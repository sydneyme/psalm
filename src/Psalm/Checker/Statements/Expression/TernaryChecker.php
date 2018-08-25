<?php
namespace Psalm\Checker\Statements\Expression;

use PhpParser;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Type;
use Psalm\Type\Algebra;
use Psalm\Type\Reconciler;

class TernaryChecker
{
    /**
     * @param   StatementsChecker           $statementsChecker
     * @param   PhpParser\Node\Expr\Ternary $stmt
     * @param   Context                     $context
     *
     * @return  false|null
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\Ternary $stmt,
        Context $context
    ) {
        $preReferencedVarIds = $context->referencedVarIds;
        $context->referencedVarIds = [];

        $context->insideConditional = true;
        if (ExpressionChecker::analyze($statementsChecker, $stmt->cond, $context) === false) {
            return false;
        }

        $newReferencedVarIds = $context->referencedVarIds;
        $context->referencedVarIds = array_merge($preReferencedVarIds, $newReferencedVarIds);

        $context->insideConditional = false;

        $tIfContext = clone $context;

        $ifClauses = \Psalm\Type\Algebra::getFormula(
            $stmt->cond,
            $statementsChecker->getFQCLN(),
            $statementsChecker
        );

        $mixedVarIds = [];

        foreach ($context->varsInScope as $varId => $type) {
            if ($type->isMixed()) {
                $mixedVarIds[] = $varId;
            }
        }

        foreach ($context->varsPossiblyInScope as $varId => $_) {
            if (!isset($context->varsInScope[$varId])) {
                $mixedVarIds[] = $varId;
            }
        }


        $ifClauses = array_values(
            array_map(
                /**
                 * @return \Psalm\Clause
                 */
                function (\Psalm\Clause $c) use ($mixedVarIds) {
                    $keys = array_keys($c->possibilities);

                    foreach ($keys as $key) {
                        foreach ($mixedVarIds as $mixedVarId) {
                            if (preg_match('/^' . preg_quote($mixedVarId, '/') . '(\[|-)/', $key)) {
                                return new \Psalm\Clause([], true);
                            }
                        }
                    }

                    return $c;
                },
                $ifClauses
            )
        );

        $ternaryClauses = Algebra::simplifyCNF(array_merge($context->clauses, $ifClauses));

        $negatedClauses = Algebra::negateFormula($ifClauses);

        $negatedIfTypes = Algebra::getTruthsFromFormula(
            Algebra::simplifyCNF(
                array_merge($context->clauses, $negatedClauses)
            )
        );

        $reconcilableIfTypes = Algebra::getTruthsFromFormula($ternaryClauses, $newReferencedVarIds);

        $changedVarIds = [];

        $tIfVarsInScopeReconciled = Reconciler::reconcileKeyedTypes(
            $reconcilableIfTypes,
            $tIfContext->varsInScope,
            $changedVarIds,
            $newReferencedVarIds,
            $statementsChecker,
            new CodeLocation($statementsChecker->getSource(), $stmt->cond),
            $statementsChecker->getSuppressedIssues()
        );

        $tIfContext->varsInScope = $tIfVarsInScopeReconciled;
        $tElseContext = clone $context;

        if ($stmt->if) {
            if (ExpressionChecker::analyze($statementsChecker, $stmt->if, $tIfContext) === false) {
                return false;
            }

            foreach ($tIfContext->varsInScope as $varId => $type) {
                if (isset($context->varsInScope[$varId])) {
                    $context->varsInScope[$varId] = Type::combineUnionTypes($context->varsInScope[$varId], $type);
                }
            }

            $context->referencedVarIds = array_merge(
                $context->referencedVarIds,
                $tIfContext->referencedVarIds
            );

            $context->unreferencedVars = array_intersect_key(
                $context->unreferencedVars,
                $tIfContext->unreferencedVars
            );
        }

        if ($negatedIfTypes) {
            $tElseVarsInScopeReconciled = Reconciler::reconcileKeyedTypes(
                $negatedIfTypes,
                $tElseContext->varsInScope,
                $changedVarIds,
                $newReferencedVarIds,
                $statementsChecker,
                new CodeLocation($statementsChecker->getSource(), $stmt->else),
                $statementsChecker->getSuppressedIssues()
            );

            $tElseContext->varsInScope = $tElseVarsInScopeReconciled;
        }

        if (ExpressionChecker::analyze($statementsChecker, $stmt->else, $tElseContext) === false) {
            return false;
        }

        foreach ($tElseContext->varsInScope as $varId => $type) {
            if (isset($context->varsInScope[$varId])) {
                $context->varsInScope[$varId] = Type::combineUnionTypes(
                    $context->varsInScope[$varId],
                    $type
                );
            } elseif (isset($tIfContext->varsInScope[$varId])) {
                $context->varsInScope[$varId] = Type::combineUnionTypes(
                    $tIfContext->varsInScope[$varId],
                    $type
                );
            }
        }

        $context->varsPossiblyInScope = array_merge(
            $context->varsPossiblyInScope,
            $tIfContext->varsPossiblyInScope,
            $tElseContext->varsPossiblyInScope
        );

        $context->referencedVarIds = array_merge(
            $context->referencedVarIds,
            $tElseContext->referencedVarIds
        );

        $context->unreferencedVars = array_intersect_key(
            $context->unreferencedVars,
            $tElseContext->unreferencedVars
        );

        $lhsType = null;

        if ($stmt->if) {
            if (isset($stmt->if->inferredType)) {
                $lhsType = $stmt->if->inferredType;
            }
        } elseif (isset($stmt->cond->inferredType)) {
            $ifReturnTypeReconciled = Reconciler::reconcileTypes(
                '!falsy',
                $stmt->cond->inferredType,
                '',
                $statementsChecker,
                new CodeLocation($statementsChecker->getSource(), $stmt),
                $statementsChecker->getSuppressedIssues()
            );

            $lhsType = $ifReturnTypeReconciled;
        }

        if (!$lhsType || !isset($stmt->else->inferredType)) {
            $stmt->inferredType = Type::getMixed();
        } else {
            $stmt->inferredType = Type::combineUnionTypes($lhsType, $stmt->else->inferredType);
        }

        return null;
    }
}
