<?php
namespace Psalm\Checker\Statements\Expression\Fetch;

use PhpParser;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Checker\TypeChecker;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Issue\EmptyArrayAccess;
use Psalm\Issue\InvalidArrayAccess;
use Psalm\Issue\InvalidArrayAssignment;
use Psalm\Issue\InvalidArrayOffset;
use Psalm\Issue\MixedArrayAccess;
use Psalm\Issue\MixedArrayAssignment;
use Psalm\Issue\MixedArrayOffset;
use Psalm\Issue\MixedStringOffsetAssignment;
use Psalm\Issue\NullArrayAccess;
use Psalm\Issue\NullArrayOffset;
use Psalm\Issue\PossiblyInvalidArrayAccess;
use Psalm\Issue\PossiblyInvalidArrayAssignment;
use Psalm\Issue\PossiblyInvalidArrayOffset;
use Psalm\Issue\PossiblyNullArrayAccess;
use Psalm\Issue\PossiblyNullArrayAssignment;
use Psalm\Issue\PossiblyNullArrayOffset;
use Psalm\Issue\PossiblyUndefinedArrayOffset;
use Psalm\IssueBuffer;
use Psalm\Type;
use Psalm\Type\Atomic\ObjectLike;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TEmpty;
use Psalm\Type\Atomic\TLiteralFloat;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TGenericParam;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TSingleLetter;
use Psalm\Type\Atomic\TString;

class ArrayFetchChecker
{
    /**
     * @param   StatementsChecker                   $statementsChecker
     * @param   PhpParser\Node\Expr\ArrayDimFetch   $stmt
     * @param   Context                             $context
     *
     * @return  false|null
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\ArrayDimFetch $stmt,
        Context $context
    ) {
        $arrayVarId = ExpressionChecker::getArrayVarId(
            $stmt->var,
            $statementsChecker->getFQCLN(),
            $statementsChecker
        );

        $keyedArrayVarId = ExpressionChecker::getArrayVarId(
            $stmt,
            $statementsChecker->getFQCLN(),
            $statementsChecker
        );

        if ($stmt->dim && ExpressionChecker::analyze($statementsChecker, $stmt->dim, $context) === false) {
            return false;
        }

        $dimVarId = null;
        $newOffsetType = null;

        if ($stmt->dim) {
            if (isset($stmt->dim->inferredType)) {
                $usedKeyType = $stmt->dim->inferredType;
            } else {
                $usedKeyType = Type::getMixed();
            }

            $dimVarId = ExpressionChecker::getArrayVarId(
                $stmt->dim,
                $statementsChecker->getFQCLN(),
                $statementsChecker
            );
        } else {
            $usedKeyType = Type::getInt();
        }

        if (ExpressionChecker::analyze(
            $statementsChecker,
            $stmt->var,
            $context
        ) === false) {
            return false;
        }

        if ($keyedArrayVarId
            && $context->hasVariable($keyedArrayVarId)
            && !$context->varsInScope[$keyedArrayVarId]->possiblyUndefined
        ) {
            $stmt->inferredType = clone $context->varsInScope[$keyedArrayVarId];

            return;
        }

        if (isset($stmt->var->inferredType)) {
            $varType = $stmt->var->inferredType;

            if ($varType->isNull()) {
                if (!$context->insideIsset) {
                    if (IssueBuffer::accepts(
                        new NullArrayAccess(
                            'Cannot access array value on null variable ' . $arrayVarId,
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }

                if (isset($stmt->inferredType)) {
                    $stmt->inferredType = Type::combineUnionTypes($stmt->inferredType, Type::getNull());
                } else {
                    $stmt->inferredType = Type::getNull();
                }

                return;
            }

            $stmt->inferredType = self::getArrayAccessTypeGivenOffset(
                $statementsChecker,
                $stmt,
                $stmt->var->inferredType,
                $usedKeyType,
                false,
                $arrayVarId,
                null,
                $context->insideIsset
            );

            if ($context->insideIsset
                && $stmt->dim
                && isset($stmt->dim->inferredType)
                && $stmt->var->inferredType->hasArray()
                && ($stmt->var instanceof PhpParser\Node\Expr\ClassConstFetch
                    || $stmt->var instanceof PhpParser\Node\Expr\ConstFetch)
            ) {
                /** @var TArray|ObjectLike */
                $arrayType = $stmt->var->inferredType->getTypes()['array'];

