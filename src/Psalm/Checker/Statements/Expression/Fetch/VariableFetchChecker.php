<?php
namespace Psalm\Checker\Statements\Expression\Fetch;

use PhpParser;
use Psalm\Checker\FunctionLikeChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Issue\InvalidScope;
use Psalm\Issue\PossiblyUndefinedGlobalVariable;
use Psalm\Issue\PossiblyUndefinedVariable;
use Psalm\Issue\UndefinedGlobalVariable;
use Psalm\Issue\UndefinedVariable;
use Psalm\IssueBuffer;
use Psalm\Type;

class VariableFetchChecker
{
    /**
     * @param   StatementsChecker               $statementsChecker
     * @param   PhpParser\Node\Expr\Variable    $stmt
     * @param   Context                         $context
     * @param   bool                            $passedByReference
     * @param   Type\Union|null                 $byRefType
     * @param   bool                            $arrayAssignment
     * @param   bool                            $fromGlobal - when used in a global keyword
     *
     * @return  false|null
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\Variable $stmt,
        Context $context,
        $passedByReference = false,
        Type\Union $byRefType = null,
        $arrayAssignment = false,
        $fromGlobal = false
    ) {
        if ($stmt->name === 'this') {
            if ($statementsChecker->isStatic()) {
                if (IssueBuffer::accepts(
                    new InvalidScope(
                        'Invalid reference to $this in a static context',
                        new CodeLocation($statementsChecker->getSource(), $stmt)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }

                return null;
            } elseif (!isset($context->varsInScope['$this'])) {
                if (IssueBuffer::accepts(
                    new InvalidScope(
                        'Invalid reference to $this in a non-class context',
                        new CodeLocation($statementsChecker->getSource(), $stmt)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }

                $context->varsInScope['$this'] = Type::getMixed();
                $context->varsPossiblyInScope['$this'] = true;

                return null;
            }

            $stmt->inferredType = clone $context->varsInScope['$this'];

            return null;
        }

        if (!$context->checkVariables) {
            if (is_string($stmt->name)) {
                $varName = '$' . $stmt->name;

                if (!$context->hasVariable($varName, $statementsChecker)) {
                    $context->varsInScope[$varName] = Type::getMixed();
                    $context->varsPossiblyInScope[$varName] = true;
                    $stmt->inferredType = Type::getMixed();
                } else {
                    $stmt->inferredType = clone $context->varsInScope[$varName];
                }
            } else {
                $stmt->inferredType = Type::getMixed();
            }

            return null;
        }

        if (in_array(
            $stmt->name,
            [
                'GLOBALS',
                '_SERVER',
                '_GET',
                '_POST',
                '_FILES',
                '_COOKIE',
                '_SESSION',
                '_REQUEST',
                '_ENV',
            ],
            true
        )
        ) {
            $stmt->inferredType = Type::getArray();
            $context->varsInScope['$' . $stmt->name] = Type::getArray();
            $context->varsPossiblyInScope['$' . $stmt->name] = true;

            return null;
        }

        if ($context->isGlobal && ($stmt->name === 'argv' || $stmt->name === 'argc')) {
            $varName = '$' . $stmt->name;

            if (!$context->hasVariable($varName, $statementsChecker)) {
                if ($stmt->name === 'argv') {
                    $context->varsInScope[$varName] = new Type\Union([
                        new Type\Atomic\TArray([
                            Type::getInt(),
                            Type::getString(),
                        ]),
                    ]);
                } else {
                    $context->varsInScope[$varName] = Type::getInt();
                }
            }

            $context->varsPossiblyInScope[$varName] = true;
            $stmt->inferredType = clone $context->varsInScope[$varName];
            return null;
        }

        if (!is_string($stmt->name)) {
            return ExpressionChecker::analyze($statementsChecker, $stmt->name, $context);
        }

        if ($passedByReference && $byRefType) {
            ExpressionChecker::assignByRefParam($statementsChecker, $stmt, $byRefType, $context);

            return null;
        }

        $varName = '$' . $stmt->name;

        if (!$context->hasVariable($varName, $statementsChecker)) {
            if (!isset($context->varsPossiblyInScope[$varName]) ||
                !$statementsChecker->getFirstAppearance($varName)
            ) {
                if ($arrayAssignment) {
                    // if we're in an array assignment, let's assign the variable
                    // because PHP allows it

                    $context->varsInScope[$varName] = Type::getArray();
                    $context->varsPossiblyInScope[$varName] = true;

                    // it might have been defined first in another if/else branch
                    if (!$statementsChecker->hasVariable($varName)) {
                        $statementsChecker->registerVariable(
                            $varName,
                            new CodeLocation($statementsChecker, $stmt),
                            $context->branchPoint
                        );
                    }
                } elseif (!$context->insideIsset
                    || $statementsChecker->getSource() instanceof FunctionLikeChecker
                ) {
                    if ($context->isGlobal || $fromGlobal) {
                        if (IssueBuffer::accepts(
                            new UndefinedGlobalVariable(
                                'Cannot find referenced variable ' . $varName . ' in global scope',
                                new CodeLocation($statementsChecker->getSource(), $stmt)
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            return false;
                        }

                        $stmt->inferredType = Type::getMixed();

                        return null;
                    }

                    if (IssueBuffer::accepts(
                        new UndefinedVariable(
                            'Cannot find referenced variable ' . $varName,
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }

                    $stmt->inferredType = Type::getMixed();

                    return false;
                }
            }

            $firstAppearance = $statementsChecker->getFirstAppearance($varName);

            if ($firstAppearance && !$context->insideIsset && !$context->insideUnset) {
                $projectChecker = $statementsChecker->getFileChecker()->projectChecker;

                if ($context->isGlobal) {
                    if ($projectChecker->alterCode) {
                        if (!isset($projectChecker->getIssuesToFix()['PossiblyUndefinedGlobalVariable'])) {
                            return;
                        }

                        $branchPoint = $statementsChecker->getBranchPoint($varName);

                        if ($branchPoint) {
                            $statementsChecker->addVariableInitialization($varName, $branchPoint);
                        }

                        return;
                    }

                    if (IssueBuffer::accepts(
                        new PossiblyUndefinedGlobalVariable(
                            'Possibly undefined global variable ' . $varName . ', first seen on line ' .
                                $firstAppearance->getLineNumber(),
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }
                } else {
                    if ($projectChecker->alterCode) {
                        if (!isset($projectChecker->getIssuesToFix()['PossiblyUndefinedVariable'])) {
                            return;
                        }

                        $branchPoint = $statementsChecker->getBranchPoint($varName);

                        if ($branchPoint) {
                            $statementsChecker->addVariableInitialization($varName, $branchPoint);
                        }

                        return;
                    }

                    if (IssueBuffer::accepts(
                        new PossiblyUndefinedVariable(
                            'Possibly undefined variable ' . $varName . ', first seen on line ' .
                                $firstAppearance->getLineNumber(),
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }
                }

                $statementsChecker->registerVariableUses([$firstAppearance->getHash() => $firstAppearance]);
            }
        } else {
            $stmt->inferredType = clone $context->varsInScope[$varName];
        }

        return null;
    }
}
