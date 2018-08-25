<?php
namespace Psalm\Checker\Statements\Block;

use PhpParser;
use Psalm\Checker\AlgebraChecker;
use Psalm\Checker\ScopeChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Checker\TypeChecker;
use Psalm\Clause;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Issue\ConflictingReferenceConstraint;
use Psalm\IssueBuffer;
use Psalm\Scope\IfScope;
use Psalm\Scope\LoopScope;
use Psalm\Type;
use Psalm\Type\Algebra;
use Psalm\Type\Reconciler;

class IfChecker
{
    /**
     * System of type substitution and deletion
     *
     * for example
     *
     * x: A|null
     *
     * if (x)
     *   (x: A)
     *   x = B  -- effects: remove A from the type of x, add B
     * else
     *   (x: null)
     *   x = C  -- effects: remove null from the type of x, add C
     *
     *
     * x: A|null
     *
     * if (!x)
     *   (x: null)
     *   throw new Exception -- effects: remove null from the type of x
     *
     * @param  StatementsChecker       $statementsChecker
     * @param  PhpParser\Node\Stmt\If_ $stmt
     * @param  Context                 $context
     *
     * @return null|false
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Stmt\If_ $stmt,
        Context $context
    ) {
        // get the first expression in the if, which should be evaluated on its own
        // this allows us to update the context of $matches in
        // if (!preg_match('/a/', 'aa', $matches)) {
        //   exit
        // }
        // echo $matches[0];
        $firstIfCondExpr = self::getDefinitelyEvaluatedExpression($stmt->cond);

        $context->insideConditional = true;

        $preConditionVarsInScope = $context->varsInScope;

        $referencedVarIds = $context->referencedVarIds;
        $context->referencedVarIds = [];

        $preAssignedVarIds = $context->assignedVarIds;
        $context->assignedVarIds = [];

        if ($firstIfCondExpr &&
            ExpressionChecker::analyze($statementsChecker, $firstIfCondExpr, $context) === false
        ) {
            return false;
        }

        $firstCondAssignedVarIds = $context->assignedVarIds;
        $context->assignedVarIds = array_merge(
            $preAssignedVarIds,
            $firstCondAssignedVarIds
        );

        $firstCondReferencedVarIds = $context->referencedVarIds;
        $context->referencedVarIds = array_merge(
            $referencedVarIds,
            $firstCondReferencedVarIds
        );

        $context->insideConditional = false;

        $ifScope = new IfScope();

        $ifContext = clone $context;

        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;

        if ($projectChecker->alterCode) {
            $ifContext->branchPoint = $ifContext->branchPoint ?: (int) $stmt->getAttribute('startFilePos');
        }

        // we need to clone the current context so our ongoing updates to $context don't mess with elseif/else blocks
        $originalContext = clone $context;

        $ifContext->insideConditional = true;

        if ($firstIfCondExpr !== $stmt->cond) {
            $assignedVarIds = $context->assignedVarIds;
            $ifContext->assignedVarIds = [];

            $referencedVarIds = $context->referencedVarIds;
            $ifContext->referencedVarIds = [];

            if (ExpressionChecker::analyze($statementsChecker, $stmt->cond, $ifContext) === false) {
                return false;
            }

            /** @var array<string, bool> */
            $moreCondReferencedVarIds = $ifContext->referencedVarIds;
            $ifContext->referencedVarIds = array_merge(
                $moreCondReferencedVarIds,
                $referencedVarIds
            );

            $condReferencedVarIds = array_merge(
                $firstCondReferencedVarIds,
                $moreCondReferencedVarIds
            );

            /** @var array<string, bool> */
            $moreCondAssignedVarIds = $ifContext->assignedVarIds;
            $ifContext->assignedVarIds = array_merge(
                $moreCondAssignedVarIds,
                $assignedVarIds
            );

