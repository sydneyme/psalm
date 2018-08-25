<?php
namespace Psalm\Checker\Statements\Block;

use PhpParser;
use Psalm\Checker\ScopeChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Clause;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\Context;
use Psalm\IssueBuffer;
use Psalm\Scope\LoopScope;
use Psalm\Type;
use Psalm\Type\Algebra;
use Psalm\Type\Reconciler;

class LoopChecker
{
    /**
     * Checks an array of statements in a loop
     *
     * @param  array<PhpParser\Node\Stmt>   $stmts
     * @param  PhpParser\Node\Expr[]        $preConditions
     * @param  PhpParser\Node\Expr[]        $postExpressions
     * @param  Context                      loop_scope->loopContext
     * @param  Context                      $loopScope->loopParentContext
     * @param  bool                         $isDo
     *
     * @return false|null
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        array $stmts,
        array $preConditions,
        array $postExpressions,
        LoopScope $loopScope,
        Context &$innerContext = null,
        $isDo = false
    ) {
        $traverser = new PhpParser\NodeTraverser;

        $assignmentMapper = new \Psalm\Visitor\AssignmentMapVisitor($loopScope->loopContext->self);
        $traverser->addVisitor($assignmentMapper);

        $traverser->traverse(array_merge($stmts, $postExpressions));

        $assignmentMap = $assignmentMapper->getAssignmentMap();

        $assignmentDepth = 0;

        $assertedVarIds = [];

        $preConditionClauses = [];

        $originalProtectedVarIds = $loopScope->loopParentContext->protectedVarIds;

        if ($preConditions) {
            foreach ($preConditions as $preCondition) {
                $preConditionClauses = array_merge(
                    $preConditionClauses,
                    Algebra::getFormula(
                        $preCondition,
                        $loopScope->loopContext->self,
                        $statementsChecker
                    )
                );
            }
        } else {
            $assertedVarIds = Context::getNewOrUpdatedVarIds(
                $loopScope->loopParentContext,
                $loopScope->loopContext
            );
        }

        $finalActions = ScopeChecker::getFinalControlActions($stmts, Config::getInstance()->exitFunctions);
        $hasBreakStatement = $finalActions === [ScopeChecker::ACTION_BREAK];

        if ($assignmentMap) {
            $firstVarId = array_keys($assignmentMap)[0];

            $assignmentDepth = self::getAssignmentMapDepth($firstVarId, $assignmentMap);
        }

        $loopScope->loopContext->parentContext = $loopScope->loopParentContext;

        if ($assignmentDepth === 0 || $hasBreakStatement) {
            $innerContext = clone $loopScope->loopContext;
            $innerContext->loopScope = $loopScope;

            $innerContext->parentContext = $loopScope->loopContext;
            $oldReferencedVarIds = $innerContext->referencedVarIds;
            $innerContext->referencedVarIds = [];

            if (!$isDo) {
                foreach ($preConditions as $preCondition) {
                    self::applyPreConditionToLoopContext(
                        $statementsChecker,
                        $preCondition,
                        $preConditionClauses,
                        $innerContext,
                        $loopScope->loopParentContext
                    );
                }
            }

            $innerContext->protectedVarIds = $loopScope->protectedVarIds;

            $statementsChecker->analyze($stmts, $innerContext);
            self::updateLoopScopeContexts($loopScope, $loopScope->loopParentContext);

            foreach ($postExpressions as $postExpression) {
                if (ExpressionChecker::analyze(
                    $statementsChecker,
                    $postExpression,
                    $loopScope->loopContext
                ) === false
                ) {
                    return false;
                }
            }

            $newReferencedVarIds = $innerContext->referencedVarIds;
            $innerContext->referencedVarIds = $oldReferencedVarIds + $innerContext->referencedVarIds;

            $loopScope->loopParentContext->varsPossiblyInScope = array_merge(
                $innerContext->varsPossiblyInScope,
                $loopScope->loopParentContext->varsPossiblyInScope
            );
        } else {
            $preOuterContext = clone $loopScope->loopParentContext;

            $analyzer = $statementsChecker->getFileChecker()->projectChecker->codebase->analyzer;

            $originalMixedCounts = $analyzer->getMixedCountsForFile($statementsChecker->getFilePath());

            IssueBuffer::startRecording();

            if (!$isDo) {
                foreach ($preConditions as $preCondition) {
                    $assertedVarIds = array_merge(
                        self::applyPreConditionToLoopContext(
                            $statementsChecker,
                            $preCondition,
                            $preConditionClauses,
                            $loopScope->loopContext,
                            $loopScope->loopParentContext
                        ),
                        $assertedVarIds
                    );
                }
            }

            // record all the vars that existed before we did the first pass through the loop
            $preLoopContext = clone $loopScope->loopContext;

            $innerContext = clone $loopScope->loopContext;
            $innerContext->parentContext = $loopScope->loopContext;
            $innerContext->loopScope = $loopScope;

            $oldReferencedVarIds = $innerContext->referencedVarIds;
            $innerContext->referencedVarIds = [];

            $assertedVarIds = array_unique($assertedVarIds);

            $innerContext->protectedVarIds = $loopScope->protectedVarIds;

            $statementsChecker->analyze($stmts, $innerContext);

            self::updateLoopScopeContexts($loopScope, $preOuterContext);

            $innerContext->protectedVarIds = $originalProtectedVarIds;

            foreach ($postExpressions as $postExpression) {
                if (ExpressionChecker::analyze($statementsChecker, $postExpression, $innerContext) === false) {
                    return false;
                }
            }

            /**
             * @var array<string, bool>
             */
            $newReferencedVarIds = $innerContext->referencedVarIds;
            $innerContext->referencedVarIds = $oldReferencedVarIds + $innerContext->referencedVarIds;

