<?php
namespace Psalm\Checker\Statements\Expression;

use PhpParser;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Issue\DuplicateArrayKey;
use Psalm\IssueBuffer;
use Psalm\Type;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TString;

class ArrayChecker
{
    /**
     * @param   StatementsChecker           $statementsChecker
     * @param   PhpParser\Node\Expr\Array_  $stmt
     * @param   Context                     $context
     *
     * @return  false|null
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\Array_ $stmt,
        Context $context
    ) {
        // if the array is empty, this special type allows us to match any other array type against it
        if (empty($stmt->items)) {
            $stmt->inferredType = Type::getEmptyArray();

            return null;
        }

        $itemKeyType = null;

        $itemValueType = null;

        $propertyTypes = [];
        $classStrings = [];

        $canCreateObjectlike = true;

        $arrayKeys = [];

        $intOffsetDiff = 0;

        /** @var int $intOffset */
        foreach ($stmt->items as $intOffset => $item) {
            if ($item === null) {
                continue;
            }

            $itemKeyValue = null;

            if ($item->key) {
                if (ExpressionChecker::analyze($statementsChecker, $item->key, $context) === false) {
                    return false;
                }

                if (isset($item->key->inferredType)) {
                    $keyType = $item->key->inferredType;

                    if ($item->key instanceof PhpParser\Node\Scalar\String_
                        && preg_match('/^(0|[1-9][0-9]*)$/', $item->key->value)
                    ) {
                        $keyType = Type::getInt(false, (int) $item->key->value);
                    }

                    if ($itemKeyType) {
                        $itemKeyType = Type::combineUnionTypes($keyType, $itemKeyType);
                    } else {
                        $itemKeyType = $keyType;
                    }

                    if ($item->key->inferredType->isSingleStringLiteral()) {
                        $itemKeyLiteralType = $item->key->inferredType->getSingleStringLiteral();
                        $itemKeyValue = $itemKeyLiteralType->value;

                        if ($itemKeyLiteralType instanceof Type\Atomic\TLiteralClassString) {
                            $classStrings[$itemKeyValue] = true;
                        }
                    } elseif ($item->key->inferredType->isSingleIntLiteral()) {
                        $itemKeyValue = $item->key->inferredType->getSingleIntLiteral()->value;

                        if ($itemKeyValue > $intOffset + $intOffsetDiff) {
                            $intOffsetDiff = $itemKeyValue - ($intOffset + $intOffsetDiff);
                        }
                    }
                }
            } else {
                $itemKeyValue = $intOffset + $intOffsetDiff;
                $itemKeyType = Type::getInt();
            }

            if ($itemKeyValue !== null) {
                if (isset($arrayKeys[$itemKeyValue])) {
                    if (IssueBuffer::accepts(
                        new DuplicateArrayKey(
                            'Key \'' . $itemKeyValue . '\' already exists on array',
                            new CodeLocation($statementsChecker->getSource(), $item)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }

                $arrayKeys[$itemKeyValue] = true;
            }

            if (ExpressionChecker::analyze($statementsChecker, $item->value, $context) === false) {
                return false;
            }

            if ($itemValueType && $itemValueType->isMixed() && !$canCreateObjectlike) {
                continue;
            }

            if (isset($item->value->inferredType)) {
                if ($itemKeyValue !== null) {
                    $propertyTypes[$itemKeyValue] = $item->value->inferredType;
                } else {
                    $canCreateObjectlike = false;
                }

                if ($itemValueType) {
                    $itemValueType = Type::combineUnionTypes($item->value->inferredType, clone $itemValueType);
                } else {
                    $itemValueType = $item->value->inferredType;
                }
            } else {
                $itemValueType = Type::getMixed();

                if ($itemKeyValue !== null) {
                    $propertyTypes[$itemKeyValue] = $itemValueType;
                } else {
                    $canCreateObjectlike = false;
                }
            }
        }

        // if this array looks like an object-like array, let's return that instead
        if ($itemValueType
            && $itemKeyType
            && ($itemKeyType->hasString() || $itemKeyType->hasInt())
            && $canCreateObjectlike
        ) {
            $objectLike = new Type\Atomic\ObjectLike($propertyTypes, $classStrings);
            $objectLike->sealed = true;

            $stmt->inferredType = new Type\Union([$objectLike]);

            return null;
        }

        $arrayType = new Type\Atomic\TArray([
            $itemKeyType ?: new Type\Union([new TInt, new TString]),
            $itemValueType ?: Type::getMixed(),
        ]);

        $arrayType->count = count($stmt->items);

        $stmt->inferredType = new Type\Union([
            $arrayType,
        ]);

        return null;
    }
}