            $condAssignedVarIds = array_merge(
                $firstCondAssignedVarIds,
                $moreCondAssignedVarIds
            );
        } else {
            $condReferencedVarIds = $firstCondReferencedVarIds;

            $condAssignedVarIds = $firstCondAssignedVarIds;
        }

        $newishVarIds = array_map(
            /**
             * @param Type\Union $_
             *
             * @return true
             */
            function (Type\Union $_) {
                return true;
            },
            array_diff_key(
                $ifContext->varsInScope,
                $preConditionVarsInScope,
                $condReferencedVarIds,
                $condAssignedVarIds
            )
        );

        // get all the var ids that were referened in the conditional, but not assigned in it
        $condReferencedVarIds = array_diff_key($condReferencedVarIds, $condAssignedVarIds);

        // remove all newly-asserted var ids too
        $condReferencedVarIds = array_filter(
            $condReferencedVarIds,
            /**
             * @param string $varId
             *
             * @return bool
             */
            function ($varId) use ($preConditionVarsInScope) {
                return isset($preConditionVarsInScope[$varId]);
            },
            ARRAY_FILTER_USE_KEY
        );

        $condReferencedVarIds = array_merge($newishVarIds, $condReferencedVarIds);

        $ifContext->insideConditional = false;

        $mixedVarIds = [];

        foreach ($ifContext->varsInScope as $varId => $type) {
            if ($type->isMixed()) {
                $mixedVarIds[] = $varId;
            }
        }

        foreach ($ifContext->varsPossiblyInScope as $varId => $_) {
            if (!isset($ifContext->varsInScope[$varId])) {
                $mixedVarIds[] = $varId;
            }
        }

        $ifClauses = Algebra::getFormula(
            $stmt->cond,
            $context->self,
            $statementsChecker
        );

        $ifClauses = array_values(
            array_map(
                /**
                 * @return Clause
                 */
                function (Clause $c) use ($mixedVarIds) {
                    $keys = array_keys($c->possibilities);

                    foreach ($keys as $key) {
                        foreach ($mixedVarIds as $mixedVarId) {
                            if (preg_match('/^' . preg_quote($mixedVarId, '/') . '(\[|-)/', $key)) {
                                return new Clause([], true);
                            }
                        }
                    }

                    return $c;
                },
                $ifClauses
            )
        );

        // this will see whether any of the clauses in set A conflict with the clauses in set B
        AlgebraChecker::checkForParadox(
            $context->clauses,
            $ifClauses,
            $statementsChecker,
            $stmt->cond,
            $condAssignedVarIds
        );

        // if we have assignments in the if, we may have duplicate clauses
        if ($condAssignedVarIds) {
            $ifClauses = Algebra::simplifyCNF($ifClauses);
        }

        $ifContext->clauses = Algebra::simplifyCNF(array_merge($context->clauses, $ifClauses));

        // define this before we alter local claues after reconciliation
        $ifScope->reasonableClauses = $ifContext->clauses;

        $ifScope->negatedClauses = Algebra::negateFormula($ifClauses);

        $ifScope->negatedTypes = Algebra::getTruthsFromFormula(
            Algebra::simplifyCNF(
                array_merge($context->clauses, $ifScope->negatedClauses)
            )
        );

        $reconcilableIfTypes = Algebra::getTruthsFromFormula(
            $ifContext->clauses,
            $condReferencedVarIds
        );

        // if the if has an || in the conditional, we cannot easily reason about it
        if ($reconcilableIfTypes) {
            $changedVarIds = [];

            $ifVarsInScopeReconciled =
                Reconciler::reconcileKeyedTypes(
                    $reconcilableIfTypes,
                    $ifContext->varsInScope,
                    $changedVarIds,
                    $condReferencedVarIds,
                    $statementsChecker,
                    $context->checkVariables
                        ? new CodeLocation(
                            $statementsChecker->getSource(),
                            $stmt->cond,
                            $context->includeLocation
                        ) : null,
                    $statementsChecker->getSuppressedIssues()
                );

            $ifContext->varsInScope = $ifVarsInScopeReconciled;

            foreach ($reconcilableIfTypes as $varId => $_) {
                $ifContext->varsPossiblyInScope[$varId] = true;
            }

            if ($changedVarIds) {
                $ifContext->removeReconciledClauses($changedVarIds);
            }

            $ifScope->ifCondChangedVarIds = $changedVarIds;
        }

        $oldIfContext = clone $ifContext;
        $context->varsPossiblyInScope = array_merge(
            $ifContext->varsPossiblyInScope,
            $context->varsPossiblyInScope
        );

        $context->referencedVarIds = array_merge(
            $ifContext->referencedVarIds,
            $context->referencedVarIds
        );

        $tempElseContext = clone $originalContext;

        $changedVarIds = [];

        if ($ifScope->negatedTypes) {
            $elseVarsReconciled = Reconciler::reconcileKeyedTypes(
                $ifScope->negatedTypes,
                $tempElseContext->varsInScope,
                $changedVarIds,
                $stmt->else || $stmt->elseifs ? $condReferencedVarIds : [],
                $statementsChecker,
                $context->checkVariables
                    ? new CodeLocation(
                        $statementsChecker->getSource(),
                        $stmt->cond,
                        $context->includeLocation
                    ) : null,
                $statementsChecker->getSuppressedIssues()
            );

            $tempElseContext->varsInScope = $elseVarsReconciled;
        }

        // we calculate the vars redefined in a hypothetical else statement to determine
        // which vars of the if we can safely change
        $preAssignmentElseRedefinedVars = $tempElseContext->getRedefinedVars($context->varsInScope, true);

        // this captures statements in the if conditional
        if ($context->collectReferences) {
            foreach ($ifContext->unreferencedVars as $varId => $locations) {
                if (!isset($context->unreferencedVars[$varId])) {
                    if (isset($ifScope->newUnreferencedVars[$varId])) {
                        $ifScope->newUnreferencedVars[$varId] += $locations;
                    } else {
                        $ifScope->newUnreferencedVars[$varId] = $locations;
                    }
                } else {
                    $newLocations = array_diff_key(
                        $locations,
                        $context->unreferencedVars[$varId]
                    );

                    if ($newLocations) {
                        if (isset($ifScope->newUnreferencedVars[$varId])) {
                            $ifScope->newUnreferencedVars[$varId] += $locations;
                        } else {
                            $ifScope->newUnreferencedVars[$varId] = $locations;
                        }
                    }
                }
            }
        }

        // check the if
        if (self::analyzeIfBlock(
            $statementsChecker,
            $stmt,
            $ifScope,
            $ifContext,
            $oldIfContext,
            $context,
            $preAssignmentElseRedefinedVars
        ) === false) {
            return false;
        }

        // check the elseifs
        foreach ($stmt->elseifs as $elseif) {
            $elseifContext = clone $originalContext;

            if ($projectChecker->alterCode) {
                $elseifContext->branchPoint =
                    $elseifContext->branchPoint ?: (int) $stmt->getAttribute('startFilePos');
            }

            if (self::analyzeElseIfBlock(
                $statementsChecker,
                $elseif,
                $ifScope,
                $elseifContext,
                $context
            ) === false) {
                return false;
            }
        }

        // check the else
        $elseContext = clone $originalContext;

        if ($stmt->else) {
            if ($projectChecker->alterCode) {
                $elseContext->branchPoint =
                    $elseContext->branchPoint ?: (int) $stmt->getAttribute('startFilePos');
            }
        }

        if (self::analyzeElseBlock(
            $statementsChecker,
            $stmt->else,
            $ifScope,
            $elseContext,
            $context
        ) === false) {
            return false;
        }

        if ($context->loopScope) {
            $context->loopScope->finalActions = array_unique(
                array_merge(
                    $context->loopScope->finalActions,
                    $ifScope->finalActions
                )
            );
        }

        $context->varsPossiblyInScope = array_merge(
            $context->varsPossiblyInScope,
            $ifScope->newVarsPossiblyInScope
        );

        $context->possiblyAssignedVarIds = array_merge(
            $context->possiblyAssignedVarIds,
            $ifScope->possiblyAssignedVarIds ?: []
        );

        // vars can only be defined/redefined if there was an else (defined in every block)
        $context->assignedVarIds = array_merge(
            $context->assignedVarIds,
            $ifScope->assignedVarIds ?: []
        );

        if ($ifScope->newVars) {
            $context->varsInScope = array_merge($context->varsInScope, $ifScope->newVars);
        }

        if ($ifScope->redefinedVars) {
            foreach ($ifScope->redefinedVars as $varId => $type) {
                $context->varsInScope[$varId] = $type;
                $ifScope->updatedVars[$varId] = true;

                if ($ifScope->reasonableClauses) {
                    $ifScope->reasonableClauses = Context::filterClauses(
                        $varId,
                        $ifScope->reasonableClauses,
                        isset($context->varsInScope[$varId])
                            ? $context->varsInScope[$varId]
                            : null,
                        $statementsChecker
                    );
                }
            }
        }

        if ($ifScope->possibleParamTypes) {
            foreach ($ifScope->possibleParamTypes as $var => $type) {
                $context->possibleParamTypes[$var] = $type;
            }
        }

        if ($ifScope->reasonableClauses
            && (count($ifScope->reasonableClauses) > 1 || !$ifScope->reasonableClauses[0]->wedge)
        ) {
            $context->clauses = Algebra::simplifyCNF(
                array_merge(
                    $ifScope->reasonableClauses,
                    $context->clauses
                )
            );
        }

        if ($ifScope->possiblyRedefinedVars) {
            foreach ($ifScope->possiblyRedefinedVars as $varId => $type) {
                if (isset($context->varsInScope[$varId])
                    && !$type->failedReconciliation
                    && !isset($ifScope->updatedVars[$varId])
                ) {
                    $combinedType = Type::combineUnionTypes(
                        $context->varsInScope[$varId],
                        $type
                    );

                    if ($combinedType->equals($context->varsInScope[$varId])) {
                        continue;
                    }

                    $context->removeDescendents($varId, $combinedType);
                    $context->varsInScope[$varId] = $combinedType;
                }
            }
        }

        if ($context->collectReferences) {
            foreach ($ifScope->newUnreferencedVars as $varId => $locations) {
                if (($stmt->else
                        && (isset($ifScope->assignedVarIds[$varId]) || isset($ifScope->newVars[$varId])))
                    || !isset($context->varsInScope[$varId])
                ) {
                    $context->unreferencedVars[$varId] = $locations;
                } elseif (isset($ifScope->possiblyAssignedVarIds[$varId])) {
                    if (!isset($context->unreferencedVars[$varId])) {
                        $context->unreferencedVars[$varId] = $locations;
                    } else {
                        $context->unreferencedVars[$varId] += $locations;
                    }
                } else {
                    $statementsChecker->registerVariableUses($locations);
                }
            }

            $context->possiblyAssignedVarIds += $ifScope->possiblyAssignedVarIds;
        }

        return null;
    }

    /**
     * @param  StatementsChecker        $statementsChecker
     * @param  PhpParser\Node\Stmt\If_  $stmt
     * @param  IfScope                  $ifScope
     * @param  Context                  $ifContext
     * @param  Context                  $oldIfContext
     * @param  Context                  $outerContext
     * @param  array<string,Type\Union> $preAssignmentElseRedefinedVars
     *
     * @return false|null
     */
    protected static function analyzeIfBlock(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Stmt\If_ $stmt,
        IfScope $ifScope,
        Context $ifContext,
        Context $oldIfContext,
        Context $outerContext,
        array $preAssignmentElseRedefinedVars
    ) {
        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;

        $finalActions = ScopeChecker::getFinalControlActions(
            $stmt->stmts,
            $projectChecker->config->exitFunctions,
            $outerContext->insideCase
        );

        $hasEndingStatements = $finalActions === [ScopeChecker::ACTION_END];

        $hasLeavingStatements = $hasEndingStatements
            || (count($finalActions) && !in_array(ScopeChecker::ACTION_NONE, $finalActions, true));

        $hasBreakStatement = $finalActions === [ScopeChecker::ACTION_BREAK];
        $hasContinueStatement = $finalActions === [ScopeChecker::ACTION_CONTINUE];
        $hasLeaveSwitchStatement = $finalActions === [ScopeChecker::ACTION_LEAVE_SWITCH];

        if (!$hasEndingStatements) {
            $ifContext->parentContext = $outerContext;
        }

        $ifScope->finalActions = $finalActions;

        $assignedVarIds = $ifContext->assignedVarIds;
        $possiblyAssignedVarIds = $ifContext->possiblyAssignedVarIds;
        $ifContext->assignedVarIds = [];
        $ifContext->possiblyAssignedVarIds = [];

        if ($statementsChecker->analyze(
            $stmt->stmts,
            $ifContext
        ) === false
        ) {
            return false;
        }

        /** @var array<string, bool> */
        $newAssignedVarIds = $ifContext->assignedVarIds;
        /** @var array<string, bool> */
        $newPossiblyAssignedVarIds = $ifContext->possiblyAssignedVarIds;

        $ifContext->assignedVarIds = array_merge($assignedVarIds, $newAssignedVarIds);
        $ifContext->possiblyAssignedVarIds = array_merge(
            $possiblyAssignedVarIds,
            $newPossiblyAssignedVarIds
        );

        if ($ifContext->byrefConstraints !== null) {
            foreach ($ifContext->byrefConstraints as $varId => $byrefConstraint) {
                if ($outerContext->byrefConstraints !== null
                    && isset($outerContext->byrefConstraints[$varId])
                    && $byrefConstraint->type
                    && ($outerConstraintType = $outerContext->byrefConstraints[$varId]->type)
                    && !TypeChecker::isContainedBy(
                        $projectChecker->codebase,
                        $byrefConstraint->type,
                        $outerConstraintType
                    )
                ) {
                    if (IssueBuffer::accepts(
                        new ConflictingReferenceConstraint(
                            'There is more than one pass-by-reference constraint on ' . $varId,
                            new CodeLocation($statementsChecker, $stmt, $outerContext->includeLocation, true)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                } else {
                    $outerContext->byrefConstraints[$varId] = $byrefConstraint;
                }
            }
        }

        if ($outerContext->collectReferences) {
            $outerContext->referencedVarIds = array_merge(
                $outerContext->referencedVarIds,
                $ifContext->referencedVarIds
            );
        }

        $micDrop = false;

        if (!$hasLeavingStatements) {
            $ifScope->newVars = array_diff_key($ifContext->varsInScope, $outerContext->varsInScope);

            $ifScope->redefinedVars = $ifContext->getRedefinedVars($outerContext->varsInScope);
            $ifScope->possiblyRedefinedVars = $ifScope->redefinedVars;
            $ifScope->assignedVarIds = $newAssignedVarIds;
            $ifScope->possiblyAssignedVarIds = $newPossiblyAssignedVarIds;

            $changedVarIds = array_keys($newAssignedVarIds);

            // if the variable was only set in the conditional, it's not possibly redefined
            foreach ($ifScope->possiblyRedefinedVars as $varId => $_) {
                if (!isset($newPossiblyAssignedVarIds[$varId])
                    && in_array($varId, $ifScope->ifCondChangedVarIds, true)
                ) {
                    unset($ifScope->possiblyRedefinedVars[$varId]);
                }
            }

            if ($ifScope->reasonableClauses) {
                // remove all reasonable clauses that would be negated by the if stmts
                foreach ($changedVarIds as $varId) {
                    $ifScope->reasonableClauses = Context::filterClauses(
                        $varId,
                        $ifScope->reasonableClauses,
                        isset($ifContext->varsInScope[$varId]) ? $ifContext->varsInScope[$varId] : null,
                        $statementsChecker
                    );
                }
            }

            if ($projectChecker->inferTypesFromUsage) {
                $ifScope->possibleParamTypes = $ifContext->possibleParamTypes;
            }
        } else {
            if (!$hasBreakStatement) {
                $ifScope->reasonableClauses = [];
            }
        }

        if ($hasLeavingStatements && !$hasBreakStatement && !$stmt->else && !$stmt->elseifs) {
            if ($ifScope->negatedTypes) {
                $changedVarIds = [];

                $outerContextVarsReconciled = Reconciler::reconcileKeyedTypes(
                    $ifScope->negatedTypes,
                    $outerContext->varsInScope,
                    $changedVarIds,
                    [],
                    $statementsChecker,
                    new CodeLocation(
                        $statementsChecker->getSource(),
                        $stmt->cond,
                        $outerContext->includeLocation,
                        false
                    ),
                    $statementsChecker->getSuppressedIssues()
                );

                foreach ($changedVarIds as $changedVarId) {
                    $outerContext->removeVarFromConflictingClauses($changedVarId);
                }

                $changedVarIds = array_unique(
                    array_merge(
                        $changedVarIds,
                        array_keys($newAssignedVarIds)
                    )
                );

                foreach ($changedVarIds as $varId) {
                    $ifScope->negatedClauses = Context::filterClauses(
                        $varId,
                        $ifScope->negatedClauses
                    );
                }

                $outerContext->varsInScope = $outerContextVarsReconciled;
                $micDrop = true;
            }

            $outerContext->clauses = Algebra::simplifyCNF(
                array_merge($outerContext->clauses, $ifScope->negatedClauses)
            );
        }

        // update the parent context as necessary, but only if we can safely reason about type negation.
        // We only update vars that changed both at the start of the if block and then again by an assignment
        // in the if statement.
        if ($ifScope->negatedTypes && !$micDrop) {
            $varsToUpdate = array_intersect(
                array_keys($preAssignmentElseRedefinedVars),
                array_keys($ifScope->negatedTypes)
            );

            $extraVarsToUpdate = [];

            // if there's an object-like array in there, we also need to update the root array variable
            foreach ($varsToUpdate as $varId) {
                $brackedPos = strpos($varId, '[');
                if ($brackedPos !== false) {
                    $extraVarsToUpdate[] = substr($varId, 0, $brackedPos);
                }
            }

            if ($extraVarsToUpdate) {
                $varsToUpdate = array_unique(array_merge($extraVarsToUpdate, $varsToUpdate));
            }

            //update $ifContext vars to include the pre-assignment else vars
            if (!$stmt->else && !$hasLeavingStatements) {
                foreach ($preAssignmentElseRedefinedVars as $varId => $type) {
                    if (isset($ifContext->varsInScope[$varId])) {
                        $ifContext->varsInScope[$varId] = Type::combineUnionTypes(
                            $ifContext->varsInScope[$varId],
                            $type
                        );
                    }
                }
            }

            $outerContext->update(
                $oldIfContext,
                $ifContext,
                $hasLeavingStatements,
                $varsToUpdate,
                $ifScope->updatedVars
            );
        }

        if (!$hasEndingStatements) {
            $varsPossiblyInScope = array_diff_key(
                $ifContext->varsPossiblyInScope,
                $outerContext->varsPossiblyInScope
            );

            if ($ifContext->loopScope) {
                if (!$hasContinueStatement && !$hasBreakStatement) {
                    $ifScope->newVarsPossiblyInScope = $varsPossiblyInScope;
                }

                $ifContext->loopScope->varsPossiblyInScope = array_merge(
                    $varsPossiblyInScope,
                    $ifContext->loopScope->varsPossiblyInScope
                );
            } elseif (!$hasLeavingStatements) {
                $ifScope->newVarsPossiblyInScope = $varsPossiblyInScope;
            }

            if ($ifContext->collectReferences && (!$hasLeavingStatements || $hasLeaveSwitchStatement)) {
                foreach ($ifContext->unreferencedVars as $varId => $locations) {
                    if (!isset($outerContext->unreferencedVars[$varId])) {
                        if (isset($ifScope->newUnreferencedVars[$varId])) {
                            $ifScope->newUnreferencedVars[$varId] += $locations;
                        } else {
                            $ifScope->newUnreferencedVars[$varId] = $locations;
                        }
                    } else {
                        $newLocations = array_diff_key(
                            $locations,
                            $outerContext->unreferencedVars[$varId]
                        );

                        if ($newLocations) {
                            if (isset($ifScope->newUnreferencedVars[$varId])) {
                                $ifScope->newUnreferencedVars[$varId] += $locations;
                            } else {
                                $ifScope->newUnreferencedVars[$varId] = $locations;
                            }
                        }
                    }
                }
            }
        }

        if ($outerContext->collectExceptions) {
            $outerContext->possiblyThrownExceptions += $ifContext->possiblyThrownExceptions;
        }
    }

    /**
     * @param  StatementsChecker           $statementsChecker
     * @param  PhpParser\Node\Stmt\ElseIf_ $elseif
     * @param  IfScope                     $ifScope
     * @param  Context                     $elseifContext
     * @param  Context                     $outerContext
     *
     * @return false|null
     */
    protected static function analyzeElseIfBlock(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Stmt\ElseIf_ $elseif,
        IfScope $ifScope,
        Context $elseifContext,
        Context $outerContext
    ) {
        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;

        $originalContext = clone $elseifContext;

        $entryClauses = array_merge($originalContext->clauses, $ifScope->negatedClauses);

        $changedVarIds = [];

        if ($ifScope->negatedTypes) {
            $elseifVarsReconciled = Reconciler::reconcileKeyedTypes(
                $ifScope->negatedTypes,
                $elseifContext->varsInScope,
                $changedVarIds,
                [],
                $statementsChecker,
                new CodeLocation(
                    $statementsChecker->getSource(),
                    $elseif->cond,
                    $outerContext->includeLocation,
                    false
                ),
                $statementsChecker->getSuppressedIssues()
            );

            $elseifContext->varsInScope = $elseifVarsReconciled;

            if ($changedVarIds) {
                $entryClauses = array_filter(
                    $entryClauses,
                    /** @return bool */
                    function (Clause $c) use ($changedVarIds) {
                        return count($c->possibilities) > 1
                            || $c->wedge
                            || !in_array(array_keys($c->possibilities)[0], $changedVarIds, true);
                    }
                );
            }
        }

        $preConditionalContext = clone $elseifContext;

        $elseifContext->insideConditional = true;

        $preAssignedVarIds = $elseifContext->assignedVarIds;

        $referencedVarIds = $elseifContext->referencedVarIds;
        $elseifContext->referencedVarIds = [];

        // check the elseif
        if (ExpressionChecker::analyze($statementsChecker, $elseif->cond, $elseifContext) === false) {
            return false;
        }

        $newReferencedVarIds = $elseifContext->referencedVarIds;
        $elseifContext->referencedVarIds = array_merge(
            $referencedVarIds,
            $elseifContext->referencedVarIds
        );

        $conditionalAssignedVarIds = $elseifContext->assignedVarIds;

        $elseifContext->assignedVarIds = array_merge(
            $preAssignedVarIds,
            $conditionalAssignedVarIds
        );

        $newAssignedVarIds = array_diff_key(
            $conditionalAssignedVarIds,
            $preAssignedVarIds
        );

        $newReferencedVarIds = array_diff_key($newReferencedVarIds, $newAssignedVarIds);

        $elseifContext->insideConditional = false;

        $mixedVarIds = [];

        foreach ($elseifContext->varsInScope as $varId => $type) {
            if ($type->isMixed()) {
                $mixedVarIds[] = $varId;
            }
        }

        $elseifClauses = Algebra::getFormula(
            $elseif->cond,
            $statementsChecker->getFQCLN(),
            $statementsChecker
        );

        $elseifClauses = array_map(
            /**
             * @return Clause
             */
            function (Clause $c) use ($mixedVarIds) {
                $keys = array_keys($c->possibilities);

                foreach ($keys as $key) {
                    foreach ($mixedVarIds as $mixedVarId) {
                        if (preg_match('/^' . preg_quote($mixedVarId, '/') . '(\[|-)/', $key)) {
                            return new Clause([], true);
                        }
                    }
                }

                return $c;
            },
            $elseifClauses
        );

        $entryClauses = array_map(
            /**
             * @return Clause
             */
            function (Clause $c) use ($conditionalAssignedVarIds) {
                $keys = array_keys($c->possibilities);

                foreach ($keys as $key) {
                    foreach ($conditionalAssignedVarIds as $conditionalAssignedVarId => $_) {
                        if (preg_match('/^' . preg_quote($conditionalAssignedVarId, '/') . '(\[|-|$)/', $key)) {
                            return new Clause([], true);
                        }
                    }
                }

                return $c;
            },
            $entryClauses
        );

        // this will see whether any of the clauses in set A conflict with the clauses in set B
        AlgebraChecker::checkForParadox(
            $entryClauses,
            $elseifClauses,
            $statementsChecker,
            $elseif->cond,
            $newAssignedVarIds
        );

        $elseifContext->clauses = Algebra::simplifyCNF(
            array_merge(
                $entryClauses,
                $elseifClauses
            )
        );

        $reconcilableElseifTypes = Algebra::getTruthsFromFormula($elseifContext->clauses);
        $negatedElseifTypes = Algebra::getTruthsFromFormula(Algebra::negateFormula($elseifClauses));

        $allNegatedVars = array_unique(
            array_merge(
                array_keys($negatedElseifTypes),
                array_keys($ifScope->negatedTypes)
            )
        );

        foreach ($allNegatedVars as $varId) {
            if (isset($negatedElseifTypes[$varId])) {
                if (isset($ifScope->negatedTypes[$varId])) {
                    $ifScope->negatedTypes[$varId] = array_merge(
                        $ifScope->negatedTypes[$varId],
                        $negatedElseifTypes[$varId]
                    );
                } else {
                    $ifScope->negatedTypes[$varId] = $negatedElseifTypes[$varId];
                }
            }
        }

        $changedVarIds = $changedVarIds ?: [];

        // if the elseif has an || in the conditional, we cannot easily reason about it
        if ($reconcilableElseifTypes) {
            $elseifVarsReconciled = Reconciler::reconcileKeyedTypes(
                $reconcilableElseifTypes,
                $elseifContext->varsInScope,
                $changedVarIds,
                $newReferencedVarIds,
                $statementsChecker,
                new CodeLocation($statementsChecker->getSource(), $elseif->cond, $outerContext->includeLocation),
                $statementsChecker->getSuppressedIssues()
            );

            $elseifContext->varsInScope = $elseifVarsReconciled;

            if ($changedVarIds) {
                $elseifContext->removeReconciledClauses($changedVarIds);
            }
        }

        $oldElseifContext = clone $elseifContext;

        $preStmtsAssignedVarIds = $elseifContext->assignedVarIds;
        $elseifContext->assignedVarIds = [];
        $preStmtsPossiblyAssignedVarIds = $elseifContext->possiblyAssignedVarIds;
        $elseifContext->possiblyAssignedVarIds = [];

        if ($statementsChecker->analyze(
            $elseif->stmts,
            $elseifContext
        ) === false
        ) {
            return false;
        }

        /** @var array<string, bool> */
        $newStmtsAssignedVarIds = $elseifContext->assignedVarIds;
        $elseifContext->assignedVarIds = $preStmtsAssignedVarIds + $newStmtsAssignedVarIds;

        /** @var array<string, bool> */
        $newStmtsPossiblyAssignedVarIds = $elseifContext->possiblyAssignedVarIds;
        $elseifContext->possiblyAssignedVarIds =
            $preStmtsPossiblyAssignedVarIds + $newStmtsPossiblyAssignedVarIds;

        if ($elseifContext->byrefConstraints !== null) {
            foreach ($elseifContext->byrefConstraints as $varId => $byrefConstraint) {
                if ($outerContext->byrefConstraints !== null
                    && isset($outerContext->byrefConstraints[$varId])
                    && ($outerConstraintType = $outerContext->byrefConstraints[$varId]->type)
                    && $byrefConstraint->type
                    && !TypeChecker::isContainedBy(
                        $projectChecker->codebase,
                        $byrefConstraint->type,
                        $outerConstraintType
                    )
                ) {
                    if (IssueBuffer::accepts(
                        new ConflictingReferenceConstraint(
                            'There is more than one pass-by-reference constraint on ' . $varId,
                            new CodeLocation($statementsChecker, $elseif, $outerContext->includeLocation, true)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                } else {
                    $outerContext->byrefConstraints[$varId] = $byrefConstraint;
                }
            }
        }

        $finalActions = ScopeChecker::getFinalControlActions(
            $elseif->stmts,
            $projectChecker->config->exitFunctions,
            $outerContext->insideCase
        );
        // has a return/throw at end
        $hasEndingStatements = $finalActions === [ScopeChecker::ACTION_END];
        $hasLeavingStatements = $hasEndingStatements
            || (count($finalActions) && !in_array(ScopeChecker::ACTION_NONE, $finalActions, true));

        $hasBreakStatement = $finalActions === [ScopeChecker::ACTION_BREAK];
        $hasContinueStatement = $finalActions === [ScopeChecker::ACTION_CONTINUE];
        $hasLeaveSwitchStatement = $finalActions === [ScopeChecker::ACTION_LEAVE_SWITCH];

        $ifScope->finalActions = array_merge($finalActions, $ifScope->finalActions);

        // update the parent context as necessary
        $elseifRedefinedVars = $elseifContext->getRedefinedVars($originalContext->varsInScope);

        if (!$hasLeavingStatements) {
            if ($ifScope->newVars === null) {
                $ifScope->newVars = array_diff_key($elseifContext->varsInScope, $outerContext->varsInScope);
            } else {
                foreach ($ifScope->newVars as $newVar => $type) {
                    if (!$elseifContext->hasVariable($newVar, $statementsChecker)) {
                        unset($ifScope->newVars[$newVar]);
                    } else {
                        $ifScope->newVars[$newVar] = Type::combineUnionTypes(
                            $type,
                            $elseifContext->varsInScope[$newVar]
                        );
                    }
                }
            }

            $possiblyRedefinedVars = $elseifRedefinedVars;

            foreach ($possiblyRedefinedVars as $varId => $_) {
                if (!isset($newStmtsAssignedVarIds[$varId])
                    && in_array($varId, $changedVarIds, true)
                ) {
                    unset($possiblyRedefinedVars[$varId]);
                }
            }

            $assignedVarIds = array_merge($newStmtsAssignedVarIds, $newAssignedVarIds);

            if ($ifScope->assignedVarIds === null) {
                $ifScope->assignedVarIds = $assignedVarIds;
            } else {
                $ifScope->assignedVarIds = array_intersect_key($assignedVarIds, $ifScope->assignedVarIds);
            }

            if ($ifScope->redefinedVars === null) {
                $ifScope->redefinedVars = $elseifRedefinedVars;
                $ifScope->possiblyRedefinedVars = $possiblyRedefinedVars;
            } else {
                foreach ($ifScope->redefinedVars as $redefinedVar => $type) {
                    if (!isset($elseifRedefinedVars[$redefinedVar])) {
                        unset($ifScope->redefinedVars[$redefinedVar]);
                    } else {
                        $ifScope->redefinedVars[$redefinedVar] = Type::combineUnionTypes(
                            $elseifRedefinedVars[$redefinedVar],
                            $type
                        );

                        if (isset($outerContext->varsInScope[$redefinedVar])
                            && $ifScope->redefinedVars[$redefinedVar]->equals(
                                $outerContext->varsInScope[$redefinedVar]
                            )
                        ) {
                            unset($ifScope->redefinedVars[$redefinedVar]);
                        }
                    }
                }

                foreach ($possiblyRedefinedVars as $var => $type) {
                    if ($type->isMixed()) {
                        $ifScope->possiblyRedefinedVars[$var] = $type;
                    } elseif (isset($ifScope->possiblyRedefinedVars[$var])) {
                        $ifScope->possiblyRedefinedVars[$var] = Type::combineUnionTypes(
                            $type,
                            $ifScope->possiblyRedefinedVars[$var]
                        );
                    } else {
                        $ifScope->possiblyRedefinedVars[$var] = $type;
                    }
                }
            }

            $reasonableClauseCount = count($ifScope->reasonableClauses);

            if ($reasonableClauseCount && $reasonableClauseCount < 20000 && $elseifClauses) {
                $ifScope->reasonableClauses = Algebra::combineOredClauses(
                    $ifScope->reasonableClauses,
                    $elseifClauses
                );
            } else {
                $ifScope->reasonableClauses = [];
            }
        } else {
            $ifScope->reasonableClauses = [];
        }

        if ($projectChecker->inferTypesFromUsage) {
            $elseifPossibleParamTypes = $elseifContext->possibleParamTypes;

            if ($ifScope->possibleParamTypes) {
                $varsToRemove = [];

                foreach ($ifScope->possibleParamTypes as $var => $type) {
                    if (isset($elseifPossibleParamTypes[$var])) {
                        $ifScope->possibleParamTypes[$var] = Type::combineUnionTypes(
                            $elseifPossibleParamTypes[$var],
                            $type
                        );
                    } else {
                        $varsToRemove[] = $var;
                    }
                }

                foreach ($varsToRemove as $var) {
                    unset($ifScope->possibleParamTypes[$var]);
                }
            }
        }

        if ($negatedElseifTypes) {
            if ($hasLeavingStatements) {
                $changedVarIds = [];

                $leavingVarsReconciled = Reconciler::reconcileKeyedTypes(
                    $negatedElseifTypes,
                    $preConditionalContext->varsInScope,
                    $changedVarIds,
                    [],
                    $statementsChecker,
                    new CodeLocation($statementsChecker->getSource(), $elseif, $outerContext->includeLocation),
                    $statementsChecker->getSuppressedIssues()
                );

                $impliedOuterContext = clone $elseifContext;
                $impliedOuterContext->varsInScope = $leavingVarsReconciled;

                $outerContext->update(
                    $elseifContext,
                    $impliedOuterContext,
                    false,
                    array_keys($negatedElseifTypes),
                    $ifScope->updatedVars
                );
            } elseif ($entryClauses && (count($entryClauses) > 1 || !array_values($entryClauses)[0]->wedge)) {
                $outerContext->update(
                    $oldElseifContext,
                    $elseifContext,
                    false,
                    array_keys($negatedElseifTypes),
                    $ifScope->updatedVars
                );
            }
        }

        if (!$hasEndingStatements) {
            $varsPossiblyInScope = array_diff_key(
                $elseifContext->varsPossiblyInScope,
                $outerContext->varsPossiblyInScope
            );

            $possiblyAssignedVarIds = $newStmtsPossiblyAssignedVarIds;

            if ($hasLeavingStatements && $elseifContext->loopScope) {
                if (!$hasContinueStatement && !$hasBreakStatement) {
                    $ifScope->newVarsPossiblyInScope = array_merge(
                        $varsPossiblyInScope,
                        $ifScope->newVarsPossiblyInScope
                    );
                    $ifScope->possiblyAssignedVarIds = array_merge(
                        $possiblyAssignedVarIds,
                        $ifScope->possiblyAssignedVarIds
                    );
                }

                $elseifContext->loopScope->varsPossiblyInScope = array_merge(
                    $varsPossiblyInScope,
                    $elseifContext->loopScope->varsPossiblyInScope
                );
            } elseif (!$hasLeavingStatements) {
                $ifScope->newVarsPossiblyInScope = array_merge(
                    $varsPossiblyInScope,
                    $ifScope->newVarsPossiblyInScope
                );
                $ifScope->possiblyAssignedVarIds = array_merge(
                    $possiblyAssignedVarIds,
                    $ifScope->possiblyAssignedVarIds
                );
            }

            if ($outerContext->collectReferences &&  (!$hasLeavingStatements || $hasLeaveSwitchStatement)) {
                foreach ($elseifContext->unreferencedVars as $varId => $locations) {
                    if (!isset($outerContext->unreferencedVars[$varId])) {
                        if (isset($ifScope->newUnreferencedVars[$varId])) {
                            $ifScope->newUnreferencedVars[$varId] += $locations;
                        } else {
                            $ifScope->newUnreferencedVars[$varId] = $locations;
                        }
                    } else {
                        $newLocations = array_diff_key(
                            $locations,
                            $outerContext->unreferencedVars[$varId]
                        );

                        if ($newLocations) {
                            if (isset($ifScope->newUnreferencedVars[$varId])) {
                                $ifScope->newUnreferencedVars[$varId] += $locations;
                            } else {
                                $ifScope->newUnreferencedVars[$varId] = $locations;
                            }
                        }
                    }
                }
            }
        }

        if ($outerContext->collectReferences) {
            $outerContext->referencedVarIds = array_merge(
                $outerContext->referencedVarIds,
                $elseifContext->referencedVarIds
            );
        }

        if ($outerContext->collectExceptions) {
            $outerContext->possiblyThrownExceptions += $elseifContext->possiblyThrownExceptions;
        }

        $ifScope->negatedClauses = array_merge(
            $ifScope->negatedClauses,
            Algebra::negateFormula($elseifClauses)
        );
    }

    /**
     * @param  StatementsChecker         $statementsChecker
     * @param  PhpParser\Node\Stmt\Else_|null $else
     * @param  IfScope                   $ifScope
     * @param  Context                   $elseContext
     * @param  Context                   $outerContext
     *
     * @return false|null
     */
    protected static function analyzeElseBlock(
        StatementsChecker $statementsChecker,
        $else,
        IfScope $ifScope,
        Context $elseContext,
        Context $outerContext
    ) {
        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;

        if (!$else && !$ifScope->negatedClauses && !$elseContext->clauses) {
            $ifScope->finalActions = array_merge([ScopeChecker::ACTION_NONE], $ifScope->finalActions);
            $ifScope->assignedVarIds = [];
            $ifScope->newVars = [];
            $ifScope->redefinedVars = [];
            $ifScope->reasonableClauses = [];

            return;
        }

        $elseContext->clauses = Algebra::simplifyCNF(
            array_merge(
                $elseContext->clauses,
                $ifScope->negatedClauses
            )
        );

        $elseTypes = Algebra::getTruthsFromFormula($elseContext->clauses);

        if (!$else && !$elseTypes) {
            $ifScope->finalActions = array_merge([ScopeChecker::ACTION_NONE], $ifScope->finalActions);
            $ifScope->assignedVarIds = [];
            $ifScope->newVars = [];
            $ifScope->redefinedVars = [];
            $ifScope->reasonableClauses = [];

            return;
        }

        $originalContext = clone $elseContext;

        if ($elseTypes) {
            $changedVarIds = [];

            $elseVarsReconciled = Reconciler::reconcileKeyedTypes(
                $elseTypes,
                $elseContext->varsInScope,
                $changedVarIds,
                [],
                $statementsChecker,
                $else
                    ? new CodeLocation($statementsChecker->getSource(), $else, $outerContext->includeLocation)
                    : null,
                $statementsChecker->getSuppressedIssues()
            );

            $elseContext->varsInScope = $elseVarsReconciled;

            $elseContext->removeReconciledClauses($changedVarIds);
        }

        $oldElseContext = clone $elseContext;

        $preStmtsAssignedVarIds = $elseContext->assignedVarIds;
        $elseContext->assignedVarIds = [];

        $prePossiblyAssignedVarIds = $elseContext->possiblyAssignedVarIds;
        $elseContext->possiblyAssignedVarIds = [];

        if ($else) {
            if ($statementsChecker->analyze(
                $else->stmts,
                $elseContext
            ) === false
            ) {
                return false;
            }
        }

        /** @var array<string, bool> */
        $newAssignedVarIds = $elseContext->assignedVarIds;
        $elseContext->assignedVarIds = $preStmtsAssignedVarIds;

        /** @var array<string, bool> */
        $newPossiblyAssignedVarIds = $elseContext->possiblyAssignedVarIds;
        $elseContext->possiblyAssignedVarIds = $prePossiblyAssignedVarIds + $newPossiblyAssignedVarIds;

        if ($else && $elseContext->byrefConstraints !== null) {
            $projectChecker = $statementsChecker->getFileChecker()->projectChecker;

            foreach ($elseContext->byrefConstraints as $varId => $byrefConstraint) {
                if ($outerContext->byrefConstraints !== null
                    && isset($outerContext->byrefConstraints[$varId])
                    && ($outerConstraintType = $outerContext->byrefConstraints[$varId]->type)
                    && $byrefConstraint->type
                    && !TypeChecker::isContainedBy(
                        $projectChecker->codebase,
                        $byrefConstraint->type,
                        $outerConstraintType
                    )
                ) {
                    if (IssueBuffer::accepts(
                        new ConflictingReferenceConstraint(
                            'There is more than one pass-by-reference constraint on ' . $varId,
                            new CodeLocation($statementsChecker, $else, $outerContext->includeLocation, true)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                } else {
                    $outerContext->byrefConstraints[$varId] = $byrefConstraint;
                }
            }
        }

        if ($else && $outerContext->collectReferences) {
            $outerContext->referencedVarIds = array_merge(
                $outerContext->referencedVarIds,
                $elseContext->referencedVarIds
            );
        }

        $finalActions = $else
            ? ScopeChecker::getFinalControlActions(
                $else->stmts,
                $projectChecker->config->exitFunctions,
                $outerContext->insideCase
            )
            : [ScopeChecker::ACTION_NONE];
        // has a return/throw at end
        $hasEndingStatements = $finalActions === [ScopeChecker::ACTION_END];
        $hasLeavingStatements = $hasEndingStatements
            || (count($finalActions) && !in_array(ScopeChecker::ACTION_NONE, $finalActions, true));

        $hasBreakStatement = $finalActions === [ScopeChecker::ACTION_BREAK];
        $hasContinueStatement = $finalActions === [ScopeChecker::ACTION_CONTINUE];
        $hasLeaveSwitchStatement = $finalActions === [ScopeChecker::ACTION_LEAVE_SWITCH];

        $ifScope->finalActions = array_merge($finalActions, $ifScope->finalActions);

        $elseRedefinedVars = $elseContext->getRedefinedVars($originalContext->varsInScope);

        // if it doesn't end in a return
        if (!$hasLeavingStatements) {
            if ($ifScope->newVars === null && $else) {
                $ifScope->newVars = array_diff_key($elseContext->varsInScope, $outerContext->varsInScope);
            } elseif ($ifScope->newVars !== null) {
                foreach ($ifScope->newVars as $newVar => $type) {
                    if (!$elseContext->hasVariable($newVar)) {
                        unset($ifScope->newVars[$newVar]);
                    } else {
                        $ifScope->newVars[$newVar] = Type::combineUnionTypes(
                            $type,
                            $elseContext->varsInScope[$newVar]
                        );
                    }
                }
            }

            if ($ifScope->assignedVarIds === null) {
                $ifScope->assignedVarIds = $newAssignedVarIds;
            } else {
                $ifScope->assignedVarIds = array_intersect_key($newAssignedVarIds, $ifScope->assignedVarIds);
            }

            if ($ifScope->redefinedVars === null) {
                $ifScope->redefinedVars = $elseRedefinedVars;
                $ifScope->possiblyRedefinedVars = $ifScope->redefinedVars;
            } else {
                foreach ($ifScope->redefinedVars as $redefinedVar => $type) {
                    if (!isset($elseRedefinedVars[$redefinedVar])) {
                        unset($ifScope->redefinedVars[$redefinedVar]);
                    } else {
                        $ifScope->redefinedVars[$redefinedVar] = Type::combineUnionTypes(
                            $elseRedefinedVars[$redefinedVar],
                            $type
                        );
                    }
                }

                foreach ($elseRedefinedVars as $var => $type) {
                    if ($type->isMixed()) {
                        $ifScope->possiblyRedefinedVars[$var] = $type;
                    } elseif (isset($ifScope->possiblyRedefinedVars[$var])) {
                        $ifScope->possiblyRedefinedVars[$var] = Type::combineUnionTypes(
                            $type,
                            $ifScope->possiblyRedefinedVars[$var]
                        );
                    } else {
                        $ifScope->possiblyRedefinedVars[$var] = $type;
                    }
                }
            }

            $ifScope->reasonableClauses = [];
        }

        // update the parent context as necessary
        if ($ifScope->negatableIfTypes) {
            $outerContext->update(
                $oldElseContext,
                $elseContext,
                $hasLeavingStatements,
                array_keys($ifScope->negatableIfTypes),
                $ifScope->updatedVars
            );
        }

        if (!$hasEndingStatements) {
            $varsPossiblyInScope = array_diff_key(
                $elseContext->varsPossiblyInScope,
                $outerContext->varsPossiblyInScope
            );

            $possiblyAssignedVarIds = $newPossiblyAssignedVarIds;

            if ($hasLeavingStatements && $elseContext->loopScope) {
                if (!$hasContinueStatement && !$hasBreakStatement) {
                    $ifScope->newVarsPossiblyInScope = array_merge(
                        $varsPossiblyInScope,
                        $ifScope->newVarsPossiblyInScope
                    );

                    $ifScope->possiblyAssignedVarIds = array_merge(
                        $possiblyAssignedVarIds,
                        $ifScope->possiblyAssignedVarIds
                    );
                }

                $elseContext->loopScope->varsPossiblyInScope = array_merge(
                    $varsPossiblyInScope,
                    $elseContext->loopScope->varsPossiblyInScope
                );
            } elseif (!$hasLeavingStatements) {
                $ifScope->newVarsPossiblyInScope = array_merge(
                    $varsPossiblyInScope,
                    $ifScope->newVarsPossiblyInScope
                );

                $ifScope->possiblyAssignedVarIds = array_merge(
                    $possiblyAssignedVarIds,
                    $ifScope->possiblyAssignedVarIds
                );
            }

            if ($outerContext->collectReferences && (!$hasLeavingStatements || $hasLeaveSwitchStatement)) {
                foreach ($elseContext->unreferencedVars as $varId => $locations) {
                    if (!isset($outerContext->unreferencedVars[$varId])) {
                        if (isset($ifScope->newUnreferencedVars[$varId])) {
                            $ifScope->newUnreferencedVars[$varId] += $locations;
                        } else {
                            $ifScope->newUnreferencedVars[$varId] = $locations;
                        }
                    } else {
                        $newLocations = array_diff_key(
                            $locations,
                            $outerContext->unreferencedVars[$varId]
                        );

                        if ($newLocations) {
                            if (isset($ifScope->newUnreferencedVars[$varId])) {
                                $ifScope->newUnreferencedVars[$varId] += $locations;
                            } else {
                                $ifScope->newUnreferencedVars[$varId] = $locations;
                            }
                        }
                    }
                }
            }
        }

        if ($outerContext->collectExceptions) {
            $outerContext->possiblyThrownExceptions += $elseContext->possiblyThrownExceptions;
        }

        if ($projectChecker->inferTypesFromUsage) {
            $elsePossibleParamTypes = $elseContext->possibleParamTypes;

            if ($ifScope->possibleParamTypes) {
                $varsToRemove = [];

                foreach ($ifScope->possibleParamTypes as $var => $type) {
                    if (isset($elsePossibleParamTypes[$var])) {
                        $ifScope->possibleParamTypes[$var] = Type::combineUnionTypes(
                            $elsePossibleParamTypes[$var],
                            $type
                        );
                    } else {
                        $varsToRemove[] = $var;
                    }
                }

                foreach ($varsToRemove as $var) {
                    unset($ifScope->possibleParamTypes[$var]);
                }
            }
        }
    }

    /**
     * Returns statements that are definitely evaluated before any statements after the end of the
     * if/elseif/else blocks
     *
     * @param  PhpParser\Node\Expr $stmt
     * @param  bool $insideAnd
     *
     * @return PhpParser\Node\Expr|null
     */
    protected static function getDefinitelyEvaluatedExpression(PhpParser\Node\Expr $stmt)
    {
        if ($stmt instanceof PhpParser\Node\Expr\BinaryOp) {
            if ($stmt instanceof PhpParser\Node\Expr\BinaryOp\BooleanAnd
                || $stmt instanceof PhpParser\Node\Expr\BinaryOp\LogicalAnd
                || $stmt instanceof PhpParser\Node\Expr\BinaryOp\LogicalXor
            ) {
                return self::getDefinitelyEvaluatedExpression($stmt->left);
            }

            return $stmt;
        }

        return $stmt;
    }
}
