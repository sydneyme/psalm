<?php
namespace Psalm\Checker\Statements\Block;

use PhpParser;
use Psalm\Checker\AlgebraChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Clause;
use Psalm\Context;
use Psalm\Scope\LoopScope;
use Psalm\Type;

class DoChecker
{
    /**
     * @return void
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Stmt\Do_ $stmt,
        Context $context
    ) {
        $doContext = clone $context;

        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;

        if ($projectChecker->alterCode) {
            $doContext->branchPoint = $doContext->branchPoint ?: (int) $stmt->getAttribute('startFilePos');
        }

        $loopScope = new LoopScope($doContext, $context);
        $loopScope->protectedVarIds = $context->protectedVarIds;

        $suppressedIssues = $statementsChecker->getSuppressedIssues();

        if (!in_array('RedundantCondition', $suppressedIssues, true)) {
            $statementsChecker->addSuppressedIssues(['RedundantCondition']);
        }
        if (!in_array('RedundantConditionGivenDocblockType', $suppressedIssues, true)) {
            $statementsChecker->addSuppressedIssues(['RedundantConditionGivenDocblockType']);
        }
        if (!in_array('TypeDoesNotContainType', $suppressedIssues, true)) {
            $statementsChecker->addSuppressedIssues(['TypeDoesNotContainType']);
        }

        $doContext->loopScope = $loopScope;

        $statementsChecker->analyze($stmt->stmts, $doContext);

        if (!in_array('RedundantCondition', $suppressedIssues, true)) {
            $statementsChecker->removeSuppressedIssues(['RedundantCondition']);
        }
        if (!in_array('RedundantConditionGivenDocblockType', $suppressedIssues, true)) {
            $statementsChecker->removeSuppressedIssues(['RedundantConditionGivenDocblockType']);
        }
        if (!in_array('TypeDoesNotContainType', $suppressedIssues, true)) {
            $statementsChecker->removeSuppressedIssues(['TypeDoesNotContainType']);
        }

        foreach ($context->varsInScope as $var => $type) {
            if ($type->isMixed()) {
                continue;
            }

            if ($doContext->hasVariable($var)) {
                if ($context->varsInScope[$var]->isMixed()) {
                    $doContext->varsInScope[$var] = $doContext->varsInScope[$var];
                }

                if ($doContext->varsInScope[$var]->getId() !== $type->getId()) {
                    $doContext->varsInScope[$var] = Type::combineUnionTypes($doContext->varsInScope[$var], $type);
                }
            }
        }

        foreach ($doContext->varsInScope as $varId => $type) {
            if (!isset($context->varsInScope[$varId])) {
                $context->varsInScope[$varId] = clone $type;
            }
        }

        $mixedVarIds = [];

        foreach ($doContext->varsInScope as $varId => $type) {
            if ($type->isMixed()) {
                $mixedVarIds[] = $varId;
            }
        }

        $whileClauses = \Psalm\Type\Algebra::getFormula(
            $stmt->cond,
            $context->self,
            $statementsChecker
        );

        $whileClauses = array_values(
            array_filter(
                $whileClauses,
                /** @return bool */
                function (Clause $c) use ($mixedVarIds) {
                    $keys = array_keys($c->possibilities);

                    foreach ($keys as $key) {
                        foreach ($mixedVarIds as $mixedVarId) {
                            if (preg_match('/^' . preg_quote($mixedVarId, '/') . '(\[|-)/', $key)) {
                                return false;
                            }
                        }
                    }

                    return true;
                }
            )
        );

        if (!$whileClauses) {
            $whileClauses = [new Clause([], true)];
        }

        $reconcilableWhileTypes = \Psalm\Type\Algebra::getTruthsFromFormula($whileClauses);

        if ($reconcilableWhileTypes) {
            $changedVarIds = [];
            $whileVarsInScopeReconciled =
                Type\Reconciler::reconcileKeyedTypes(
                    $reconcilableWhileTypes,
                    $doContext->varsInScope,
                    $changedVarIds,
                    [],
                    $statementsChecker,
                    new \Psalm\CodeLocation($statementsChecker->getSource(), $stmt->cond),
                    $statementsChecker->getSuppressedIssues()
                );

            $doContext->varsInScope = $whileVarsInScopeReconciled;
        }

        $doCondContext = clone $doContext;

        if (!in_array('RedundantCondition', $suppressedIssues, true)) {
            $statementsChecker->addSuppressedIssues(['RedundantCondition']);
        }
        if (!in_array('RedundantConditionGivenDocblockType', $suppressedIssues, true)) {
            $statementsChecker->addSuppressedIssues(['RedundantConditionGivenDocblockType']);
        }

        ExpressionChecker::analyze($statementsChecker, $stmt->cond, $doCondContext);

        if (!in_array('RedundantCondition', $suppressedIssues, true)) {
            $statementsChecker->removeSuppressedIssues(['RedundantCondition']);
        }
        if (!in_array('RedundantConditionGivenDocblockType', $suppressedIssues, true)) {
            $statementsChecker->removeSuppressedIssues(['RedundantConditionGivenDocblockType']);
        }

        if ($context->collectReferences) {
            $doContext->unreferencedVars = $doCondContext->unreferencedVars;
        }

        foreach ($doCondContext->varsInScope as $varId => $type) {
            if (isset($context->varsInScope[$varId])) {
                $context->varsInScope[$varId] = Type::combineUnionTypes($context->varsInScope[$varId], $type);
            }
        }

        LoopChecker::analyze(
            $statementsChecker,
            $stmt->stmts,
            [$stmt->cond],
            [],
            $loopScope,
            $innerLoopContext,
            true
        );

        foreach ($doContext->varsInScope as $varId => $type) {
            if (!isset($context->varsInScope[$varId])) {
                $context->varsInScope[$varId] = $type;
            }
        }

        // because it's a do {} while, inner loop vars belong to the main context
        if (!$innerLoopContext) {
            throw new \UnexpectedValueException('Should never be null');
        }

        foreach ($innerLoopContext->varsInScope as $varId => $type) {
            if (!isset($context->varsInScope[$varId])) {
                $context->varsInScope[$varId] = $type;
            }
        }

        $context->varsPossiblyInScope = array_merge(
            $context->varsPossiblyInScope,
            $doContext->varsPossiblyInScope
        );

        $context->referencedVarIds = array_merge(
            $context->referencedVarIds,
            $doContext->referencedVarIds
        );

        ExpressionChecker::analyze($statementsChecker, $stmt->cond, $innerLoopContext);

        if ($context->collectReferences) {
            $context->unreferencedVars = $doContext->unreferencedVars;
        }

        if ($context->collectExceptions) {
            $context->possiblyThrownExceptions += $innerLoopContext->possiblyThrownExceptions;
        }
    }
}