            $recordedIssues = IssueBuffer::clearRecordingLevel();
            IssueBuffer::stopRecording();

            for ($i = 0; $i < $assignmentDepth; ++$i) {
                $varsToRemove = [];

                $hasChanges = false;

                // reset the $innerContext to what it was before we started the analysis,
                // but union the types with what's in the loop scope

                foreach ($innerContext->varsInScope as $varId => $type) {
                    if (in_array($varId, $assertedVarIds, true)) {
                        // set the vars to whatever the while/foreach loop expects them to be
                        if (!isset($preLoopContext->varsInScope[$varId])
                            || !$type->equals($preLoopContext->varsInScope[$varId])
                        ) {
                            $hasChanges = true;
                        }
                    } elseif (isset($preOuterContext->varsInScope[$varId])) {
                        if (!$type->equals($preOuterContext->varsInScope[$varId])) {
                            $hasChanges = true;

                            // widen the foreach context type with the initial context type
                            $innerContext->varsInScope[$varId] = Type::combineUnionTypes(
                                $innerContext->varsInScope[$varId],
                                $preOuterContext->varsInScope[$varId]
                            );

                            // if there's a change, invalidate related clauses
                            $preLoopContext->removeVarFromConflictingClauses($varId);
                        }

                        if (isset($loopScope->loopContext->varsInScope[$varId])
                            && !$type->equals($loopScope->loopContext->varsInScope[$varId])
                        ) {
                            $hasChanges = true;

                            // widen the foreach context type with the initial context type
                            $innerContext->varsInScope[$varId] = Type::combineUnionTypes(
                                $innerContext->varsInScope[$varId],
                                $loopScope->loopContext->varsInScope[$varId]
                            );

                            // if there's a change, invalidate related clauses
                            $preLoopContext->removeVarFromConflictingClauses($varId);
                        }
                    } else {
                        // give an opportunity to redeemed UndefinedVariable issues
                        if ($recordedIssues) {
                            $hasChanges = true;
                        }
                        $varsToRemove[] = $varId;
                    }
                }

                $loopScope->loopParentContext->varsPossiblyInScope = array_merge(
                    $innerContext->varsPossiblyInScope,
                    $loopScope->loopParentContext->varsPossiblyInScope
                );

                // if there are no changes to the types, no need to re-examine
                if (!$hasChanges) {
                    break;
                }

                if ($innerContext->collectReferences) {
                    foreach ($loopScope->possiblyUnreferencedVars as $varId => $locations) {
                        if (isset($innerContext->unreferencedVars[$varId])) {
                            $innerContext->unreferencedVars[$varId] += $locations;
                        } else {
                            $innerContext->unreferencedVars[$varId] = $locations;
                        }
                    }
                }

                // remove vars that were defined in the foreach
                foreach ($varsToRemove as $varId) {
                    unset($innerContext->varsInScope[$varId]);
                }

                $analyzer->setMixedCountsForFile($statementsChecker->getFilePath(), $originalMixedCounts);
                IssueBuffer::startRecording();

                foreach ($preConditions as $preCondition) {
                    self::applyPreConditionToLoopContext(
                        $statementsChecker,
                        $preCondition,
                        $preConditionClauses,
                        $innerContext,
                        $loopScope->loopParentContext
                    );
                }

                foreach ($assertedVarIds as $varId) {
                    if (!isset($innerContext->varsInScope[$varId])
                        || $innerContext->varsInScope[$varId]->getId()
                            !== $preLoopContext->varsInScope[$varId]->getId()
                        || $innerContext->varsInScope[$varId]->fromDocblock
                            !== $preLoopContext->varsInScope[$varId]->fromDocblock
                    ) {
                        $innerContext->varsInScope[$varId] = clone $preLoopContext->varsInScope[$varId];
                    }
                }

                $innerContext->clauses = $preLoopContext->clauses;

                $innerContext->protectedVarIds = $loopScope->protectedVarIds;

                $traverser = new PhpParser\NodeTraverser;

                $traverser->addVisitor(new \Psalm\Visitor\NodeCleanerVisitor());
                $traverser->traverse($stmts);

                $statementsChecker->analyze($stmts, $innerContext);

                self::updateLoopScopeContexts($loopScope, $preOuterContext);

                $innerContext->protectedVarIds = $originalProtectedVarIds;

                foreach ($postExpressions as $postExpression) {
                    if (ExpressionChecker::analyze($statementsChecker, $postExpression, $innerContext) === false) {
                        return false;
                    }
                }

                $recordedIssues = IssueBuffer::clearRecordingLevel();

                IssueBuffer::stopRecording();
            }

