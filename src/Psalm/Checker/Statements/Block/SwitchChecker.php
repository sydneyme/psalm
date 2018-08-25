<?php
namespace Psalm\Checker\Statements\Block;

use PhpParser;
use Psalm\Checker\AlgebraChecker;
use Psalm\Checker\ScopeChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Issue\ContinueOutsideLoop;
use Psalm\Issue\ParadoxicalCondition;
use Psalm\IssueBuffer;
use Psalm\Scope\SwitchScope;
use Psalm\Type;
use Psalm\Type\Algebra;
use Psalm\Type\Reconciler;

class SwitchChecker
{
    /**
     * @param   StatementsChecker               $statementsChecker
     * @param   PhpParser\Node\Stmt\Switch_     $stmt
     * @param   Context                         $context
     *
     * @return  false|null
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Stmt\Switch_ $stmt,
        Context $context
    ) {
        if (ExpressionChecker::analyze($statementsChecker, $stmt->cond, $context) === false) {
            return false;
        }

        $switchVarId = ExpressionChecker::getArrayVarId(
            $stmt->cond,
            null,
            $statementsChecker
        );

        $originalContext = clone $context;

        $newVarsInScope = null;

        $newVarsPossiblyInScope = [];

        $redefinedVars = null;
        $possiblyRedefinedVars = null;

        // the last statement always breaks, by default
        $lastCaseExitType = 'break';

        $caseExitTypes = new \SplFixedArray(count($stmt->cases));

        $hasDefault = false;

        $caseActionMap = [];

        $config = \Psalm\Config::getInstance();

        // create a map of case statement -> ultimate exit type
        for ($i = count($stmt->cases) - 1; $i >= 0; --$i) {
            $case = $stmt->cases[$i];

            $caseActions = $caseActionMap[$i] = ScopeChecker::getFinalControlActions(
                $case->stmts,
                $config->exitFunctions,
                true
            );

            if (!in_array(ScopeChecker::ACTION_NONE, $caseActions, true)) {
                if ($caseActions === [ScopeChecker::ACTION_END]) {
                    $lastCaseExitType = 'return_throw';
                } elseif ($caseActions === [ScopeChecker::ACTION_CONTINUE]) {
                    $lastCaseExitType = 'continue';
                } elseif (in_array(ScopeChecker::ACTION_LEAVE_SWITCH, $caseActions, true)) {
                    $lastCaseExitType = 'break';
                }
            }

            $caseExitTypes[$i] = $lastCaseExitType;
        }

        $leftoverStatements = [];
        $leftoverCaseEqualityExpr = null;
        $negatedClauses = [];

        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;

        $newUnreferencedVars = [];
        $newAssignedVarIds = null;
        $newPossiblyAssignedVarIds = [];

        for ($i = 0, $l = count($stmt->cases); $i < $l; $i++) {
            $case = $stmt->cases[$i];

            /** @var string */
            $caseExitType = $caseExitTypes[$i];

            $caseActions = $caseActionMap[$i];

            // has a return/throw at end
            $hasEndingStatements = $caseActions === [ScopeChecker::ACTION_END];
            $hasLeavingStatements = $hasEndingStatements
                || (count($caseActions) && !in_array(ScopeChecker::ACTION_NONE, $caseActions, true));

            $caseContext = clone $originalContext;
            if ($projectChecker->alterCode) {
                $caseContext->branchPoint = $caseContext->branchPoint ?: (int) $stmt->getAttribute('startFilePos');
            }
            $caseContext->parentContext = $context;
            $caseContext->switchScope = new SwitchScope();

            $caseEqualityExpr = null;

