<?php
namespace Psalm\Checker\Statements\Block;

use PhpParser;
use Psalm\Checker\ScopeChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Context;
use Psalm\Scope\LoopScope;

class WhileChecker
{
    /**
     * @param   StatementsChecker           $statementsChecker
     * @param   PhpParser\Node\Stmt\While_  $stmt
     * @param   Context                     $context
     *
     * @return  false|null
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Stmt\While_ $stmt,
        Context $context
    ) {
        $whileTrue = ($stmt->cond instanceof PhpParser\Node\Expr\ConstFetch && $stmt->cond->name->parts === ['true'])
            || ($stmt->cond instanceof PhpParser\Node\Scalar\LNumber && $stmt->cond->value > 0);

        $preContext = null;

        if ($whileTrue) {
            $preContext = clone $context;
        }

        $whileContext = clone $context;

        $whileContext->insideLoop = true;

        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;

        if ($projectChecker->alterCode) {
            $whileContext->branchPoint = $whileContext->branchPoint ?: (int) $stmt->getAttribute('startFilePos');
        }

        $loopScope = new LoopScope($whileContext, $context);
        $loopScope->protectedVarIds = $context->protectedVarIds;

        if (LoopChecker::analyze(
            $statementsChecker,
            $stmt->stmts,
            [$stmt->cond],
            [],
            $loopScope,
            $innerLoopContext
        ) === false) {
            return false;
        }

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
                $whileContext->varsPossiblyInScope
            );
        } else {
            $context->varsInScope = $preContext->varsInScope;
            $context->varsPossiblyInScope = $preContext->varsPossiblyInScope;
        }

        $context->referencedVarIds = array_merge(
            $context->referencedVarIds,
            $whileContext->referencedVarIds
        );

        if ($context->collectReferences) {
            $context->unreferencedVars = $whileContext->unreferencedVars;
        }

        return null;
    }
}
