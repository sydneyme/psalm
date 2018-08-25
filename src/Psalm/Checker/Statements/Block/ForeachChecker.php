<?php
namespace Psalm\Checker\Statements\Block;

use PhpParser;
use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\CommentChecker;
use Psalm\Checker\Statements\Expression\AssignmentChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Exception\DocblockParseException;
use Psalm\Issue\InvalidDocblock;
use Psalm\Issue\InvalidIterator;
use Psalm\Issue\NullIterator;
use Psalm\Issue\PossiblyFalseIterator;
use Psalm\Issue\PossiblyInvalidIterator;
use Psalm\Issue\PossiblyNullIterator;
use Psalm\Issue\RawObjectIteration;
use Psalm\IssueBuffer;
use Psalm\Scope\LoopScope;
use Psalm\Type;

class ForeachChecker
{
    /**
     * @param   StatementsChecker               $statementsChecker
     * @param   PhpParser\Node\Stmt\Foreach_    $stmt
     * @param   Context                         $context
     *
     * @return  false|null
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Stmt\Foreach_ $stmt,
        Context $context
    ) {
        if (ExpressionChecker::analyze($statementsChecker, $stmt->expr, $context) === false) {
            return false;
        }

        $foreachContext = clone $context;

        $foreachContext->insideLoop = true;

        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;

        if ($projectChecker->alterCode) {
            $foreachContext->branchPoint =
                $foreachContext->branchPoint ?: (int) $stmt->getAttribute('startFilePos');
        }

        $keyType = null;
        $valueType = null;

        $varId = ExpressionChecker::getVarId(
            $stmt->expr,
            $statementsChecker->getFQCLN(),
            $statementsChecker
        );

        if (isset($stmt->expr->inferredType)) {
            $iteratorType = $stmt->expr->inferredType;
        } elseif ($varId && $foreachContext->hasVariable($varId, $statementsChecker)) {
            $iteratorType = $foreachContext->varsInScope[$varId];
        } else {
            $iteratorType = null;
        }

        if ($iteratorType) {
            if ($iteratorType->isNull()) {
                if (IssueBuffer::accepts(
                    new NullIterator(
                        'Cannot iterate over null',
                        new CodeLocation($statementsChecker->getSource(), $stmt->expr)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }
            } elseif ($iteratorType->isNullable() && !$iteratorType->ignoreNullableIssues) {
                if (IssueBuffer::accepts(
                    new PossiblyNullIterator(
                        'Cannot iterate over nullable var ' . $iteratorType,
                        new CodeLocation($statementsChecker->getSource(), $stmt->expr)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }
            } elseif ($iteratorType->isFalsable() && !$iteratorType->ignoreFalsableIssues) {
                if (IssueBuffer::accepts(
                    new PossiblyFalseIterator(
                        'Cannot iterate over falsable var ' . $iteratorType,
                        new CodeLocation($statementsChecker->getSource(), $stmt->expr)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }
            }

            $hasValidIterator = false;
            $invalidIteratorTypes = [];

            foreach ($iteratorType->getTypes() as $iteratorType) {
                // if it's an empty array, we cannot iterate over it
                if ($iteratorType instanceof Type\Atomic\TArray
                    && $iteratorType->typeParams[1]->isEmpty()
                ) {
                    $hasValidIterator = true;
                    continue;
                }

                if ($iteratorType instanceof Type\Atomic\TNull || $iteratorType instanceof Type\Atomic\TFalse) {
                    continue;
                }

                if ($iteratorType instanceof Type\Atomic\TArray
                    || $iteratorType instanceof Type\Atomic\ObjectLike
                ) {
                    if ($iteratorType instanceof Type\Atomic\ObjectLike) {
                        $iteratorType = $iteratorType->getGenericArrayType();
                    }

                    if (!$valueType) {
                        $valueType = $iteratorType->typeParams[1];
                    } else {
                        $valueType = Type::combineUnionTypes($valueType, $iteratorType->typeParams[1]);
                    }

                    $keyTypePart = $iteratorType->typeParams[0];

                    if (!$keyType) {
                        $keyType = $keyTypePart;
                    } else {
                        $keyType = Type::combineUnionTypes($keyType, $keyTypePart);
                    }

                    $hasValidIterator = true;
                    continue;
                }

                if ($iteratorType instanceof Type\Atomic\Scalar ||
                    $iteratorType instanceof Type\Atomic\TVoid
                ) {
                    $invalidIteratorTypes[] = $iteratorType->getKey();

                    $valueType = Type::getMixed();
                } elseif ($iteratorType instanceof Type\Atomic\TObject ||
                    $iteratorType instanceof Type\Atomic\TMixed ||
                    $iteratorType instanceof Type\Atomic\TEmpty
                ) {
                    $hasValidIterator = true;
                    $valueType = Type::getMixed();
                } elseif ($iteratorType instanceof Type\Atomic\TNamedObject) {
                    if ($iteratorType->value !== 'Traversable' &&
                        $iteratorType->value !== $statementsChecker->getClassName()
                    ) {
                        if (ClassLikeChecker::checkFullyQualifiedClassLikeName(
                            $statementsChecker,
                            $iteratorType->value,
                            new CodeLocation($statementsChecker->getSource(), $stmt->expr),
                            $statementsChecker->getSuppressedIssues()
                        ) === false) {
                            return false;
                        }
                    }

                    $hasValidIterator = true;

                    if ($iteratorType instanceof Type\Atomic\TGenericObject &&
                        (strtolower($iteratorType->value) === 'iterable' ||
                            strtolower($iteratorType->value) === 'traversable' ||
                            $codebase->classImplements(
                                $iteratorType->value,
                                'Traversable'
                            ))
                    ) {
                        $valueIndex = count($iteratorType->typeParams) - 1;
                        $valueTypePart = $iteratorType->typeParams[$valueIndex];

                        if (!$valueType) {
                            $valueType = $valueTypePart;
                        } else {
                            $valueType = Type::combineUnionTypes($valueType, $valueTypePart);
                        }

                        if ($valueIndex) {
                            $keyTypePart = $iteratorType->typeParams[0];

                            if (!$keyType) {
                                $keyType = $keyTypePart;
                            } else {
                                $keyType = Type::combineUnionTypes($keyType, $keyTypePart);
                            }
                        }
                        continue;
                    }

                    if (!$codebase->classlikes->classOrInterfaceExists($iteratorType->value)) {
                        continue;
                    }

                    if ($codebase->classImplements(
                        $iteratorType->value,
                        'IteratorAggregate'
                    ) ||
                        (
                            $codebase->interfaceExists($iteratorType->value)
                            && $codebase->interfaceExtends(
                                $iteratorType->value,
                                'IteratorAggregate'
                            )
                        )
                    ) {
                        $iteratorMethod = $iteratorType->value . '::getIterator';
                        $selfClass = $iteratorType->value;
                        $iteratorClassType = $codebase->methods->getMethodReturnType(
                            $iteratorMethod,
                            $selfClass
                        );

                        if ($iteratorClassType) {
                            $arrayType = ExpressionChecker::fleshOutType(
                                $projectChecker,
                                $iteratorClassType,
                                $selfClass,
                                $selfClass
                            );

                            foreach ($arrayType->getTypes() as $arrayAtomicType) {
                                if ($arrayAtomicType instanceof Type\Atomic\TArray
                                    || $arrayAtomicType instanceof Type\Atomic\ObjectLike
                                ) {
                                    if ($arrayAtomicType instanceof Type\Atomic\ObjectLike) {
                                        $arrayAtomicType = $arrayAtomicType->getGenericArrayType();
                                    }

                                    $keyTypePart = $arrayAtomicType->typeParams[0];
                                    $valueTypePart = $arrayAtomicType->typeParams[1];
                                } elseif ($arrayAtomicType instanceof Type\Atomic\TGenericObject) {
                                    $typeParamCount = count($arrayAtomicType->typeParams);

                                    $valueTypePart = $arrayAtomicType->typeParams[$typeParamCount - 1];
                                    $keyTypePart = $typeParamCount > 1
                                        ? $arrayAtomicType->typeParams[0]
                                        : Type::getMixed();
                                } else {
                                    $keyType = Type::getMixed();
                                    $valueType = Type::getMixed();
                                    break;
                                }

                                if (!$keyType) {
                                    $keyType = $keyTypePart;
                                } else {
                                    $keyType = Type::combineUnionTypes($keyType, $keyTypePart);
                                }

                                if (!$valueType) {
                                    $valueType = $valueTypePart;
                                } else {
                                    $valueType = Type::combineUnionTypes($valueType, $valueTypePart);
                                }
                            }
                        } else {
                            $valueType = Type::getMixed();
                        }
                    } elseif ($codebase->classImplements(
                        $iteratorType->value,
                        'Iterator'
                    ) ||
                        (
                            $codebase->interfaceExists($iteratorType->value)
                            && $codebase->interfaceExtends(
                                $iteratorType->value,
                                'Iterator'
                            )
                        )
                    ) {
                        $iteratorMethod = $iteratorType->value . '::current';
                        $selfClass = $iteratorType->value;
                        $iteratorClassType = $codebase->methods->getMethodReturnType(
                            $iteratorMethod,
                            $selfClass
                        );

                        if ($iteratorClassType) {
                            $valueTypePart = ExpressionChecker::fleshOutType(
                                $projectChecker,
                                $iteratorClassType,
                                $selfClass,
                                $selfClass
                            );

                            if (!$valueType) {
                                $valueType = $valueTypePart;
                            } else {
                                $valueType = Type::combineUnionTypes($valueType, $valueTypePart);
                            }
                        } else {
                            $valueType = Type::getMixed();
                        }
                    } elseif ($codebase->classImplements(
                        $iteratorType->value,
                        'Traversable'
                    ) ||
                        (
                            $codebase->interfaceExists($iteratorType->value)
                            && $codebase->interfaceExtends(
                                $iteratorType->value,
                                'Traversable'
                            )
                        )
                    ) {
                        // @todo try and get value type
                    } elseif (!in_array(
                        strtolower($iteratorType->value),
                        ['iterator', 'iterable', 'traversable'],
                        true
                    )) {
                        if (IssueBuffer::accepts(
                            new RawObjectIteration(
                                'Possibly undesired iteration over regular object ' . $iteratorType->value,
                                new CodeLocation($statementsChecker->getSource(), $stmt->expr)
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            return false;
                        }
                    }
                }
            }

            if ($invalidIteratorTypes) {
                if ($hasValidIterator) {
                    if (IssueBuffer::accepts(
                        new PossiblyInvalidIterator(
                            'Cannot iterate over ' . $invalidIteratorTypes[0],
                            new CodeLocation($statementsChecker->getSource(), $stmt->expr)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }
                } else {
                    if (IssueBuffer::accepts(
                        new InvalidIterator(
                            'Cannot iterate over ' . $invalidIteratorTypes[0],
                            new CodeLocation($statementsChecker->getSource(), $stmt->expr)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }
                }
            }
        }

        if ($stmt->keyVar && $stmt->keyVar instanceof PhpParser\Node\Expr\Variable && is_string($stmt->keyVar->name)) {
            $keyVarId = '$' . $stmt->keyVar->name;
            $foreachContext->varsInScope[$keyVarId] = $keyType ?: Type::getMixed();
            $foreachContext->varsPossiblyInScope[$keyVarId] = true;

            $location = new CodeLocation($statementsChecker, $stmt->keyVar);

            if ($context->collectReferences && !isset($foreachContext->byrefConstraints[$keyVarId])) {
                $foreachContext->unreferencedVars[$keyVarId] = [$location->getHash() => $location];
            }

            if (!$statementsChecker->hasVariable($keyVarId)) {
                $statementsChecker->registerVariable(
                    $keyVarId,
                    $location,
                    $foreachContext->branchPoint
                );
            } else {
                $statementsChecker->registerVariableAssignment(
                    $keyVarId,
                    $location
                );
            }

            if ($stmt->byRef && $context->collectReferences) {
                $statementsChecker->registerVariableUses([$location->getHash() => $location]);
            }
        }

        if ($context->collectReferences
            && $stmt->byRef
            && $stmt->valueVar instanceof PhpParser\Node\Expr\Variable
            && is_string($stmt->valueVar->name)
        ) {
            $foreachContext->byrefConstraints['$' . $stmt->valueVar->name]
                = new \Psalm\ReferenceConstraint($valueType);
        }

        AssignmentChecker::analyze(
            $statementsChecker,
            $stmt->valueVar,
            null,
            $valueType ?: Type::getMixed(),
            $foreachContext,
            (string)$stmt->getDocComment()
        );

        $docCommentText = (string)$stmt->getDocComment();

        if ($docCommentText) {
            $varComments = [];

            try {
                $varComments = CommentChecker::getTypeFromComment(
                    $docCommentText,
                    $statementsChecker->getSource(),
                    $statementsChecker->getSource()->getAliases()
                );
            } catch (DocblockParseException $e) {
                if (IssueBuffer::accepts(
                    new InvalidDocblock(
                        (string)$e->getMessage(),
                        new CodeLocation($statementsChecker, $stmt)
                    )
                )) {
                    // fall through
                }
            }

            foreach ($varComments as $varComment) {
                if (!$varComment->varId) {
                    continue;
                }

                $commentType = ExpressionChecker::fleshOutType(
                    $projectChecker,
                    $varComment->type,
                    $context->self,
                    $context->self
                );

                $foreachContext->varsInScope[$varComment->varId] = $commentType;
            }
        }

        $loopScope = new LoopScope($foreachContext, $context);

        $protectedVarIds = $context->protectedVarIds;
        if ($varId) {
            $protectedVarIds[$varId] = true;
        }
        $loopScope->protectedVarIds = $protectedVarIds;

        LoopChecker::analyze($statementsChecker, $stmt->stmts, [], [], $loopScope);

        $context->varsPossiblyInScope = array_merge(
            $foreachContext->varsPossiblyInScope,
            $context->varsPossiblyInScope
        );

        $context->referencedVarIds = array_merge(
            $foreachContext->referencedVarIds,
            $context->referencedVarIds
        );

        if ($context->collectExceptions) {
            $context->possiblyThrownExceptions += $foreachContext->possiblyThrownExceptions;
        }

        if ($context->collectReferences) {
            foreach ($foreachContext->unreferencedVars as $varId => $locations) {
                if (isset($context->unreferencedVars[$varId])) {
                    $context->unreferencedVars[$varId] += $locations;
                } else {
                    $context->unreferencedVars[$varId] = $locations;
                }
            }
        }

        return null;
    }
}