            if ($case->cond) {
                if (ExpressionChecker::analyze($statementsChecker, $case->cond, $caseContext) === false) {
                    return false;
                }

                $switchCondition = clone $stmt->cond;

                if ($switchCondition instanceof PhpParser\Node\Expr\Variable
                    && is_string($switchCondition->name)
                    && isset($context->varsInScope['$' . $switchCondition->name])
                ) {
                    $switchVarType = $context->varsInScope['$' . $switchCondition->name];

                    $typeStatements = [];

                    foreach ($switchVarType->getTypes() as $type) {
                        if ($type instanceof Type\Atomic\GetClassT) {
                            $typeStatements[] = new PhpParser\Node\Expr\FuncCall(
                                new PhpParser\Node\Name(['get_class']),
                                [
                                    new PhpParser\Node\Arg(
                                        new PhpParser\Node\Expr\Variable(substr($type->typeof, 1))
                                    ),
                                ]
                            );
                        } elseif ($type instanceof Type\Atomic\GetTypeT) {
                            $typeStatements[] = new PhpParser\Node\Expr\FuncCall(
                                new PhpParser\Node\Name(['gettype']),
                                [
                                    new PhpParser\Node\Arg(
                                        new PhpParser\Node\Expr\Variable(substr($type->typeof, 1))
                                    ),
                                ]
                            );
                        } else {
                            $typeStatements = null;
                            break;
                        }
                    }

                    if ($typeStatements && count($typeStatements) === 1) {
                        $switchCondition = $typeStatements[0];
                    }
                }

                if (isset($switchCondition->inferredType)
                    && isset($case->cond->inferredType)
                    && (($switchCondition->inferredType->isString() && $case->cond->inferredType->isString())
                        || ($switchCondition->inferredType->isInt() && $case->cond->inferredType->isInt())
                        || ($switchCondition->inferredType->isFloat() && $case->cond->inferredType->isFloat())
                    )
                ) {
                    $caseEqualityExpr = new PhpParser\Node\Expr\BinaryOp\Identical(
                        $switchCondition,
                        $case->cond,
                        $case->cond->getAttributes()
                    );
                } else {
                    $caseEqualityExpr = new PhpParser\Node\Expr\BinaryOp\Equal(
                        $switchCondition,
                        $case->cond,
                        $case->cond->getAttributes()
                    );
                }
            }

            $caseStmts = $case->stmts;

            $caseStmts = array_merge($leftoverStatements, $caseStmts);

            if (!$case->cond) {
                $hasDefault = true;
            }

            if (!$hasLeavingStatements && $i !== $l - 1) {
                if (!$caseEqualityExpr) {
                    $caseEqualityExpr = new PhpParser\Node\Expr\FuncCall(
                        new PhpParser\Node\Name\FullyQualified(['rand']),
                        [
                            new PhpParser\Node\Arg(new PhpParser\Node\Scalar\LNumber(0)),
                            new PhpParser\Node\Arg(new PhpParser\Node\Scalar\LNumber(1)),
                        ],
                        $case->getAttributes()
                    );
                }

                $leftoverCaseEqualityExpr = $leftoverCaseEqualityExpr
                    ? new PhpParser\Node\Expr\BinaryOp\BooleanOr(
                        $leftoverCaseEqualityExpr,
                        $caseEqualityExpr,
                        $case->cond ? $case->cond->getAttributes() : $case->getAttributes()
                    )
                    : $caseEqualityExpr;

                $caseIfStmt = new PhpParser\Node\Stmt\If_(
                    $leftoverCaseEqualityExpr,
                    ['stmts' => $caseStmts]
                );

                $leftoverStatements = [$caseIfStmt];

                continue;
            }

            if ($leftoverCaseEqualityExpr) {
                $caseOrDefaultEqualityExpr = $caseEqualityExpr;

                if (!$caseOrDefaultEqualityExpr) {
                    $caseOrDefaultEqualityExpr = new PhpParser\Node\Expr\FuncCall(
                        new PhpParser\Node\Name\FullyQualified(['rand']),
                        [
                            new PhpParser\Node\Arg(new PhpParser\Node\Scalar\LNumber(0)),
                            new PhpParser\Node\Arg(new PhpParser\Node\Scalar\LNumber(1)),
                        ],
                        $case->getAttributes()
                    );
                }

                $caseEqualityExpr = new PhpParser\Node\Expr\BinaryOp\BooleanOr(
                    $leftoverCaseEqualityExpr,
                    $caseOrDefaultEqualityExpr,
                    $caseOrDefaultEqualityExpr->getAttributes()
                );
            }

            $caseContext->insideCase = true;