                if ($arrayType instanceof TArray) {
                    $constArrayKeyType = $arrayType->typeParams[0];
                } else {
                    $constArrayKeyType = $arrayType->getGenericKeyType();
                }

                if ($dimVarId && !$constArrayKeyType->isMixed() && !$stmt->dim->inferredType->isMixed()) {
                    $newOffsetType = clone $stmt->dim->inferredType;
                    $constArrayKeyAtomicTypes = $constArrayKeyType->getTypes();
                    $projectChecker = $statementsChecker->getFileChecker()->projectChecker;

                    foreach ($newOffsetType->getTypes() as $offsetKey => $offsetAtomicType) {
                        if ($offsetAtomicType instanceof TString
                            || $offsetAtomicType instanceof TInt
                        ) {
                            if (!isset($constArrayKeyAtomicTypes[$offsetKey])
                                && !TypeChecker::isContainedBy(
                                    $projectChecker->codebase,
                                    new Type\Union([$offsetAtomicType]),
                                    $constArrayKeyType
                                )
                            ) {
                                $newOffsetType->removeType($offsetKey);
                            }
                        } elseif (!TypeChecker::isContainedBy(
                            $projectChecker->codebase,
                            $constArrayKeyType,
                            new Type\Union([$offsetAtomicType])
                        )) {
                            $newOffsetType->removeType($offsetKey);
                        }
                    }
                }
            }
        }

        if ($keyedArrayVarId && $context->hasVariable($keyedArrayVarId, $statementsChecker)) {
            $stmt->inferredType = $context->varsInScope[$keyedArrayVarId];
        }

        if (!isset($stmt->inferredType)) {
            $stmt->inferredType = Type::getMixed();
        } else {
            if ($stmt->inferredType->possiblyUndefined && !$context->insideIsset && !$context->insideUnset) {
                if (IssueBuffer::accepts(
                    new PossiblyUndefinedArrayOffset(
                        'Possibly undefined array key ' . $keyedArrayVarId,
                        new CodeLocation($statementsChecker->getSource(), $stmt)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }
            }
        }

        if ($context->insideIsset && $dimVarId && $newOffsetType && $newOffsetType->getTypes()) {
            $context->varsInScope[$dimVarId] = $newOffsetType;
        }

        if ($keyedArrayVarId && !$context->insideIsset) {
            $context->varsInScope[$keyedArrayVarId] = $stmt->inferredType;
            $context->varsPossiblyInScope[$keyedArrayVarId] = true;

            // reference the variable too
            $context->hasVariable($keyedArrayVarId, $statementsChecker);
        }

        return null;
    }

    /**
     * @param  Type\Union $arrayType
     * @param  Type\Union $offsetType
     * @param  bool       $inAssignment
     * @param  null|string    $arrayVarId
     * @param  bool       $insideIsset
     *
     * @return Type\Union
     */
    public static function getArrayAccessTypeGivenOffset(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\ArrayDimFetch $stmt,
        Type\Union $arrayType,
        Type\Union $offsetType,
        $inAssignment,
        $arrayVarId,
        Type\Union $replacementType = null,
        $insideIsset = false
    ) {
        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;

        $hasArrayAccess = false;
        $nonArrayTypes = [];

        $hasValidOffset = false;
        $expectedOffsetTypes = [];

        $keyValue = null;

        if ($stmt->dim instanceof PhpParser\Node\Scalar\String_
            || $stmt->dim instanceof PhpParser\Node\Scalar\LNumber
        ) {
            $keyValue = $stmt->dim->value;
        } elseif (isset($stmt->dim->inferredType)) {
            foreach ($stmt->dim->inferredType->getTypes() as $possibleValueType) {
                if ($possibleValueType instanceof TLiteralString
                    || $possibleValueType instanceof TLiteralInt
                ) {
                    if ($keyValue !== null) {
                        $keyValue = null;
                        break;
                    }

                    $keyValue = $possibleValueType->value;
                } elseif ($possibleValueType instanceof TString
                    || $possibleValueType instanceof TInt
                ) {
                    $keyValue = null;
                    break;
                }
            }
        }

        $arrayAccessType = null;

        if ($offsetType->isNull()) {
            if (IssueBuffer::accepts(
                new NullArrayOffset(
                    'Cannot access value on variable ' . $arrayVarId . ' using null offset',
                    new CodeLocation($statementsChecker->getSource(), $stmt)
                ),
                $statementsChecker->getSuppressedIssues()
            )) {
                // fall through
            }

            return Type::getMixed();
        }

        if ($offsetType->isNullable() && !$offsetType->ignoreNullableIssues && !$insideIsset) {
            if (IssueBuffer::accepts(
                new PossiblyNullArrayOffset(
                    'Cannot access value on variable ' . $arrayVarId
                        . ' using possibly null offset ' . $offsetType,
                    new CodeLocation($statementsChecker->getSource(), $stmt->var)
                ),
                $statementsChecker->getSuppressedIssues()
            )) {
                // fall through
            }
        }

        foreach ($arrayType->getTypes() as &$type) {
            if ($type instanceof TNull) {
                if ($arrayType->ignoreNullableIssues) {
                    continue;
                }

                if ($inAssignment) {
                    if ($replacementType) {
                        if ($arrayAccessType) {
                            $arrayAccessType = Type::combineUnionTypes($arrayAccessType, $replacementType);
                        } else {
                            $arrayAccessType = clone $replacementType;
                        }
                    } else {
                        if (IssueBuffer::accepts(
                            new PossiblyNullArrayAssignment(
                                'Cannot access array value on possibly null variable ' . $arrayVarId .
                                    ' of type ' . $arrayType,
                                new CodeLocation($statementsChecker->getSource(), $stmt)
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            // fall through
                        }

                        $arrayAccessType = new Type\Union([new TEmpty]);
                    }
                } else {
                    if (!$insideIsset) {
                        if (IssueBuffer::accepts(
                            new PossiblyNullArrayAccess(
                                'Cannot access array value on possibly null variable ' . $arrayVarId .
                                    ' of type ' . $arrayType,
                                new CodeLocation($statementsChecker->getSource(), $stmt)
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    }

                    if ($arrayAccessType) {
                        $arrayAccessType = Type::combineUnionTypes($arrayAccessType, Type::getNull());
                    } else {
                        $arrayAccessType = Type::getNull();
                    }
                }

                continue;
            }

            if ($type instanceof TArray || $type instanceof ObjectLike) {
                $hasArrayAccess = true;

                if ($inAssignment
                    && $type instanceof TArray
                    && $type->typeParams[0]->isEmpty()
                    && $keyValue !== null
                ) {
                    // ok, type becomes an ObjectLike

                    $type = new ObjectLike([$keyValue => new Type\Union([new TEmpty])]);
                }

                $offsetType = self::replaceOffsetTypeWithInts($offsetType);

                if ($type instanceof TArray) {
                    // if we're assigning to an empty array with a key offset, refashion that array
                    if ($inAssignment) {
                        if ($type->typeParams[0]->isEmpty()) {
                            $type->typeParams[0] = $offsetType;
                        }
                    } elseif (!$type->typeParams[0]->isEmpty()) {
                        if (!TypeChecker::isContainedBy(
                            $projectChecker->codebase,
                            $offsetType,
                            $type->typeParams[0],
                            true,
                            $offsetType->ignoreFalsableIssues,
                            $hasScalarMatch,
                            $typeCoerced,
                            $typeCoercedFromMixed,
                            $toStringCast,
                            $typeCoercedFromScalar
                        ) && !$typeCoercedFromScalar
                        ) {
                            $expectedOffsetTypes[] = $type->typeParams[0]->getId();
                        } else {
                            $hasValidOffset = true;
                        }
                    }

                    if (!$stmt->dim && $type->count !== null) {
                        $type->count++;
                    }

                    if ($inAssignment && $replacementType) {
                        $type->typeParams[1] = Type::combineUnionTypes(
                            $type->typeParams[1],
                            $replacementType
                        );
                    }

                    if (!$arrayAccessType) {
                        $arrayAccessType = $type->typeParams[1];
                    } else {
                        $arrayAccessType = Type::combineUnionTypes(
                            $arrayAccessType,
                            $type->typeParams[1]
                        );
                    }

                    if ($arrayAccessType->isEmpty()
                        && !$inAssignment
                        && !$insideIsset
                    ) {
                        if (IssueBuffer::accepts(
                            new EmptyArrayAccess(
                                'Cannot access value on empty array variable ' . $arrayVarId,
                                new CodeLocation($statementsChecker->getSource(), $stmt)
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            return Type::getMixed(true);
                        }

                        if (!IssueBuffer::isRecording()) {
                            $arrayAccessType = Type::getMixed(true);
                        }
                    }
                } else {
                    if ($keyValue !== null) {
                        if (isset($type->properties[$keyValue]) || $replacementType) {
                            $hasValidOffset = true;

                            if ($replacementType) {
                                if (isset($type->properties[$keyValue])) {
                                    $type->properties[$keyValue] = Type::combineUnionTypes(
                                        $type->properties[$keyValue],
                                        $replacementType
                                    );
                                } else {
                                    $type->properties[$keyValue] = $replacementType;
                                }
                            }

                            if (!$arrayAccessType) {
                                $arrayAccessType = clone $type->properties[$keyValue];
                            } else {
                                $arrayAccessType = Type::combineUnionTypes(
                                    $arrayAccessType,
                                    $type->properties[$keyValue]
                                );
                            }
                        } elseif ($inAssignment) {
                            $type->properties[$keyValue] = new Type\Union([new TEmpty]);

                            if (!$arrayAccessType) {
                                $arrayAccessType = clone $type->properties[$keyValue];
                            } else {
                                $arrayAccessType = Type::combineUnionTypes(
                                    $arrayAccessType,
                                    $type->properties[$keyValue]
                                );
                            }
                        } else {
                            if (!$insideIsset || $type->sealed) {
                                $objectLikeKeys = array_keys($type->properties);

                                if (count($objectLikeKeys) === 1) {
                                    $expectedKeysString = '\'' . $objectLikeKeys[0] . '\'';
                                } else {
                                    $lastKey = array_pop($objectLikeKeys);
                                    $expectedKeysString = '\'' . implode('\', \'', $objectLikeKeys) .
                                        '\' or \'' . $lastKey . '\'';
                                }

                                $expectedOffsetTypes[] = $expectedKeysString;
                            }

                            $arrayAccessType = Type::getMixed();
                        }
                    } elseif (TypeChecker::isContainedBy(
                        $codebase,
                        $offsetType,
                        $type->getGenericKeyType(),
                        true,
                        $offsetType->ignoreFalsableIssues,
                        $hasScalarMatch,
                        $typeCoerced,
                        $typeCoercedFromMixed,
                        $toStringCast,
                        $typeCoercedFromScalar
                    )
                    || $typeCoercedFromScalar
                    || $inAssignment
                    ) {
                        if ($replacementType) {
                            $genericParams = Type::combineUnionTypes(
                                $type->getGenericValueType(),
                                $replacementType
                            );

                            $newKeyType = Type::combineUnionTypes(
                                $type->getGenericKeyType(),
                                $offsetType
                            );

                            $propertyCount = $type->sealed ? count($type->properties) : null;

                            $type = new TArray([
                                $newKeyType,
                                $genericParams,
                            ]);

                            if (!$stmt->dim && $propertyCount) {
                                ++$propertyCount;
                                $type->count = $propertyCount;
                            }

                            if (!$arrayAccessType) {
                                $arrayAccessType = clone $genericParams;
                            } else {
                                $arrayAccessType = Type::combineUnionTypes(
                                    $arrayAccessType,
                                    $genericParams
                                );
                            }
                        } else {
                            if (!$arrayAccessType) {
                                $arrayAccessType = $type->getGenericValueType();
                            } else {
                                $arrayAccessType = Type::combineUnionTypes(
                                    $arrayAccessType,
                                    $type->getGenericValueType()
                                );
                            }
                        }

                        $hasValidOffset = true;
                    } else {
                        if (!$insideIsset || $type->sealed) {
                            $expectedOffsetTypes[] = (string)$type->getGenericKeyType()->getId();
                        }

                        $arrayAccessType = Type::getMixed();
                    }
                }
                continue;
            }

            if ($type instanceof TString) {
                if ($inAssignment && $replacementType) {
                    if ($replacementType->isMixed()) {
                        $codebase->analyzer->incrementMixedCount($statementsChecker->getFilePath());

                        if (IssueBuffer::accepts(
                            new MixedStringOffsetAssignment(
                                'Right-hand-side of string offset assignment cannot be mixed',
                                new CodeLocation($statementsChecker->getSource(), $stmt)
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    } else {
                        $codebase->analyzer->incrementNonMixedCount($statementsChecker->getFilePath());
                    }
                }

                if ($type instanceof TSingleLetter) {
                    $validOffsetType = Type::getInt(false, 0);
                } elseif ($type instanceof TLiteralString) {
                    $validOffsets = [];

                    for ($i = 0, $l = strlen($type->value); $i < $l; $i++) {
                        $validOffsets[] = new TLiteralInt($i);
                    }

                    $validOffsetType = new Type\Union($validOffsets);
                } else {
                    $validOffsetType = Type::getInt();
                }

                if (!TypeChecker::isContainedBy(
                    $projectChecker->codebase,
                    $offsetType,
                    $validOffsetType,
                    true
                )) {
                    $expectedOffsetTypes[] = $validOffsetType->getId();
                } else {
                    $hasValidOffset = true;
                }

                if (!$arrayAccessType) {
                    $arrayAccessType = Type::getSingleLetter();
                } else {
                    $arrayAccessType = Type::combineUnionTypes(
                        $arrayAccessType,
                        Type::getSingleLetter()
                    );
                }

                continue;
            }

            if ($type instanceof TMixed || $type instanceof TGenericParam || $type instanceof TEmpty) {
                $codebase->analyzer->incrementMixedCount($statementsChecker->getFilePath());

                if (!$insideIsset) {
                    if ($inAssignment) {
                        if (IssueBuffer::accepts(
                            new MixedArrayAssignment(
                                'Cannot access array value on mixed variable ' . $arrayVarId,
                                new CodeLocation($statementsChecker->getSource(), $stmt)
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    } else {
                        if (IssueBuffer::accepts(
                            new MixedArrayAccess(
                                'Cannot access array value on mixed variable ' . $arrayVarId,
                                new CodeLocation($statementsChecker->getSource(), $stmt)
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    }
                }

                $arrayAccessType = Type::getMixed();
                break;
            }

            $codebase->analyzer->incrementNonMixedCount($statementsChecker->getFilePath());

            if ($type instanceof Type\Atomic\TFalse && $arrayType->ignoreFalsableIssues) {
                continue;
            }

            if ($type instanceof TNamedObject) {
                if (strtolower($type->value) !== 'simplexmlelement'
                    && strtolower($type->value) !== 'arrayaccess'
                    && (($codebase->classExists($type->value)
                            && !$codebase->classImplements($type->value, 'ArrayAccess'))
                        || ($codebase->interfaceExists($type->value)
                            && !$codebase->interfaceExtends($type->value, 'ArrayAccess'))
                    )
                ) {
                    $nonArrayTypes[] = (string)$type;
                } else {
                    $arrayAccessType = Type::getMixed();
                }
            } else {
                $nonArrayTypes[] = (string)$type;
            }
        }

        if ($nonArrayTypes) {
            if ($hasArrayAccess) {
                if ($inAssignment) {
                    if (IssueBuffer::accepts(
                        new PossiblyInvalidArrayAssignment(
                            'Cannot access array value on non-array variable ' .
                            $arrayVarId . ' of type ' . $nonArrayTypes[0],
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )
                    ) {
                        // do nothing
                    }
                } else {
                    if (IssueBuffer::accepts(
                        new PossiblyInvalidArrayAccess(
                            'Cannot access array value on non-array variable ' .
                            $arrayVarId . ' of type ' . $nonArrayTypes[0],
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )
                    ) {
                        // do nothing
                    }
                }
            } else {
                if ($inAssignment) {
                    if (IssueBuffer::accepts(
                        new InvalidArrayAssignment(
                            'Cannot access array value on non-array variable ' .
                            $arrayVarId . ' of type ' . $nonArrayTypes[0],
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                } else {
                    if (IssueBuffer::accepts(
                        new InvalidArrayAccess(
                            'Cannot access array value on non-array variable ' .
                            $arrayVarId . ' of type ' . $nonArrayTypes[0],
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }

                $arrayAccessType = Type::getMixed();
            }
        }

        if ($offsetType->isMixed()) {
            $codebase->analyzer->incrementMixedCount($statementsChecker->getFilePath());

            if (IssueBuffer::accepts(
                new MixedArrayOffset(
                    'Cannot access value on variable ' . $arrayVarId . ' using mixed offset',
                    new CodeLocation($statementsChecker->getSource(), $stmt)
                ),
                $statementsChecker->getSuppressedIssues()
            )) {
                // fall through
            }
        } else {
            $codebase->analyzer->incrementNonMixedCount($statementsChecker->getFilePath());

            if ($expectedOffsetTypes) {
                $invalidOffsetType = $expectedOffsetTypes[0];

                $usedOffset = 'using a ' . $offsetType->getId() . ' offset';

                if ($keyValue !== null) {
                    $usedOffset = 'using offset value of '
                        . (is_int($keyValue) ? $keyValue : '\'' . $keyValue . '\'');
                }

                if ($hasValidOffset) {
                    if (IssueBuffer::accepts(
                        new PossiblyInvalidArrayOffset(
                            'Cannot access value on variable ' . $arrayVarId . ' ' . $usedOffset
                                . ', expecting ' . $invalidOffsetType,
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                } else {
                    if (IssueBuffer::accepts(
                        new InvalidArrayOffset(
                            'Cannot access value on variable ' . $arrayVarId . ' ' . $usedOffset
                                . ', expecting ' . $invalidOffsetType,
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }
            }
        }

        if ($arrayAccessType === null) {
            throw new \InvalidArgumentException('This is a bad place');
        }

        if ($inAssignment) {
            $arrayType->bustCache();
        }

        return $arrayAccessType;
    }

    /**
     * @return Type\Union
     */
    public static function replaceOffsetTypeWithInts(Type\Union $offsetType)
    {
        $offsetStringTypes = $offsetType->getLiteralStrings();

        $offsetType = clone $offsetType;

        foreach ($offsetStringTypes as $key => $offsetStringType) {
            if (preg_match('/^(0|[1-9][0-9]*)$/', $offsetStringType->value)) {
                $offsetType->addType(new Type\Atomic\TLiteralInt((int) $offsetStringType->value));
                $offsetType->removeType($key);
            }
        }

        return $offsetType;
    }
}
