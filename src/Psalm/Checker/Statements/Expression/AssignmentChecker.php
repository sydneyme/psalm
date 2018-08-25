<?php
namespace Psalm\Checker\Statements\Expression;

use PhpParser;
use Psalm\Checker\CommentChecker;
use Psalm\Checker\Statements\Expression\Assignment\ArrayAssignmentChecker;
use Psalm\Checker\Statements\Expression\Assignment\PropertyAssignmentChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Checker\TypeChecker;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Exception\DocblockParseException;
use Psalm\Exception\IncorrectDocblockException;
use Psalm\Issue\AssignmentToVoid;
use Psalm\Issue\InvalidDocblock;
use Psalm\Issue\InvalidScope;
use Psalm\Issue\LoopInvalidation;
use Psalm\Issue\MissingDocblockType;
use Psalm\Issue\MixedAssignment;
use Psalm\Issue\ReferenceConstraintViolation;
use Psalm\IssueBuffer;
use Psalm\Type;

class AssignmentChecker
{
    /**
     * @param  StatementsChecker        $statementsChecker
     * @param  PhpParser\Node\Expr      $assignVar
     * @param  PhpParser\Node\Expr|null $assignValue  This has to be null to support list destructuring
     * @param  Type\Union|null          $assignValueType
     * @param  Context                  $context
     * @param  string                   $docComment
     * @param  int|null                 $cameFromLineNumber
     *
     * @return false|Type\Union
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr $assignVar,
        $assignValue,
        $assignValueType,
        Context $context,
        $docComment,
        $cameFromLineNumber = null
    ) {
        $varId = ExpressionChecker::getVarId(
            $assignVar,
            $statementsChecker->getFQCLN(),
            $statementsChecker
        );

        // gets a variable id that *may* contain array keys
        $arrayVarId = ExpressionChecker::getArrayVarId(
            $assignVar,
            $statementsChecker->getFQCLN(),
            $statementsChecker
        );

        $varComments = [];
        $commentType = null;

        if ($docComment) {
            try {
                $varComments = CommentChecker::getTypeFromComment(
                    $docComment,
                    $statementsChecker->getSource(),
                    $statementsChecker->getAliases(),
                    null,
                    $cameFromLineNumber
                );
            } catch (IncorrectDocblockException $e) {
                if (IssueBuffer::accepts(
                    new MissingDocblockType(
                        (string)$e->getMessage(),
                        new CodeLocation($statementsChecker->getSource(), $assignVar)
                    )
                )) {
                    // fall through
                }
            } catch (DocblockParseException $e) {
                if (IssueBuffer::accepts(
                    new InvalidDocblock(
                        (string)$e->getMessage(),
                        new CodeLocation($statementsChecker->getSource(), $assignVar)
                    )
                )) {
                    // fall through
                }
            }

            foreach ($varComments as $varComment) {
                try {
                    $varCommentType = ExpressionChecker::fleshOutType(
                        $statementsChecker->getFileChecker()->projectChecker,
                        $varComment->type,
                        $context->self,
                        $context->self
                    );

                    $varCommentType->setFromDocblock();

                    if (!$varComment->varId || $varComment->varId === $varId) {
                        $commentType = $varCommentType;
                        continue;
                    }

                    $context->varsInScope[$varComment->varId] = $varCommentType;
                } catch (\UnexpectedValueException $e) {
                    if (IssueBuffer::accepts(
                        new InvalidDocblock(
                            (string)$e->getMessage(),
                            new CodeLocation($statementsChecker->getSource(), $assignVar)
                        )
                    )) {
                        // fall through
                    }
                }
            }
        }

        if ($assignValue && ExpressionChecker::analyze($statementsChecker, $assignValue, $context) === false) {
            if ($varId) {
                if ($arrayVarId) {
                    $context->removeDescendents($arrayVarId, null, $assignValueType);
                }

                // if we're not exiting immediately, make everything mixed
                $context->varsInScope[$varId] = $commentType ?: Type::getMixed();
            }

            return false;
        }

        if ($commentType) {
            $assignValueType = $commentType;
        } elseif (!$assignValueType) {
            if (isset($assignValue->inferredType)) {
                $assignValueType = $assignValue->inferredType;
            } else {
                $assignValueType = Type::getMixed();
            }
        }

        if ($arrayVarId && isset($context->varsInScope[$arrayVarId])) {
            // removes dependennt vars from $context
            $context->removeDescendents(
                $arrayVarId,
                $context->varsInScope[$arrayVarId],
                $assignValueType,
                $statementsChecker
            );
        } else {
            $rootVarId = ExpressionChecker::getRootVarId(
                $assignVar,
                $statementsChecker->getFQCLN(),
                $statementsChecker
            );

            if ($rootVarId && isset($context->varsInScope[$rootVarId])) {
                $context->removeVarFromConflictingClauses(
                    $rootVarId,
                    $context->varsInScope[$rootVarId],
                    $statementsChecker
                );
            }
        }

        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;

        if ($assignValueType->isMixed()) {
            $codebase->analyzer->incrementMixedCount($statementsChecker->getFilePath());

            if (!$assignVar instanceof PhpParser\Node\Expr\PropertyFetch) {
                if (IssueBuffer::accepts(
                    new MixedAssignment(
                        'Cannot assign ' . $varId . ' to a mixed type',
                        new CodeLocation($statementsChecker->getSource(), $assignVar)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    // fall through
                }
            }
        } else {
            $codebase->analyzer->incrementNonMixedCount($statementsChecker->getFilePath());

            if ($varId
                && isset($context->byrefConstraints[$varId])
                && ($outerConstraintType = $context->byrefConstraints[$varId]->type)
            ) {
                if (!TypeChecker::isContainedBy(
                    $codebase,
                    $assignValueType,
                    $outerConstraintType,
                    $assignValueType->ignoreNullableIssues,
                    $assignValueType->ignoreFalsableIssues
                )
                ) {
                    if (IssueBuffer::accepts(
                        new ReferenceConstraintViolation(
                            'Variable ' . $varId . ' is limited to values of type '
                                . $context->byrefConstraints[$varId]->type
                                . ' because it is passed by reference',
                            new CodeLocation($statementsChecker->getSource(), $assignVar)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }
            }
        }

        if ($varId === '$this' && IssueBuffer::accepts(
            new InvalidScope(
                'Cannot re-assign ' . $varId,
                new CodeLocation($statementsChecker->getSource(), $assignVar)
            ),
            $statementsChecker->getSuppressedIssues()
        )) {
            return false;
        }

        if (isset($context->protectedVarIds[$varId])) {
            if (IssueBuffer::accepts(
                new LoopInvalidation(
                    'Variable ' . $varId . ' has already been assigned in a for/foreach loop',
                    new CodeLocation($statementsChecker->getSource(), $assignVar)
                ),
                $statementsChecker->getSuppressedIssues()
            )) {
                // fall through
            }
        }

        if ($assignVar instanceof PhpParser\Node\Expr\Variable && is_string($assignVar->name) && $varId) {
            $context->varsInScope[$varId] = $assignValueType;
            $context->varsPossiblyInScope[$varId] = true;
            $context->assignedVarIds[$varId] = true;
            $context->possiblyAssignedVarIds[$varId] = true;

            $location = new CodeLocation($statementsChecker, $assignVar);

            if ($context->collectReferences) {
                $context->unreferencedVars[$varId] = [$location->getHash() => $location];
            }

            if (!$statementsChecker->hasVariable($varId)) {
                $statementsChecker->registerVariable(
                    $varId,
                    $location,
                    $context->branchPoint
                );
            } else {
                $statementsChecker->registerVariableAssignment(
                    $varId,
                    $location
                );
            }

            if (isset($context->byrefConstraints[$varId])) {
                $statementsChecker->registerVariableUses([$location->getHash() => $location]);
            }
        } elseif ($assignVar instanceof PhpParser\Node\Expr\List_
            || $assignVar instanceof PhpParser\Node\Expr\Array_
        ) {
            /** @var int $offset */
            foreach ($assignVar->items as $offset => $assignVarItem) {
                // $assignVarItem can be null e.g. list($a, ) = ['a', 'b']
                if (!$assignVarItem) {
                    continue;
                }

                $var = $assignVarItem->value;

                if ($assignValue instanceof PhpParser\Node\Expr\Array_
                    && isset($assignVarItem->value->inferredType)
                ) {
                    self::analyze(
                        $statementsChecker,
                        $var,
                        $assignVarItem->value,
                        null,
                        $context,
                        $docComment
                    );

                    continue;
                }

                if (isset($assignValueType->getTypes()['array'])
                    && ($arrayAtomicType = $assignValueType->getTypes()['array'])
                    && $arrayAtomicType instanceof Type\Atomic\ObjectLike
                    && !$assignVarItem->key
                    && isset($arrayAtomicType->properties[$offset]) // if object-like has int offsets
                ) {
                    self::analyze(
                        $statementsChecker,
                        $var,
                        null,
                        $arrayAtomicType->properties[(string)$offset],
                        $context,
                        $docComment
                    );

                    continue;
                }

                if ($var instanceof PhpParser\Node\Expr\List_
                    || $var instanceof PhpParser\Node\Expr\Array_
                ) {
                    /** @var Type\Atomic\ObjectLike|Type\Atomic\TArray|null */
                    $arrayValueType = isset($assignValueType->getTypes()['array'])
                        ? $assignValueType->getTypes()['array']
                        : null;

                    if ($arrayValueType instanceof Type\Atomic\ObjectLike) {
                        $arrayValueType = $arrayValueType->getGenericArrayType();
                    }

                    self::analyze(
                        $statementsChecker,
                        $var,
                        null,
                        $arrayValueType ? clone $arrayValueType->typeParams[1] : Type::getMixed(),
                        $context,
                        $docComment
                    );
                }

                $listVarId = ExpressionChecker::getArrayVarId(
                    $var,
                    $statementsChecker->getFQCLN(),
                    $statementsChecker
                );

                if ($listVarId) {
                    $context->varsPossiblyInScope[$listVarId] = true;
                    $context->assignedVarIds[$listVarId] = true;
                    $context->possiblyAssignedVarIds[$listVarId] = true;

                    $alreadyInScope = isset($context->varsInScope[$varId]);

                    if (strpos($listVarId, '-') === false && strpos($listVarId, '[') === false) {
                        $location = new CodeLocation($statementsChecker, $var);

                        if ($context->collectReferences) {
                            $context->unreferencedVars[$listVarId] = [$location->getHash() => $location];
                        }

                        if (!$statementsChecker->hasVariable($listVarId)) {
                            $statementsChecker->registerVariable(
                                $listVarId,
                                $location,
                                $context->branchPoint
                            );
                        } else {
                            $statementsChecker->registerVariableAssignment(
                                $listVarId,
                                $location
                            );
                        }

                        if (isset($context->byrefConstraints[$listVarId])) {
                            $statementsChecker->registerVariableUses([$location->getHash() => $location]);
                        }
                    }

                    $newAssignType = null;

                    if (isset($assignValueType->getTypes()['array'])) {
                        $arrayAtomicType = $assignValueType->getTypes()['array'];

                        if ($arrayAtomicType instanceof Type\Atomic\TArray) {
                            $newAssignType = clone $arrayAtomicType->typeParams[1];
                        } elseif ($arrayAtomicType instanceof Type\Atomic\ObjectLike) {
                            if ($assignVarItem->key
                                && ($assignVarItem->key instanceof PhpParser\Node\Scalar\String_
                                    || $assignVarItem->key instanceof PhpParser\Node\Scalar\LNumber)
                                && isset($arrayAtomicType->properties[$assignVarItem->key->value])
                            ) {
                                $newAssignType =
                                    clone $arrayAtomicType->properties[$assignVarItem->key->value];
                            }
                        }
                    }

                    if ($alreadyInScope) {
                        // removes dependennt vars from $context
                        $context->removeDescendents(
                            $listVarId,
                            $context->varsInScope[$listVarId],
                            $newAssignType,
                            $statementsChecker
                        );
                    }

                    foreach ($varComments as $varComment) {
                        try {
                            if ($varComment->varId === $listVarId) {
                                $varCommentType = ExpressionChecker::fleshOutType(
                                    $statementsChecker->getFileChecker()->projectChecker,
                                    $varComment->type,
                                    $context->self,
                                    $context->self
                                );

                                $varCommentType->setFromDocblock();

                                $newAssignType = $varCommentType;
                                break;
                            }
                        } catch (\UnexpectedValueException $e) {
                            if (IssueBuffer::accepts(
                                new InvalidDocblock(
                                    (string)$e->getMessage(),
                                    new CodeLocation($statementsChecker->getSource(), $assignVar)
                                )
                            )) {
                                // fall through
                            }
                        }
                    }

                    $context->varsInScope[$listVarId] = $newAssignType ?: Type::getMixed();
                }
            }
        } elseif ($assignVar instanceof PhpParser\Node\Expr\ArrayDimFetch) {
            ArrayAssignmentChecker::analyze(
                $statementsChecker,
                $assignVar,
                $context,
                $assignValueType
            );
        } elseif ($assignVar instanceof PhpParser\Node\Expr\PropertyFetch) {
            if (!$assignVar->name instanceof PhpParser\Node\Identifier) {
                if (ExpressionChecker::analyze($statementsChecker, $assignVar->name, $context) === false) {
                    return false;
                }
            }

            if ($assignVar->name instanceof PhpParser\Node\Identifier) {
                $propName = $assignVar->name->name;
            } elseif (isset($assignVar->name->inferredType)
                && $assignVar->name->inferredType->isSingleStringLiteral()
            ) {
                $propName = $assignVar->name->inferredType->getSingleStringLiteral()->value;
            } else {
                $propName = null;
            }

            if ($propName) {
                PropertyAssignmentChecker::analyzeInstance(
                    $statementsChecker,
                    $assignVar,
                    $propName,
                    $assignValue,
                    $assignValueType,
                    $context
                );
            } else {
                if (ExpressionChecker::analyze($statementsChecker, $assignVar->var, $context) === false) {
                    return false;
                }
            }

            if ($varId) {
                $context->varsPossiblyInScope[$varId] = true;
            }
        } elseif ($assignVar instanceof PhpParser\Node\Expr\StaticPropertyFetch &&
            $assignVar->class instanceof PhpParser\Node\Name
        ) {
            if (ExpressionChecker::analyze($statementsChecker, $assignVar, $context) === false) {
                return false;
            }

            if ($context->checkClasses) {
                PropertyAssignmentChecker::analyzeStatic(
                    $statementsChecker,
                    $assignVar,
                    $assignValue,
                    $assignValueType,
                    $context
                );
            }

            if ($varId) {
                $context->varsPossiblyInScope[$varId] = true;
            }
        }

        if ($varId && isset($context->varsInScope[$varId]) && $context->varsInScope[$varId]->isVoid()) {
            if (IssueBuffer::accepts(
                new AssignmentToVoid(
                    'Cannot assign ' . $varId . ' to type void',
                    new CodeLocation($statementsChecker->getSource(), $assignVar)
                ),
                $statementsChecker->getSuppressedIssues()
            )) {
                return false;
            }

            $context->varsInScope[$varId] = Type::getMixed();

            return Type::getMixed();
        }

        return $assignValueType;
    }

    /**
     * @param   StatementsChecker               $statementsChecker
     * @param   PhpParser\Node\Expr\AssignOp    $stmt
     * @param   Context                         $context
     *
     * @return  false|null
     */
    public static function analyzeAssignmentOperation(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\AssignOp $stmt,
        Context $context
    ) {
        if (ExpressionChecker::analyze($statementsChecker, $stmt->var, $context) === false) {
            return false;
        }

        if (ExpressionChecker::analyze($statementsChecker, $stmt->expr, $context) === false) {
            return false;
        }

        $arrayVarId = ExpressionChecker::getArrayVarId(
            $stmt->var,
            $statementsChecker->getFQCLN(),
            $statementsChecker
        );

        if ($arrayVarId && $context->collectReferences && $stmt->var instanceof PhpParser\Node\Expr\Variable) {
            $location = new CodeLocation($statementsChecker, $stmt->var);
            $context->assignedVarIds[$arrayVarId] = true;
            $context->possiblyAssignedVarIds[$arrayVarId] = true;
            $statementsChecker->registerVariableAssignment(
                $arrayVarId,
                $location
            );
            $context->unreferencedVars[$arrayVarId] = [$location->getHash() => $location];
        }

        $varType = isset($stmt->var->inferredType) ? clone $stmt->var->inferredType : null;
        $exprType = isset($stmt->expr->inferredType) ? $stmt->expr->inferredType : null;

        if ($stmt instanceof PhpParser\Node\Expr\AssignOp\Plus ||
            $stmt instanceof PhpParser\Node\Expr\AssignOp\Minus ||
            $stmt instanceof PhpParser\Node\Expr\AssignOp\Mod ||
            $stmt instanceof PhpParser\Node\Expr\AssignOp\Mul ||
            $stmt instanceof PhpParser\Node\Expr\AssignOp\Pow
        ) {
            BinaryOpChecker::analyzeNonDivArithmenticOp(
                $statementsChecker,
                $stmt->var,
                $stmt->expr,
                $stmt,
                $resultType,
                $context
            );

            if ($stmt->var instanceof PhpParser\Node\Expr\ArrayDimFetch) {
                ArrayAssignmentChecker::analyze(
                    $statementsChecker,
                    $stmt->var,
                    $context,
                    $resultType ?: Type::getMixed(true)
                );
            } elseif ($resultType && $arrayVarId) {
                $context->varsInScope[$arrayVarId] = $resultType;
                $stmt->inferredType = clone $context->varsInScope[$arrayVarId];
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\AssignOp\Div
            && $varType
            && $exprType
            && $varType->hasDefinitelyNumericType()
            && $exprType->hasDefinitelyNumericType()
            && $arrayVarId
        ) {
            $context->varsInScope[$arrayVarId] = Type::combineUnionTypes(Type::getFloat(), Type::getInt());
            $stmt->inferredType = clone $context->varsInScope[$arrayVarId];
        } elseif ($stmt instanceof PhpParser\Node\Expr\AssignOp\Concat) {
            BinaryOpChecker::analyzeConcatOp(
                $statementsChecker,
                $stmt->var,
                $stmt->expr,
                $context,
                $resultType
            );

            if ($resultType && $arrayVarId) {
                $context->varsInScope[$arrayVarId] = $resultType;
                $stmt->inferredType = clone $context->varsInScope[$arrayVarId];
            }
        }

        return null;
    }

    /**
     * @param   StatementsChecker               $statementsChecker
     * @param   PhpParser\Node\Expr\AssignRef   $stmt
     * @param   Context                         $context
     *
     * @return  false|null
     */
    public static function analyzeAssignmentRef(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\AssignRef $stmt,
        Context $context
    ) {
        if (self::analyze(
            $statementsChecker,
            $stmt->var,
            $stmt->expr,
            null,
            $context,
            (string)$stmt->getDocComment()
        ) === false) {
            return false;
        }

        $lhsVarId = ExpressionChecker::getVarId(
            $stmt->var,
            $statementsChecker->getFQCLN(),
            $statementsChecker
        );

        $rhsVarId = ExpressionChecker::getVarId(
            $stmt->expr,
            $statementsChecker->getFQCLN(),
            $statementsChecker
        );

        if ($lhsVarId) {
            $context->varsInScope[$lhsVarId] = Type::getMixed();
        }

        if ($rhsVarId) {
            $context->varsInScope[$rhsVarId] = Type::getMixed();
        }
    }
}
