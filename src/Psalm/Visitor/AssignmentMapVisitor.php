<?php
namespace Psalm\Visitor;

use PhpParser;
use Psalm\Checker\Statements\ExpressionChecker;

class AssignmentMapVisitor extends PhpParser\NodeVisitorAbstract implements PhpParser\NodeVisitor
{
    /**
     * @var array<string, array<string, bool>>
     */
    protected $assignmentMap = [];

    /**
     * @var string|null
     */
    protected $thisClassName;

    /**
     * @param string|null $thisClassName
     */
    public function __construct($thisClassName)
    {
        $this->thisClassName = $thisClassName;
    }

    /**
     * @param  PhpParser\Node $node
     *
     * @return null|int
     */
    public function enterNode(PhpParser\Node $node)
    {
        if ($node instanceof PhpParser\Node\Expr\Assign) {
            $leftVarId = ExpressionChecker::getRootVarId($node->var, $this->thisClassName);
            $rightVarId = ExpressionChecker::getRootVarId($node->expr, $this->thisClassName);

            if ($leftVarId) {
                $this->assignmentMap[$leftVarId][$rightVarId ?: 'isset'] = true;
            }

            return PhpParser\NodeTraverser::DONT_TRAVERSE_CHILDREN;
        } elseif ($node instanceof PhpParser\Node\Expr\PostInc
            || $node instanceof PhpParser\Node\Expr\PostDec
            || $node instanceof PhpParser\Node\Expr\PreInc
            || $node instanceof PhpParser\Node\Expr\PreDec
            || $node instanceof PhpParser\Node\Expr\AssignOp
        ) {
            $varId = ExpressionChecker::getRootVarId($node->var, $this->thisClassName);

            if ($varId) {
                $this->assignmentMap[$varId][$varId] = true;
            }

            return PhpParser\NodeTraverser::DONT_TRAVERSE_CHILDREN;
        } elseif ($node instanceof PhpParser\Node\Expr\FuncCall) {
            foreach ($node->args as $arg) {
                $argVarId = ExpressionChecker::getRootVarId($arg->value, $this->thisClassName);

                if ($argVarId) {
                    $this->assignmentMap[$argVarId][$argVarId] = true;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public function getAssignmentMap()
    {
        return $this->assignmentMap;
    }
}