            $leftoverStatements = [];
            $leftoverCaseEqualityExpr = null;

            $caseClauses = [];

            if ($caseEqualityExpr) {
                $caseClauses = Algebra::getFormula(
                    $caseEqualityExpr,
                    $context->self,
                    $statementsChecker
                );
            }

            if ($negatedClauses) {
                $entryClauses = Algebra::simplifyCNF(array_merge($originalContext->clauses, $negatedClauses));
            } else {
                $entryClauses = $originalContext->clauses;
            }

            if ($caseClauses && $case->cond) {
                // this will see whether any of the clauses in set A conflict with the clauses in set B
                AlgebraChecker::checkForParadox(
                    $entryClauses,
                    $caseClauses,
                    $statementsChecker,
                    $case->cond,
                    []
                );

                $caseContext->clauses = Algebra::simplifyCNF(array_merge($entryClauses, $caseClauses));
            } else {
                $caseContext->clauses = $entryClauses;
            }

            $reconcilableIfTypes = Algebra::getTruthsFromFormula($caseContext->clauses);

            // if the if has an || in the conditional, we cannot easily reason about it
            if ($reconcilableIfTypes) {
                $changedVarIds = [];

                $suppressedIssues = $statementsChecker->getSuppressedIssues();

                if (!in_array('RedundantCondition', $suppressedIssues, true)) {
                    $statementsChecker->addSuppressedIssues(['RedundantCondition']);
                }

                if (!in_array('RedundantConditionGivenDocblockType', $suppressedIssues, true)) {
                    $statementsChecker->addSuppressedIssues(['RedundantConditionGivenDocblockType']);
                }

                $caseVarsInScopeReconciled =
                    Reconciler::reconcileKeyedTypes(
                        $reconcilableIfTypes,
                        $caseContext->varsInScope,
                        $changedVarIds,
                        $case->cond && $switchVarId ? [$switchVarId => true] : [],
                        $statementsChecker,
                        new CodeLocation(
                            $statementsChecker->getSource(),
                            $case->cond ? $case->cond : $case,
                            $context->includeLocation
                        ),
                        $statementsChecker->getSuppressedIssues()
                    );

                if (!in_array('RedundantCondition', $suppressedIssues, true)) {
                    $statementsChecker->removeSuppressedIssues(['RedundantCondition']);
                }

                if (!in_array('RedundantConditionGivenDocblockType', $suppressedIssues, true)) {
                    $statementsChecker->removeSuppressedIssues(['RedundantConditionGivenDocblockType']);
                }

                $caseContext->varsInScope = $caseVarsInScopeReconciled;
                foreach ($reconcilableIfTypes as $varId => $_) {
                    $caseContext->varsPossiblyInScope[$varId] = true;
                }

                if ($changedVarIds) {
                    $caseContext->removeReconciledClauses($changedVarIds);
                }
            }

            if ($caseClauses) {
                $negatedClauses = array_merge(
                    $negatedClauses,
                    Algebra::negateFormula($caseClauses)
                );
            }

            $prePossiblyAssignedVarIds = $caseContext->possiblyAssignedVarIds;
            $caseContext->possiblyAssignedVarIds = [];

            $preAssignedVarIds = $caseContext->assignedVarIds;
            $caseContext->assignedVarIds = [];

            $statementsChecker->analyze($caseStmts, $caseContext);

            /** @var array<string, bool> */
            $newCaseAssignedVarIds = $caseContext->assignedVarIds;
            $caseContext->assignedVarIds = $preAssignedVarIds + $newCaseAssignedVarIds;

            /** @var array<string, bool> */
            $newCasePossiblyAssignedVarIds = $caseContext->possiblyAssignedVarIds;
            $caseContext->possiblyAssignedVarIds =
                $prePossiblyAssignedVarIds + $newCasePossiblyAssignedVarIds;

            $context->referencedVarIds = array_merge(
                $context->referencedVarIds,
                $caseContext->referencedVarIds
            );

