<?php
namespace Psalm\Checker\Statements\Block;

use PhpParser;
use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\ScopeChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Issue\InvalidCatch;
use Psalm\IssueBuffer;
use Psalm\Scope\LoopScope;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

class TryChecker
{
    /**
     * @param   StatementsChecker               $statementsChecker
     * @param   PhpParser\Node\Stmt\TryCatch    $stmt
     * @param   Context                         $context
     *
     * @return  false|null
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Stmt\TryCatch $stmt,
        Context $context
    ) {
        $catchActions = [];
        $allCatchesLeave = true;

        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;

        /** @var int $i */
        foreach ($stmt->catches as $i => $catch) {
            $catchActions[$i] = ScopeChecker::getFinalControlActions($catch->stmts, $codebase->config->exitFunctions);
            $allCatchesLeave = $allCatchesLeave && !in_array(ScopeChecker::ACTION_NONE, $catchActions[$i], true);
        }

        $existingThrownExceptions = $context->possiblyThrownExceptions;

        /**
         * @var array<string, bool>
         */
        $context->possiblyThrownExceptions = [];

        if ($allCatchesLeave) {
            $tryContext = $context;
        } else {
            $tryContext = clone $context;

            if ($projectChecker->alterCode) {
                $tryContext->branchPoint = $tryContext->branchPoint ?: (int) $stmt->getAttribute('startFilePos');
            }
        }

        $assignedVarIds = $tryContext->assignedVarIds;
        $context->assignedVarIds = [];

        $oldUnreferencedVars = $tryContext->unreferencedVars;
        $newlyUnreferencedVars = [];
        $reassignedVars = [];

        if ($statementsChecker->analyze($stmt->stmts, $context) === false) {
            return false;
        }

        /** @var array<string, bool> */
        $newlyAssignedVarIds = $context->assignedVarIds;

        $context->assignedVarIds = array_merge(
            $assignedVarIds,
            $newlyAssignedVarIds
        );

        if ($tryContext !== $context) {
            foreach ($context->varsInScope as $varId => $type) {
                if (!isset($tryContext->varsInScope[$varId])) {
                    $tryContext->varsInScope[$varId] = clone $type;
                    $tryContext->varsInScope[$varId]->fromDocblock = true;
                    $type->possiblyUndefinedFromTry = true;
                } else {
                    $tryContext->varsInScope[$varId] = Type::combineUnionTypes(
                        $tryContext->varsInScope[$varId],
                        $type
                    );
                }
            }

            $tryContext->varsPossiblyInScope = $context->varsPossiblyInScope;

            $context->referencedVarIds = array_merge(
                $tryContext->referencedVarIds,
                $context->referencedVarIds
            );

            if ($context->collectReferences) {
                $newlyUnreferencedVars = array_merge(
                    $newlyUnreferencedVars,
                    array_diff_key(
                        $context->unreferencedVars,
                        $oldUnreferencedVars
                    )
                );

                foreach ($context->unreferencedVars as $varId => $locations) {
                    if (isset($oldUnreferencedVars[$varId])
                        && $oldUnreferencedVars[$varId] !== $locations
                    ) {
                        $reassignedVars[$varId] = $locations;
                    }
                }
            }
        }

        $tryLeavesLoop = $context->loopScope
            && $context->loopScope->finalActions
            && !in_array(ScopeChecker::ACTION_NONE, $context->loopScope->finalActions, true);

        if (!$allCatchesLeave) {
            foreach ($newlyAssignedVarIds as $assignedVarId => $_) {
                $context->removeVarFromConflictingClauses($assignedVarId);
            }
        } else {
            foreach ($newlyAssignedVarIds as $assignedVarId => $_) {
                $tryContext->removeVarFromConflictingClauses($assignedVarId);
            }
        }

        // at this point we have two contexts – $context, in which it is assumed that everything was fine,
        // and $tryContext - which allows all variables to have the union of the values before and after
        // the try was applied
        $originalContext = clone $tryContext;

