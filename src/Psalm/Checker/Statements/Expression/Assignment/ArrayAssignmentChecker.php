<?php
namespace Psalm\Checker\Statements\Expression\Assignment;

use PhpParser;
use Psalm\Checker\Statements\Expression\Fetch\ArrayFetchChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Context;
use Psalm\Type;
use Psalm\Type\Atomic\ObjectLike;
use Psalm\Type\Atomic\TArray;

class ArrayAssignmentChecker
{
    /**
     * @param   StatementsChecker                   $statementsChecker
     * @param   PhpParser\Node\Expr\ArrayDimFetch   $stmt
     * @param   Context                             $context
     * @param   Type\Union                          $assignmentValueType
     *
     * @return  void
     * @psalm-suppress MixedMethodCall - some funky logic here
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\ArrayDimFetch $stmt,
        Context $context,
        Type\Union $assignmentValueType
    ) {
        $nesting = 0;
        $varId = ExpressionChecker::getVarId(
            $stmt->var,
            $statementsChecker->getFQCLN(),
            $statementsChecker,
            $nesting
        );

        self::updateArrayType(
            $statementsChecker,
            $stmt,
            $assignmentValueType,
            $context
        );

        if (!isset($stmt->var->inferredType) && $varId) {
            $context->varsInScope[$varId] = Type::getMixed();
        }
    }

    /**
     * @param  StatementsChecker                 $statementsChecker
     * @param  PhpParser\Node\Expr\ArrayDimFetch $stmt
     * @param  Type\Union                        $assignmentType
     * @param  Context                           $context
     *
     * @return false|null
     *
     * @psalm-suppress UnusedVariable due to Psalm bug
     */
    public static function updateArrayType(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\ArrayDimFetch $stmt,
        Type\Union $assignmentType,
        Context $context
    ) {
        $rootArrayExpr = $stmt;

        $childStmts = [];

        while ($rootArrayExpr->var instanceof PhpParser\Node\Expr\ArrayDimFetch) {
            $childStmts[] = $rootArrayExpr;
            $rootArrayExpr = $rootArrayExpr->var;
        }

        $childStmts[] = $rootArrayExpr;
        $rootArrayExpr = $rootArrayExpr->var;

        if (ExpressionChecker::analyze(
            $statementsChecker,
            $rootArrayExpr,
            $context,
            true
        ) === false) {
            // fall through
        }

        $rootType = isset($rootArrayExpr->inferredType) ? $rootArrayExpr->inferredType : Type::getMixed();

        if ($rootType->isMixed()) {
            return null;
        }

        $childStmts = array_reverse($childStmts);

        $currentType = $rootType;

        $currentDim = $stmt->dim;

        $reversedChildStmts = [];

        // gets a variable id that *may* contain array keys
        $rootVarId = ExpressionChecker::getRootVarId(
            $rootArrayExpr,
            $statementsChecker->getFQCLN(),
            $statementsChecker
        );

        $varIdAdditions = [];

        $parentVarId = null;

        $fullVarId = true;

        $childStmt = null;

        // First go from the root element up, and go as far as we can to figure out what
        // array types there are
        while ($childStmts) {
            $childStmt = array_shift($childStmts);

            if (count($childStmts)) {
                array_unshift($reversedChildStmts, $childStmt);
            }

            if ($childStmt->dim) {
                if (ExpressionChecker::analyze(
                    $statementsChecker,
                    $childStmt->dim,
                    $context
                ) === false) {
                    return false;
                }

                if (!isset($childStmt->dim->inferredType)) {
                    return null;
                }

                if ($childStmt->dim instanceof PhpParser\Node\Scalar\String_
                    || ($childStmt->dim instanceof PhpParser\Node\Expr\ConstFetch
                       && $childStmt->dim->inferredType->isSingleStringLiteral())
                ) {
                    if ($childStmt->dim instanceof PhpParser\Node\Scalar\String_) {
                        $value = $childStmt->dim->value;
                    } else {
                        $value = $childStmt->dim->inferredType->getSingleStringLiteral()->value;
                    }

                    if (preg_match('/^(0|[1-9][0-9]*)$/', $value)) {
                        $varIdAdditions[] = '[' . $value . ']';
                    }
                    $varIdAdditions[] = '[\'' . $value . '\']';
                } elseif ($childStmt->dim instanceof PhpParser\Node\Scalar\LNumber
                    || ($childStmt->dim instanceof PhpParser\Node\Expr\ConstFetch
                        && $childStmt->dim->inferredType->isSingleIntLiteral())
                ) {
                    if ($childStmt->dim instanceof PhpParser\Node\Scalar\LNumber) {
                        $value = $childStmt->dim->value;
                    } else {
                        $value = $childStmt->dim->inferredType->getSingleIntLiteral()->value;
                    }

                    $varIdAdditions[] = '[' . $value . ']';
                } elseif ($childStmt->dim instanceof PhpParser\Node\Expr\Variable
                    && is_string($childStmt->dim->name)
                ) {
                    $varIdAdditions[] = '[$' . $childStmt->dim->name . ']';
                } else {
                    $varIdAdditions[] = '[' . $childStmt->dim->inferredType . ']';
                    $fullVarId = false;
                }
            } else {
                $varIdAdditions[] = '';
                $fullVarId = false;
            }

            if (!isset($childStmt->var->inferredType)) {
                return null;
            }

            if ($childStmt->var->inferredType->isEmpty()) {
                $childStmt->var->inferredType = Type::getEmptyArray();
            }

            $arrayVarId = $rootVarId . implode('', $varIdAdditions);

            if ($parentVarId && isset($context->varsInScope[$parentVarId])) {
                $childStmt->var->inferredType = clone $context->varsInScope[$parentVarId];
            }

            $parentVarId = $arrayVarId;

            $childStmt->inferredType = ArrayFetchChecker::getArrayAccessTypeGivenOffset(
                $statementsChecker,
                $childStmt,
                $childStmt->var->inferredType,
                isset($childStmt->dim->inferredType) ? $childStmt->dim->inferredType : Type::getInt(),
                true,
                $arrayVarId,
                $childStmts ? null : $assignmentType
            );

            if (!$childStmts) {
                $childStmt->inferredType = $assignmentType;
            }

            $currentType = $childStmt->inferredType;
            $currentDim = $childStmt->dim;

            if ($childStmt->var->inferredType->isMixed()) {
                $fullVarId = false;
                break;
            }
        }

        if ($rootVarId
            && $fullVarId
            && isset($childStmt->var->inferredType)
            && !$childStmt->var->inferredType->hasObjectType()
        ) {
            $arrayVarId = $rootVarId . implode('', $varIdAdditions);
            $context->varsInScope[$arrayVarId] = clone $assignmentType;
        }

        // only update as many child stmts are we were able to process above
        foreach ($reversedChildStmts as $childStmt) {
            if (!isset($childStmt->inferredType)) {
                throw new \InvalidArgumentException('Should never get here');
            }

            $isSingleStringLiteral = false;

            if ($currentDim instanceof PhpParser\Node\Scalar\String_
                || $currentDim instanceof PhpParser\Node\Scalar\LNumber
                || ($currentDim instanceof PhpParser\Node\Expr\ConstFetch
                    && isset($currentDim->inferredType)
                    && (($isSingleStringLiteral = $currentDim->inferredType->isSingleStringLiteral())
                        || $currentDim->inferredType->isSingleIntLiteral()))
            ) {
                if ($currentDim instanceof PhpParser\Node\Scalar\String_
                    || $currentDim instanceof PhpParser\Node\Scalar\LNumber
                ) {
                    $keyValue = $currentDim->value;
                } elseif ($isSingleStringLiteral) {
                    $keyValue = $currentDim->inferredType->getSingleStringLiteral()->value;
                } else {
                    $keyValue = $currentDim->inferredType->getSingleIntLiteral()->value;
                }

                $hasMatchingObjectlikeProperty = false;

                foreach ($childStmt->inferredType->getTypes() as $type) {
                    if ($type instanceof ObjectLike) {
                        if (isset($type->properties[$keyValue])) {
                            $hasMatchingObjectlikeProperty = true;

                            $type->properties[$keyValue] = clone $currentType;
                        }
                    }
                }

                if (!$hasMatchingObjectlikeProperty) {
                    $arrayAssignmentType = new Type\Union([
                        new ObjectLike([$keyValue => $currentType]),
                    ]);

                    $newChildType = Type::combineUnionTypes(
                        $childStmt->inferredType,
                        $arrayAssignmentType
                    );
                } else {
                    $newChildType = $childStmt->inferredType; // noop
                }
            } else {
                $arrayAssignmentType = new Type\Union([
                    new TArray([
                        isset($currentDim->inferredType) ? $currentDim->inferredType : Type::getInt(),
                        $currentType,
                    ]),
                ]);

                $newChildType = Type::combineUnionTypes(
                    $childStmt->inferredType,
                    $arrayAssignmentType
                );
            }

            $newChildType->removeType('null');
            $newChildType->possiblyUndefined = false;

            if (!$childStmt->inferredType->hasObjectType()) {
                $childStmt->inferredType = $newChildType;
            }

            $currentType = $childStmt->inferredType;
            $currentDim = $childStmt->dim;

            array_pop($varIdAdditions);

            if ($rootVarId) {
                $arrayVarId = $rootVarId . implode('', $varIdAdditions);
                $context->varsInScope[$arrayVarId] = clone $childStmt->inferredType;
            }
        }

        $rootIsString = $rootType->isString();
        $isSingleStringLiteral = false;

        if (($currentDim instanceof PhpParser\Node\Scalar\String_
                || $currentDim instanceof PhpParser\Node\Scalar\LNumber
                || ($currentDim instanceof PhpParser\Node\Expr\ConstFetch
                    && isset($currentDim->inferredType)
                    && (($isSingleStringLiteral = $currentDim->inferredType->isSingleStringLiteral())
                        || $currentDim->inferredType->isSingleIntLiteral())))
            && ($currentDim instanceof PhpParser\Node\Scalar\String_
                || !$rootIsString)
        ) {
            if ($currentDim instanceof PhpParser\Node\Scalar\String_
                || $currentDim instanceof PhpParser\Node\Scalar\LNumber
            ) {
                $keyValue = $currentDim->value;
            } elseif ($isSingleStringLiteral) {
                $keyValue = $currentDim->inferredType->getSingleStringLiteral()->value;
            } else {
                $keyValue = $currentDim->inferredType->getSingleIntLiteral()->value;
            }

            $hasMatchingObjectlikeProperty = false;

            foreach ($rootType->getTypes() as $type) {
                if ($type instanceof ObjectLike) {
                    if (isset($type->properties[$keyValue])) {
                        $hasMatchingObjectlikeProperty = true;

                        $type->properties[$keyValue] = clone $currentType;
                    }
                }
            }

            if (!$hasMatchingObjectlikeProperty) {
                $arrayAssignmentType = new Type\Union([
                    new ObjectLike([$keyValue => $currentType]),
                ]);

                $newChildType = Type::combineUnionTypes(
                    $rootType,
                    $arrayAssignmentType
                );
            } else {
                $newChildType = $rootType; // noop
            }
        } elseif (!$rootIsString) {
            if ($currentDim) {
                if (isset($currentDim->inferredType)) {
                    $arrayAtomicKeyType = ArrayFetchChecker::replaceOffsetTypeWithInts(
                        $currentDim->inferredType
                    );
                } else {
                    $arrayAtomicKeyType = Type::getMixed();
                }
            } else {
                // todo: this can be improved I think
                $arrayAtomicKeyType = Type::getInt();
            }

            $arrayAtomicType = new TArray([
                $arrayAtomicKeyType,
                $currentType,
            ]);

            $fromCountableObjectLike = false;

            if (!$currentDim && !$context->insideLoop) {
                $atomicRootTypes = $rootType->getTypes();

                if (isset($atomicRootTypes['array'])) {
                    if ($atomicRootTypes['array'] instanceof TArray) {
                        $arrayAtomicType->count = $atomicRootTypes['array']->count;
                    } elseif ($atomicRootTypes['array'] instanceof ObjectLike
                        && $atomicRootTypes['array']->sealed
                    ) {
                        $arrayAtomicType->count = count($atomicRootTypes['array']->properties);
                        $fromCountableObjectLike = true;
                    }
                }
            }

            $arrayAssignmentType = new Type\Union([
                $arrayAtomicType,
            ]);

            $newChildType = Type::combineUnionTypes(
                $rootType,
                $arrayAssignmentType
            );

            if ($fromCountableObjectLike) {
                $atomicRootTypes = $newChildType->getTypes();

                if (isset($atomicRootTypes['array'])
                    && $atomicRootTypes['array'] instanceof TArray
                    && $atomicRootTypes['array']->count !== null
                ) {
                    $atomicRootTypes['array']->count++;
                }
            }
        } else {
            $newChildType = $rootType;
        }

        $newChildType->removeType('null');

        if (!$rootType->hasObjectType()) {
            $rootType = $newChildType;
        }

        $rootArrayExpr->inferredType = $rootType;

        if ($rootArrayExpr instanceof PhpParser\Node\Expr\PropertyFetch) {
            if ($rootArrayExpr->name instanceof PhpParser\Node\Identifier) {
                PropertyAssignmentChecker::analyzeInstance(
                    $statementsChecker,
                    $rootArrayExpr,
                    $rootArrayExpr->name->name,
                    null,
                    $rootType,
                    $context,
                    false
                );
            } else {
                if (ExpressionChecker::analyze($statementsChecker, $rootArrayExpr->name, $context) === false) {
                    return false;
                }

                if (ExpressionChecker::analyze($statementsChecker, $rootArrayExpr->var, $context) === false) {
                    return false;
                }
            }
        } elseif ($rootVarId) {
            if ($context->hasVariable($rootVarId, $statementsChecker)) {
                $context->varsInScope[$rootVarId] = $rootType;
            } else {
                $context->varsInScope[$rootVarId] = $rootType;
            }
        }

        return null;
    }
}
