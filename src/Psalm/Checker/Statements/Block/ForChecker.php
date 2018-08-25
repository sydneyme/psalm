<?php
namespace Psalm\Checker\Statements\Block;

use PhpParser;
use Psalm\Checker\ScopeChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Context;
use Psalm\Scope\LoopScope;

class ForChecker
{
    /**
     * @param   StatementsChecker           $statementsChecker
     * @param   PhpParser\Node\Stmt\For_    $stmt
     * @param   Context                     $context
     *
     * @return  false|null
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Stmt\For_ $stmt,
        Context $context
    ) {
        $preAssignedVarIds = $context->assignedVarIds;
        $context->assignedVarIds = [];

        foreach ($stmt->init as $init) {
            if (ExpressionChecker::analyze($statementsChecker, $init, $context) === false) {
                return false;
            }
        }

        $assignedVarIds = $context->assignedVarIds;

        $context->assignedVarIds = array_merge(
            $preAssignedVarIds,
            $assignedVarIds
        );

        $whileTrue = !$stmt->cond && !$stmt->init && !$stmt->loop;

        $preContext = $whileTrue ? clone $context : null;

        $forContext = clone $context;

        $forContext->insideLoop = true;

        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;

        if ($projectChecker->alterCode) {
            $forContext->branchPoint = $forContext->branchPoint ?: (int) $stmt->getAttribute('startFilePos');
        }

        $loopScope = new LoopScope($forContext, $context);

        $loopScope->protectedVarIds = array_merge(
            $assignedVarIds,
            $context->protectedVarIds
        );

        LoopChecker::analyze(
            $statementsChecker,
            $stmt->stmts,
            $stmt->cond,
            $stmt->loop,
            $loopScope,
            $innerLoopContext
        );

        if ($innerLoopContext && $whileTrue) {
            // if we actually leave the loop
            if (in_array(ScopeChecker::ACTION_BREAK, $loopScope->finalActions, true)
                || in_array(ScopeChecker::ACTION_END, $loopScope->finalActions, true)
            ) {
                foreach ($innerLoopContext->varsInScope as $varId => $type) {
                    if (!isset($context->varsInScope[$varId])) {
                        $context->varsInScope[$varId] = $type;
                    }
                }
            }
        }

        if (!$whileTrue
            || in_array(ScopeChecker::ACTION_BREAK, $loopScope->finalActions, true)
            || in_array(ScopeChecker::ACTION_END, $loopScope->finalActions, true)
            || !$preContext
        ) {
            $context->varsPossiblyInScope = array_merge(
                $context->varsPossiblyInScope,
                $forContext->varsPossiblyInScope
            );

            $context->possiblyAssignedVarIds =
                $forContext->possiblyAssignedVarIds + $context->possiblyAssignedVarIds;
        } else {
            $context->varsInScope = $preContext->varsInScope;
            $context->varsPossiblyInScope = $preContext->varsPossiblyInScope;
        }

        $context->referencedVarIds =
            $forContext->referencedVarIds + $context->referencedVarIds;

        if ($context->collectReferences) {
            $context->unreferencedVars = array_intersect_key(
                $forContext->unreferencedVars,
                $context->unreferencedVars
            );
        }

        if ($context->collectReferences) {
            $context->unreferencedVars = array_intersect_key(
                $forContext->unreferencedVars,
                $context->unreferencedVars
            );
        }

        if ($context->collectExceptions) {
            $context->possiblyThrownExceptions += $forContext->possiblyThrownExceptions;
        }

        return null;
    }
}