            if ($recordedIssues) {
                foreach ($recordedIssues as $recordedIssue) {
                    // if we're not in any loops then this will just result in the issue being emitted
                    IssueBuffer::bubbleUp($recordedIssue);
                }
            }
        }

        $doesSometimesBreak = in_array(ScopeChecker::ACTION_BREAK, $loopScope->finalActions, true);
        $doesAlwaysBreak = $loopScope->finalActions === [ScopeChecker::ACTION_BREAK];

        if ($doesSometimesBreak) {
            if ($loopScope->possiblyRedefinedLoopParentVars !== null) {
                foreach ($loopScope->possiblyRedefinedLoopParentVars as $var => $type) {
                    $loopScope->loopParentContext->varsInScope[$var] = Type::combineUnionTypes(
                        $type,
                        $loopScope->loopParentContext->varsInScope[$var]
                    );
                }
            }
        }

        foreach ($loopScope->loopParentContext->varsInScope as $varId => $type) {
            if ($type->isMixed() || !isset($loopScope->loopContext->varsInScope[$varId])) {
                continue;
            }

            if ($loopScope->loopContext->varsInScope[$varId]->getId() !== $type->getId()) {
                $loopScope->loopParentContext->varsInScope[$varId] = Type::combineUnionTypes(
                    $loopScope->loopParentContext->varsInScope[$varId],
                    $loopScope->loopContext->varsInScope[$varId]
                );

                $loopScope->loopParentContext->removeVarFromConflictingClauses($varId);
            }
        }

        if (!$doesAlwaysBreak) {
            foreach ($loopScope->loopParentContext->varsInScope as $varId => $type) {
                if ($type->isMixed()) {
                    continue;
                }

                if (!isset($innerContext->varsInScope[$varId])) {
                    unset($loopScope->loopParentContext->varsInScope[$varId]);
                    continue;
                }

                if ($innerContext->varsInScope[$varId]->isMixed()) {
                    $loopScope->loopParentContext->varsInScope[$varId] =
                        $innerContext->varsInScope[$varId];
                    $loopScope->loopParentContext->removeVarFromConflictingClauses($varId);
                    continue;
                }

                if ($innerContext->varsInScope[$varId]->getId() !== $type->getId()) {
                    $loopScope->loopParentContext->varsInScope[$varId] = Type::combineUnionTypes(
                        $loopScope->loopParentContext->varsInScope[$varId],
                        $innerContext->varsInScope[$varId]
                    );

                    $loopScope->loopParentContext->removeVarFromConflictingClauses($varId);
                }
            }
        }

        if ($preConditions && $preConditionClauses && !ScopeChecker::doesEverBreak($stmts)) {
            // if the loop contains an assertion and there are no break statements, we can negate that assertion
            // and apply it to the current context
            $negatedPreConditionTypes = Algebra::getTruthsFromFormula(
                Algebra::negateFormula($preConditionClauses)
            );

            if ($negatedPreConditionTypes) {
                $changedVarIds = [];

                $varsInScopeReconciled = Reconciler::reconcileKeyedTypes(
                    $negatedPreConditionTypes,
                    $innerContext->varsInScope,
                    $changedVarIds,
                    [],
                    $statementsChecker,
                    new CodeLocation($statementsChecker->getSource(), $preConditions[0]),
                    $statementsChecker->getSuppressedIssues()
                );

                foreach ($changedVarIds as $varId) {
                    if (isset($varsInScopeReconciled[$varId])
                        && isset($loopScope->loopParentContext->varsInScope[$varId])
                    ) {
                        $loopScope->loopParentContext->varsInScope[$varId] = $varsInScopeReconciled[$varId];
                    }

                    $loopScope->loopParentContext->removeVarFromConflictingClauses($varId);
                }
            }
        }

        $loopScope->loopContext->referencedVarIds = array_merge(
            $innerContext->referencedVarIds,
            $loopScope->loopContext->referencedVarIds
        );

        if ($innerContext->collectReferences) {
            foreach ($loopScope->possiblyUnreferencedVars as $varId => $locations) {
                if (isset($innerContext->unreferencedVars[$varId])) {
                    $innerContext->unreferencedVars[$varId] += $locations;
                } else {
                    $innerContext->unreferencedVars[$varId] = $locations;
                }
            }

            foreach ($innerContext->unreferencedVars as $varId => $locations) {
                if (!isset($newReferencedVarIds[$varId]) || $hasBreakStatement) {
                    if (!isset($loopScope->loopContext->unreferencedVars[$varId])) {
                        $loopScope->loopContext->unreferencedVars[$varId] = $locations;
                    } else {
                        $loopScope->loopContext->unreferencedVars[$varId] += $locations;
                    }
                } else {
                    $statementsChecker->registerVariableUses($locations);
                }
            }

            foreach ($loopScope->unreferencedVars as $varId => $locations) {
                if (!isset($loopScope->loopContext->unreferencedVars[$varId])) {
                    $loopScope->loopContext->unreferencedVars[$varId] = $locations;
                } else {
                    $loopScope->loopContext->unreferencedVars[$varId] += $locations;
                }
            }
        }
    }

    /**
     * @param  LoopScope $loopScope
     * @param  Context   $preOuterContext
     *
     * @return void
     */
    private static function updateLoopScopeContexts(
        LoopScope $loopScope,
        Context $preOuterContext
    ) {
        $updatedLoopVars = [];

        if (!in_array(ScopeChecker::ACTION_CONTINUE, $loopScope->finalActions, true)) {
            $loopScope->loopContext->varsInScope = $preOuterContext->varsInScope;
        } else {
            if ($loopScope->redefinedLoopVars !== null) {
                foreach ($loopScope->redefinedLoopVars as $var => $type) {
                    $loopScope->loopContext->varsInScope[$var] = $type;
                    $updatedLoopVars[$var] = true;
                }
            }

            if ($loopScope->possiblyRedefinedLoopVars) {
                foreach ($loopScope->possiblyRedefinedLoopVars as $var => $type) {
                    if ($loopScope->loopContext->hasVariable($var)
                        && !isset($updatedLoopVars[$var])
                    ) {
                        $loopScope->loopContext->varsInScope[$var] = Type::combineUnionTypes(
                            $loopScope->loopContext->varsInScope[$var],
                            $type
                        );
                    }
                }
            }
        }

        // merge vars possibly in scope at the end of each loop
        $loopScope->loopContext->varsPossiblyInScope = array_merge(
            $loopScope->loopContext->varsPossiblyInScope,
            $loopScope->varsPossiblyInScope
        );
    }

    /**
     * @param  PhpParser\Node\Expr $preCondition
     * @param  array<int, Clause>  $preConditionClauses
     * @param  Context             $loopContext
     * @param  Context             $outerContext
     *
     * @return string[]
     */
    private static function applyPreConditionToLoopContext(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr $preCondition,
        array $preConditionClauses,
        Context $loopContext,
        Context $outerContext
    ) {
        $preReferencedVarIds = $loopContext->referencedVarIds;
        $loopContext->referencedVarIds = [];

        $loopContext->insideConditional = true;

        if (ExpressionChecker::analyze($statementsChecker, $preCondition, $loopContext) === false) {
            return [];
        }

        $loopContext->insideConditional = false;

        $newReferencedVarIds = $loopContext->referencedVarIds;
        $loopContext->referencedVarIds = array_merge($preReferencedVarIds, $newReferencedVarIds);

        $assertedVarIds = Context::getNewOrUpdatedVarIds($outerContext, $loopContext);

        $loopContext->clauses = Algebra::simplifyCNF(
            array_merge($outerContext->clauses, $preConditionClauses)
        );

        $reconcilableWhileTypes = Algebra::getTruthsFromFormula(
            $loopContext->clauses,
            $newReferencedVarIds
        );

        $changedVarIds = [];

        $preConditionVarsInScopeReconciled = Reconciler::reconcileKeyedTypes(
            $reconcilableWhileTypes,
            $loopContext->varsInScope,
            $changedVarIds,
            $newReferencedVarIds,
            $statementsChecker,
            new CodeLocation($statementsChecker->getSource(), $preCondition),
            $statementsChecker->getSuppressedIssues()
        );

        $loopContext->varsInScope = $preConditionVarsInScopeReconciled;

        foreach ($assertedVarIds as $varId) {
            $loopContext->clauses = Context::filterClauses(
                $varId,
                $loopContext->clauses,
                null,
                $statementsChecker
            );
        }

        return $assertedVarIds;
    }

    /**
     * @param  string                               $firstVarId
     * @param  array<string, array<string, bool>>   $assignmentMap
     *
     * @return int
     */
    private static function getAssignmentMapDepth($firstVarId, array $assignmentMap)
    {
        $maxDepth = 0;

        $assignmentVarIds = $assignmentMap[$firstVarId];
        unset($assignmentMap[$firstVarId]);

        foreach ($assignmentVarIds as $assignmentVarId => $_) {
            $depth = 1;

            if (isset($assignmentMap[$assignmentVarId])) {
                $depth = 1 + self::getAssignmentMapDepth($assignmentVarId, $assignmentMap);
            }

            if ($depth > $maxDepth) {
                $maxDepth = $depth;
            }
        }

        return $maxDepth;
    }
}