            if ($caseExitType !== 'return_throw') {
                if (!$case->cond
                    && $switchVarId
                    && isset($caseContext->varsInScope[$switchVarId])
                    && $caseContext->varsInScope[$switchVarId]->isEmpty()
                ) {
                    if (IssueBuffer::accepts(
                        new ParadoxicalCondition(
                            'All possible case statements have been met, default is impossible here',
                            new CodeLocation($statementsChecker->getSource(), $case)
                        )
                    )) {
                        return false;
                    }
                }

                $vars = array_diff_key(
                    $caseContext->varsPossiblyInScope,
                    $originalContext->varsPossiblyInScope
                );

                // if we're leaving this block, add vars to outer for loop scope
                if ($caseExitType === 'continue') {
                    if ($context->loopScope) {
                        $context->loopScope->varsPossiblyInScope = array_merge(
                            $vars,
                            $context->loopScope->varsPossiblyInScope
                        );
                    } else {
                        if (IssueBuffer::accepts(
                            new ContinueOutsideLoop(
                                'Continue called when not in loop',
                                new CodeLocation($statementsChecker->getSource(), $case)
                            )
                        )) {
                            return false;
                        }
                    }
                } else {
                    $caseRedefinedVars = $caseContext->getRedefinedVars($originalContext->varsInScope);

                    if ($possiblyRedefinedVars === null) {
                        $possiblyRedefinedVars = $caseRedefinedVars;
                    } else {
                        foreach ($caseRedefinedVars as $varId => $type) {
                            if (!isset($possiblyRedefinedVars[$varId])) {
                                $possiblyRedefinedVars[$varId] = $type;
                            } else {
                                $possiblyRedefinedVars[$varId] = Type::combineUnionTypes(
                                    $type,
                                    $possiblyRedefinedVars[$varId]
                                );
                            }
                        }
                    }

                    if ($redefinedVars === null) {
                        $redefinedVars = $caseRedefinedVars;
                    } else {
                        foreach ($redefinedVars as $varId => $type) {
                            if (!isset($caseRedefinedVars[$varId])) {
                                unset($redefinedVars[$varId]);
                            } else {
                                $redefinedVars[$varId] = Type::combineUnionTypes(
                                    $type,
                                    $caseRedefinedVars[$varId]
                                );
                            }
                        }
                    }

                    $contextNewVars = array_diff_key($caseContext->varsInScope, $context->varsInScope);

                    if ($newVarsInScope === null) {
                        $newVarsInScope = $contextNewVars;
                        $newVarsPossiblyInScope = array_diff_key(
                            $caseContext->varsPossiblyInScope,
                            $context->varsPossiblyInScope
                        );
                    } else {
                        foreach ($newVarsInScope as $newVar => $type) {
                            if (!$caseContext->hasVariable($newVar, $statementsChecker)) {
                                unset($newVarsInScope[$newVar]);
                            } else {
                                $newVarsInScope[$newVar] =
                                    Type::combineUnionTypes($caseContext->varsInScope[$newVar], $type);
                            }
                        }

                        $newVarsPossiblyInScope = array_merge(
                            array_diff_key(
                                $caseContext->varsPossiblyInScope,
                                $context->varsPossiblyInScope
                            ),
                            $newVarsPossiblyInScope
                        );
                    }
                }

                if ($context->collectExceptions) {
                    $context->possiblyThrownExceptions += $caseContext->possiblyThrownExceptions;
                }

                if ($context->collectReferences) {
                    $newPossiblyAssignedVarIds =
                        $newPossiblyAssignedVarIds + $newCasePossiblyAssignedVarIds;

                    if ($newAssignedVarIds === null) {
                        $newAssignedVarIds = $newCaseAssignedVarIds;
                    } else {
                        $newAssignedVarIds = array_intersect_key($newAssignedVarIds, $newCaseAssignedVarIds);
                    }

                    foreach ($caseContext->unreferencedVars as $varId => $locations) {
                        if (!isset($originalContext->unreferencedVars[$varId])) {
                            if (isset($newUnreferencedVars[$varId])) {
                                $newUnreferencedVars[$varId] += $locations;
                            } else {
                                $newUnreferencedVars[$varId] = $locations;
                            }
                        } else {
                            $newLocations = array_diff_key(
                                $locations,
                                $originalContext->unreferencedVars[$varId]
                            );

                            if ($newLocations) {
                                if (isset($newUnreferencedVars[$varId])) {
                                    $newUnreferencedVars[$varId] += $locations;
                                } else {
                                    $newUnreferencedVars[$varId] = $locations;
                                }
                            }
                        }
                    }

                    foreach ($caseContext->switchScope->unreferencedVars as $varId => $locations) {
                        if (!isset($originalContext->unreferencedVars[$varId])) {
                            if (isset($newUnreferencedVars[$varId])) {
                                $newUnreferencedVars[$varId] += $locations;
                            } else {
                                $newUnreferencedVars[$varId] = $locations;
                            }
                        } else {
                            $newLocations = array_diff_key(
                                $locations,
                                $originalContext->unreferencedVars[$varId]
                            );

                            if ($newLocations) {
                                if (isset($newUnreferencedVars[$varId])) {
                                    $newUnreferencedVars[$varId] += $locations;
                                } else {
                                    $newUnreferencedVars[$varId] = $locations;
                                }
                            }
                        }
                    }
                }
            }
        }

        $allOptionsMatched = $hasDefault;

        if (!$hasDefault && $negatedClauses && $switchVarId) {
            $entryClauses = Algebra::simplifyCNF(array_merge($originalContext->clauses, $negatedClauses));

            $reconcilableIfTypes = Algebra::getTruthsFromFormula($entryClauses);

            // if the if has an || in the conditional, we cannot easily reason about it
            if ($reconcilableIfTypes && isset($reconcilableIfTypes[$switchVarId])) {
                $changedVarIds = [];

                $caseVarsInScopeReconciled =
                    Reconciler::reconcileKeyedTypes(
                        $reconcilableIfTypes,
                        $originalContext->varsInScope,
                        $changedVarIds,
                        [],
                        $statementsChecker
                    );

                if (isset($caseVarsInScopeReconciled[$switchVarId])
                    && $caseVarsInScopeReconciled[$switchVarId]->isEmpty()
                ) {
                    $allOptionsMatched = true;
                }
            }
        }

        // only update vars if there is a default or all possible cases accounted for
        // if the default has a throw/return/continue, that should be handled above
        if ($allOptionsMatched) {
            if ($newVarsInScope) {
                $context->varsInScope = array_merge($context->varsInScope, $newVarsInScope);
            }

            if ($redefinedVars) {
                $context->varsInScope = array_merge($context->varsInScope, $redefinedVars);
            }

            if ($possiblyRedefinedVars) {
                foreach ($possiblyRedefinedVars as $varId => $type) {
                    if (!isset($redefinedVars[$varId]) && !isset($newVarsInScope[$varId])) {
                        $context->varsInScope[$varId]
                            = Type::combineUnionTypes($type, $context->varsInScope[$varId]);
                    }
                }
            }

            /** @psalm-suppress UndefinedPropertyAssignment */
            $stmt->allMatched = true;
        } elseif ($possiblyRedefinedVars) {
            foreach ($possiblyRedefinedVars as $varId => $type) {
                $context->varsInScope[$varId] = Type::combineUnionTypes($type, $context->varsInScope[$varId]);
            }
        }

        if ($newAssignedVarIds) {
            $context->assignedVarIds += $newAssignedVarIds;
        }

        if ($context->collectReferences) {
            foreach ($newUnreferencedVars as $varId => $locations) {
                if (($allOptionsMatched && isset($newAssignedVarIds[$varId]))
                    || !isset($context->varsInScope[$varId])
                ) {
                    $context->unreferencedVars[$varId] = $locations;
                } elseif (isset($newPossiblyAssignedVarIds[$varId])) {
                    if (!isset($context->unreferencedVars[$varId])) {
                        $context->unreferencedVars[$varId] = $locations;
                    } else {
                        $context->unreferencedVars[$varId] += $locations;
                    }
                } else {
                    $statementsChecker->registerVariableUses($locations);
                }
            }
        }

        $context->varsPossiblyInScope = array_merge($context->varsPossiblyInScope, $newVarsPossiblyInScope);

        return null;
    }
}