        /** @var int $i */
        foreach ($stmt->catches as $i => $catch) {
            $catchContext = clone $originalContext;

            $fqCatchClasses = [];

            $catchVarName = $catch->var->name;

            if (!is_string($catchVarName)) {
                throw new \UnexpectedValueException('Catch var name must be a string');
            }

            foreach ($catch->types as $catchType) {
                $fqCatchClass = ClassLikeChecker::getFQCLNFromNameObject(
                    $catchType,
                    $statementsChecker->getAliases()
                );

                if ($originalContext->checkClasses) {
                    if (ClassLikeChecker::checkFullyQualifiedClassLikeName(
                        $statementsChecker,
                        $fqCatchClass,
                        new CodeLocation($statementsChecker->getSource(), $catchType, $context->includeLocation),
                        $statementsChecker->getSuppressedIssues(),
                        false
                    ) === false) {
                        return false;
                    }
                }

                if (($codebase->classExists($fqCatchClass)
                        && strtolower($fqCatchClass) !== 'exception'
                        && !($codebase->classExtends($fqCatchClass, 'Exception')
                            || $codebase->classImplements($fqCatchClass, 'Throwable')))
                    || ($codebase->interfaceExists($fqCatchClass)
                        && strtolower($fqCatchClass) !== 'throwable'
                        && !$codebase->interfaceExtends($fqCatchClass, 'Throwable'))
                ) {
                    if (IssueBuffer::accepts(
                        new InvalidCatch(
                            'Class/interface ' . $fqCatchClass . ' cannot be caught',
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }
                }

                $fqCatchClasses[] = $fqCatchClass;
            }

            $potentiallyCaughtClasses = array_flip($fqCatchClasses);

            if ($catchContext->collectExceptions) {
                foreach ($fqCatchClasses as $fqCatchClass) {
                    $fqCatchClassLower = strtolower($fqCatchClass);

                    foreach ($context->possiblyThrownExceptions as $exceptionFqcln => $_) {
                        $exceptionFqclnLower = strtolower($exceptionFqcln);

                        if ($exceptionFqclnLower === $fqCatchClassLower) {
                            unset($context->possiblyThrownExceptions[$exceptionFqcln]);
                            continue;
                        }

                        if ($codebase->classExists($exceptionFqcln)
                            && $codebase->classExtendsOrImplements(
                                $exceptionFqcln,
                                $fqCatchClass
                            )
                        ) {
                            unset($context->possiblyThrownExceptions[$exceptionFqcln]);
                            continue;
                        }

                        if ($codebase->interfaceExists($exceptionFqcln)
                            && $codebase->interfaceExtends(
                                $exceptionFqcln,
                                $fqCatchClass
                            )
                        ) {
                            unset($context->possiblyThrownExceptions[$exceptionFqcln]);
                            continue;
                        }
                    }
                }
            }

            $catchVarId = '$' . $catchVarName;

            $catchContext->varsInScope[$catchVarId] = new Union(
                array_map(
                    /**
                     * @param string $fqCatchClass
                     *
                     * @return Type\Atomic
                     */
                    function ($fqCatchClass) use ($codebase) {
                        $catchClassType = new TNamedObject($fqCatchClass);

                        if (version_compare(PHP_VERSION, '7.0.0dev', '>=')
                            && $codebase->interfaceExists($fqCatchClass)
                            && !$codebase->interfaceExtends($fqCatchClass, 'Throwable')
                        ) {
                            $catchClassType->addIntersectionType(new TNamedObject('Throwable'));
                        }

                        return $catchClassType;
                    },
                    $fqCatchClasses
                )
            );

            // discard all clauses because crazy stuff may have happened in try block
            $catchContext->clauses = [];

            $catchContext->varsPossiblyInScope[$catchVarId] = true;

            if (!$statementsChecker->hasVariable($catchVarId)) {
                $location = new CodeLocation(
                    $statementsChecker,
                    $catch->var,
                    $context->includeLocation
                );
                $statementsChecker->registerVariable(
                    $catchVarId,
                    $location,
                    $tryContext->branchPoint
                );
                $catchContext->unreferencedVars[$catchVarId] = [$location->getHash() => $location];
            }

            // this registers the variable to avoid unfair deadcode issues
            $catchContext->hasVariable($catchVarId, $statementsChecker);

            $suppressedIssues = $statementsChecker->getSuppressedIssues();

            if (!in_array('RedundantCondition', $suppressedIssues, true)) {
                $statementsChecker->addSuppressedIssues(['RedundantCondition']);
            }

            $statementsChecker->analyze($catch->stmts, $catchContext);

            if (!in_array('RedundantCondition', $suppressedIssues, true)) {
                $statementsChecker->removeSuppressedIssues(['RedundantCondition']);
            }

            $context->referencedVarIds = array_merge(
                $catchContext->referencedVarIds,
                $context->referencedVarIds
            );

            if ($context->collectReferences && $catchActions[$i] !== [ScopeChecker::ACTION_END]) {
                foreach ($context->unreferencedVars as $varId => $_) {
                    if (!isset($catchContext->unreferencedVars[$varId])) {
                        unset($context->unreferencedVars[$varId]);
                    }
                }

                $newlyUnreferencedVars = array_merge(
                    $newlyUnreferencedVars,
                    array_diff_key(
                        $catchContext->unreferencedVars,
                        $oldUnreferencedVars
                    )
                );

                foreach ($catchContext->unreferencedVars as $varId => $locations) {
                    if (!isset($oldUnreferencedVars[$varId])
                        && (isset($context->unreferencedVars[$varId])
                            || isset($newlyAssignedVarIds[$varId]))
                    ) {
                        $statementsChecker->registerVariableUses($locations);
                    } elseif (isset($oldUnreferencedVars[$varId])
                        && $oldUnreferencedVars[$varId] !== $locations
                    ) {
                        $statementsChecker->registerVariableUses($locations);
                    }
                }
            }

            if ($context->collectExceptions) {
                $potentiallyCaughtClasses = array_diff_key(
                    $potentiallyCaughtClasses,
                    $context->possiblyThrownExceptions
                );
            }

            if ($catchActions[$i] !== [ScopeChecker::ACTION_END]) {
                foreach ($catchContext->varsInScope as $varId => $type) {
                    if ($context->hasVariable($varId)
                        && $context->varsInScope[$varId]->getId() !== $type->getId()
                    ) {
                        $context->varsInScope[$varId] = Type::combineUnionTypes(
                            $context->varsInScope[$varId],
                            $type
                        );
                    }
                }

                $context->varsPossiblyInScope = array_merge(
                    $catchContext->varsPossiblyInScope,
                    $context->varsPossiblyInScope
                );
            }
        }

        if ($context->loopScope
            && !$tryLeavesLoop
            && !in_array(ScopeChecker::ACTION_NONE, $context->loopScope->finalActions, true)
        ) {
            $context->loopScope->finalActions[] = ScopeChecker::ACTION_NONE;
        }

        if ($stmt->finally) {
            $statementsChecker->analyze($stmt->finally->stmts, $context);
        }

        if ($context->collectReferences) {
            foreach ($oldUnreferencedVars as $varId => $locations) {
                if (isset($context->unreferencedVars[$varId])
                    && $context->unreferencedVars[$varId] !== $locations
                ) {
                    $statementsChecker->registerVariableUses($locations);
                }
            }
        }

        $context->possiblyThrownExceptions += $existingThrownExceptions;

        return null;
    }
}
