<?php
namespace Psalm\Checker;

use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Codebase;
use Psalm\Type;
use Psalm\Type\Atomic\ObjectLike;
use Psalm\Type\Atomic\Scalar;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TEmptyMixed;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TGenericParam;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TLiteralFloat;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TNumeric;
use Psalm\Type\Atomic\TNumericString;
use Psalm\Type\Atomic\TObject;
use Psalm\Type\Atomic\TResource;
use Psalm\Type\Atomic\TScalar;
use Psalm\Type\Atomic\TSingleLetter;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TTrue;

class TypeChecker
{
    /**
     * Does the input param type match the given param type
     *
     * @param  Type\Union   $inputType
     * @param  Type\Union   $containerType
     * @param  bool         $ignoreNull
     * @param  bool         $ignoreFalse
     * @param  bool         &$hasScalarMatch
     * @param  bool         &$typeCoerced    whether or not there was type coercion involved
     * @param  bool         &$typeCoercedFromMixed
     * @param  bool         &$toStringCast
     * @param  bool         &$typeCoercedFromScalar
     *
     * @return bool
     */
    public static function isContainedBy(
        Codebase $codebase,
        Type\Union $inputType,
        Type\Union $containerType,
        $ignoreNull = false,
        $ignoreFalse = false,
        &$hasScalarMatch = null,
        &$typeCoerced = null,
        &$typeCoercedFromMixed = null,
        &$toStringCast = null,
        &$typeCoercedFromScalar = null
    ) {
        $hasScalarMatch = true;

        if ($containerType->isMixed() && !$containerType->isEmptyMixed()) {
            return true;
        }

        if ($inputType->possiblyUndefined && !$containerType->possiblyUndefined) {
            return false;
        }

        foreach ($inputType->getTypes() as $inputTypePart) {
            if ($inputTypePart instanceof TNull && $ignoreNull) {
                continue;
            }

            if ($inputTypePart instanceof TFalse && $ignoreFalse) {
                continue;
            }

            $typeMatchFound = false;
            $scalarTypeMatchFound = false;
            $allToStringCast = true;
            $atomicToStringCast = false;

            foreach ($containerType->getTypes() as $containerTypePart) {
                $isAtomicContainedBy = self::isAtomicContainedBy(
                    $codebase,
                    $inputTypePart,
                    $containerTypePart,
                    $scalarTypeMatchFound,
                    $typeCoerced,
                    $typeCoercedFromMixed,
                    $atomicToStringCast,
                    $typeCoercedFromScalar
                );

                if ($isAtomicContainedBy) {
                    $typeMatchFound = true;
                }

                if ($atomicToStringCast !== true && $typeMatchFound) {
                    $allToStringCast = false;
                }
            }

            // only set this flag if we're definite that the only
            // reason the type match has been found is because there
            // was a __toString cast
            if ($allToStringCast && $typeMatchFound) {
                $toStringCast = true;
            }

            if (!$typeMatchFound) {
                if (!$scalarTypeMatchFound) {
                    $hasScalarMatch = false;
                }

                return false;
            }
        }

        return true;
    }

    /**
     * Used for comparing signature typehints, uses PHP's light contravariance rules
     *
     * @param  Type\Union   $inputType
     * @param  Type\Union   $containerType
     *
     * @return bool
     */
    public static function isContainedByInPhp(
        Type\Union $inputType = null,
        Type\Union $containerType
    ) {
        if (!$inputType) {
            return false;
        }

        if ($inputType->getId() === $containerType->getId()) {
            return true;
        }

        if ($inputType->isNullable() && !$containerType->isNullable()) {
            return false;
        }

        $inputTypeNotNull = clone $inputType;
        $inputTypeNotNull->removeType('null');

        $containerTypeNotNull = clone $containerType;
        $containerTypeNotNull->removeType('null');

        if ($inputTypeNotNull->getId() === $containerTypeNotNull->getId()) {
            return true;
        }

        if ($inputTypeNotNull->hasArray() && $containerTypeNotNull->hasType('iterable')) {
            return true;
        }

        return false;
    }

    /**
     * Does the input param type match the given param type
     *
     * @param  Type\Union   $inputType
     * @param  Type\Union   $containerType
     * @param  bool         $ignoreNull
     * @param  bool         $ignoreFalse
     * @param  bool         &$hasScalarMatch
     * @param  bool         &$typeCoerced    whether or not there was type coercion involved
     * @param  bool         &$typeCoercedFromMixed
     * @param  bool         &$toStringCast
     *
     * @return bool
     */
    public static function canBeContainedBy(
        Codebase $codebase,
        Type\Union $inputType,
        Type\Union $containerType,
        $ignoreNull = false,
        $ignoreFalse = false
    ) {
        if ($containerType->isMixed()) {
            return true;
        }

        if ($inputType->possiblyUndefined && !$containerType->possiblyUndefined) {
            return false;
        }

        foreach ($containerType->getTypes() as $containerTypePart) {
            if ($containerTypePart instanceof TNull && $ignoreNull) {
                continue;
            }

            if ($containerTypePart instanceof TFalse && $ignoreFalse) {
                continue;
            }

            $scalarTypeMatchFound = false;
            $atomicToStringCast = false;

            foreach ($inputType->getTypes() as $inputTypePart) {
                $isAtomicContainedBy = self::isAtomicContainedBy(
                    $codebase,
                    $inputTypePart,
                    $containerTypePart,
                    $scalarTypeMatchFound,
                    $typeCoerced,
                    $typeCoercedFromMixed,
                    $atomicToStringCast
                );

                if ($isAtomicContainedBy) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Can any part of the $type1 be equal to any part of $type2
     *
     * @return bool
     */
    public static function canBeIdenticalTo(
        Codebase $codebase,
        Type\Union $type1,
        Type\Union $type2
    ) {
        if ($type1->isMixed() || $type2->isMixed()) {
            return true;
        }

        if ($type1->isNullable() && $type2->isNullable()) {
            return true;
        }

        foreach ($type1->getTypes() as $type1Part) {
            if ($type1Part instanceof TNull) {
                continue;
            }

            foreach ($type2->getTypes() as $type2Part) {
                if ($type2Part instanceof TNull) {
                    continue;
                }

                $eitherContains = self::isAtomicContainedBy(
                    $codebase,
                    $type1Part,
                    $type2Part
                ) || self::isAtomicContainedBy(
                    $codebase,
                    $type2Part,
                    $type1Part
                );

                if ($eitherContains) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  Codebase       $codebase
     * @param  TNamedObject   $inputTypePart
     * @param  TNamedObject   $containerTypePart
     *
     * @return bool
     */
    private static function isObjectContainedByObject(
        Codebase $codebase,
        TNamedObject $inputTypePart,
        TNamedObject $containerTypePart
    ) {
        $intersectionInputTypes = $inputTypePart->extraTypes ?: [];
        $intersectionInputTypes[] = $inputTypePart;

        $intersectionContainerTypes = $containerTypePart->extraTypes ?: [];
        $intersectionContainerTypes[] = $containerTypePart;

        foreach ($intersectionContainerTypes as $intersectionContainerType) {
            $intersectionContainerTypeLower = strtolower($intersectionContainerType->value);

            foreach ($intersectionInputTypes as $intersectionInputType) {
                $intersectionInputTypeLower = strtolower($intersectionInputType->value);

                if ($intersectionContainerTypeLower === $intersectionInputTypeLower) {
                    continue 2;
                }

                if ($intersectionInputTypeLower === 'generator'
                    && in_array($intersectionContainerTypeLower, ['iterator', 'traversable', 'iterable'], true)
                ) {
                    continue 2;
                }

                if ($codebase->classExists($intersectionInputType->value)
                    && $codebase->classExtendsOrImplements(
                        $intersectionInputType->value,
                        $intersectionContainerType->value
                    )
                ) {
                    continue 2;
                }

                if ($codebase->interfaceExists($intersectionInputType->value)
                    && $codebase->interfaceExtends(
                        $intersectionInputType->value,
                        $intersectionContainerType->value
                    )
                ) {
                    continue 2;
                }

                if (ExpressionChecker::isMock($intersectionInputType->value)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * Does the input param atomic type match the given param atomic type
     *
     * @param  Codebase     $codebase
     * @param  Type\Atomic  $inputTypePart
     * @param  Type\Atomic  $containerTypePart
     * @param  bool         &$hasScalarMatch
     * @param  bool         &$typeCoerced    whether or not there was type coercion involved
     * @param  bool         &$typeCoercedFromMixed
     * @param  bool         &$toStringCast
     * @param  bool         &$typeCoercedFromScalar
     *
     * @return bool
     */
    public static function isAtomicContainedBy(
        Codebase $codebase,
        Type\Atomic $inputTypePart,
        Type\Atomic $containerTypePart,
        &$hasScalarMatch = null,
        &$typeCoerced = null,
        &$typeCoercedFromMixed = null,
        &$toStringCast = null,
        &$typeCoercedFromScalar = null
    ) {
        if ($containerTypePart instanceof TMixed || $containerTypePart instanceof TGenericParam) {
            if (get_class($containerTypePart) === TEmptyMixed::class
                && get_class($inputTypePart) === TMixed::class
            ) {
                $typeCoerced = true;
                $typeCoercedFromMixed = true;

                return false;
            }

            return true;
        }

        if ($inputTypePart instanceof TMixed || $inputTypePart instanceof TGenericParam) {
            $typeCoerced = true;
            $typeCoercedFromMixed = true;

            return false;
        }

        if ($inputTypePart instanceof TNull && $containerTypePart instanceof TNull) {
            return true;
        }

        if ($inputTypePart instanceof TNull || $containerTypePart instanceof TNull) {
            return false;
        }

        if ($inputTypePart->shallowEquals($containerTypePart) ||
            (
                $inputTypePart instanceof TNamedObject
                && $containerTypePart instanceof TNamedObject
                && self::isObjectContainedByObject($codebase, $inputTypePart, $containerTypePart)
            )
        ) {
            return self::isMatchingTypeContainedBy(
                $codebase,
                $inputTypePart,
                $containerTypePart,
                $hasScalarMatch,
                $typeCoerced,
                $typeCoercedFromMixed,
                $toStringCast
            );
        }

        if ($inputTypePart instanceof TFalse
            && $containerTypePart instanceof TBool
            && !($containerTypePart instanceof TTrue)
        ) {
            return true;
        }

        if ($inputTypePart instanceof TTrue
            && $containerTypePart instanceof TBool
            && !($containerTypePart instanceof TFalse)
        ) {
            return true;
        }

        // from https://wiki.php.net/rfc/scalar_type_hints_v5:
        //
        // > int types can resolve a parameter type of float
        if ($inputTypePart instanceof TInt && $containerTypePart instanceof TFloat) {
            return true;
        }

        if ($inputTypePart instanceof TNamedObject
            && $inputTypePart->value === 'static'
            && $containerTypePart instanceof TNamedObject
            && strtolower($containerTypePart->value) === 'self'
        ) {
            return true;
        }

        if ($containerTypePart instanceof TCallable && $inputTypePart instanceof Type\Atomic\Fn) {
            $allTypesContain = true;

            if (self::compareCallable(
                $codebase,
                $inputTypePart,
                $containerTypePart,
                $typeCoerced,
                $typeCoercedFromMixed,
                $hasScalarMatch,
                $allTypesContain
            ) === false
            ) {
                return false;
            }

            if (!$allTypesContain) {
                return false;
            }
        }

        if ($inputTypePart instanceof TNamedObject &&
            $inputTypePart->value === 'Closure' &&
            $containerTypePart instanceof TCallable
        ) {
            return true;
        }

        if ($containerTypePart instanceof TNumeric &&
            ($inputTypePart->isNumericType() || $inputTypePart instanceof TString)
        ) {
            return true;
        }

        if ($containerTypePart instanceof ObjectLike && $inputTypePart instanceof ObjectLike) {
            $allTypesContain = true;

            foreach ($containerTypePart->properties as $key => $containerPropertyType) {
                if (!isset($inputTypePart->properties[$key])) {
                    if (!$containerPropertyType->possiblyUndefined) {
                        $allTypesContain = false;
                    }

                    continue;
                }

                $inputPropertyType = $inputTypePart->properties[$key];

                if (!$inputPropertyType->isEmpty()
                    && !self::isContainedBy(
                        $codebase,
                        $inputPropertyType,
                        $containerPropertyType,
                        $inputPropertyType->ignoreNullableIssues,
                        $inputPropertyType->ignoreFalsableIssues,
                        $propertyHasScalarMatch,
                        $propertyTypeCoerced,
                        $propertyTypeCoercedFromMixed,
                        $propertyTypeToStringCast,
                        $propertyTypeCoercedFromScalar
                    )
                    && !$propertyTypeCoercedFromScalar
                ) {
                    if (self::isContainedBy(
                        $codebase,
                        $containerPropertyType,
                        $inputPropertyType,
                        false,
                        false,
                        $inversePropertyHasScalarMatch,
                        $inversePropertyTypeCoerced,
                        $inversePropertyTypeCoercedFromMixed,
                        $inversePropertyTypeToStringCast,
                        $inversePropertyTypeCoercedFromScalar
                    )
                    || $inversePropertyTypeCoercedFromScalar
                    ) {
                        $typeCoerced = true;
                    }

                    $allTypesContain = false;
                }
            }

            if ($allTypesContain) {
                $toStringCast = false;

                return true;
            }

            return false;
        }

        if ($containerTypePart instanceof TNamedObject
            && strtolower($containerTypePart->value) === 'iterable'
        ) {
            if ($inputTypePart instanceof TArray || $inputTypePart instanceof ObjectLike) {
                if (!$containerTypePart instanceof TGenericObject) {
                    return true;
                }

                if ($inputTypePart instanceof ObjectLike) {
                    $inputTypePart = $inputTypePart->getGenericArrayType();
                }

                $allTypesContain = true;

                foreach ($inputTypePart->typeParams as $i => $inputParam) {
                    $containerParamOffset = $i - (2 - count($containerTypePart->typeParams));

                    if ($containerParamOffset === -1) {
                        continue;
                    }

                    $containerParam = $containerTypePart->typeParams[$containerParamOffset];

                    if ($i === 0
                        && $inputParam->isMixed()
                        && $containerParam->hasString()
                        && $containerParam->hasInt()
                    ) {
                        continue;
                    }

                    if (!$inputParam->isEmpty()
                        && !self::isContainedBy(
                            $codebase,
                            $inputParam,
                            $containerParam,
                            $inputParam->ignoreNullableIssues,
                            $inputParam->ignoreFalsableIssues,
                            $arrayHasScalarMatch,
                            $arrayTypeCoerced,
                            $typeCoercedFromMixed,
                            $arrayToStringCast,
                            $arrayTypeCoercedFromScalar
                        )
                        && !$arrayTypeCoercedFromScalar
                    ) {
                        $allTypesContain = false;
                    }
                }

                if ($allTypesContain) {
                    $toStringCast = false;

                    return true;
                }

                return false;
            }

            if ($inputTypePart->isTraversable($codebase)) {
                return true;
            }
        }

        if ($containerTypePart instanceof TScalar && $inputTypePart instanceof Scalar) {
            return true;
        }

        if (get_class($containerTypePart) === TInt::class && $inputTypePart instanceof TLiteralInt) {
            return true;
        }

        if (get_class($containerTypePart) === TFloat::class && $inputTypePart instanceof TLiteralFloat) {
            return true;
        }

        if ((get_class($containerTypePart) === TString::class
                || get_class($containerTypePart) === TSingleLetter::class)
            && $inputTypePart instanceof TLiteralString
        ) {
            return true;
        }

        if (get_class($inputTypePart) === TInt::class && $containerTypePart instanceof TLiteralInt) {
            $typeCoerced = true;
            $typeCoercedFromScalar = true;

            return false;
        }

        if (get_class($inputTypePart) === TFloat::class && $containerTypePart instanceof TLiteralFloat) {
            $typeCoerced = true;
            $typeCoercedFromScalar = true;

            return false;
        }

        if ((get_class($inputTypePart) === TString::class || get_class($containerTypePart) === TSingleLetter::class)
            && $containerTypePart instanceof TLiteralString
        ) {
            $typeCoerced = true;
            $typeCoercedFromScalar = true;

            return false;
        }

        if (($containerTypePart instanceof TClassString || $containerTypePart instanceof TLiteralClassString)
            && ($inputTypePart instanceof TClassString || $inputTypePart instanceof TLiteralClassString)
        ) {
            if ($containerTypePart instanceof TClassString) {
                return true;
            }

            if ($inputTypePart instanceof TClassString) {
                $typeCoerced = true;
                $typeCoercedFromScalar = true;

                return false;
            }

            $fakeContainerObject = new TNamedObject($containerTypePart->value);
            $fakeInputObject = new TNamedObject($inputTypePart->value);

            return self::isObjectContainedByObject($codebase, $fakeInputObject, $fakeContainerObject);
        }

        if (($inputTypePart instanceof TClassString || $inputTypePart instanceof TLiteralClassString)
            && (get_class($containerTypePart) === TString::class
                || get_class($containerTypePart) === TSingleLetter::class
                || get_class($containerTypePart) === Type\Atomic\GetClassT::class)
        ) {
            return true;
        }

        if ($containerTypePart instanceof TString && $inputTypePart instanceof TNumericString) {
            return true;
        }

        if ($containerTypePart instanceof TNumericString && $inputTypePart instanceof TString) {
            $typeCoerced = true;

            return false;
        }

        if (($containerTypePart instanceof TClassString || $containerTypePart instanceof TLiteralClassString)
            && $inputTypePart instanceof TString
        ) {
            if (\Psalm\Config::getInstance()->allowCoercionFromStringToClassConst) {
                $typeCoerced = true;
            }

            return false;
        }

        if ($containerTypePart instanceof TString &&
            $inputTypePart instanceof TNamedObject
        ) {
            // check whether the object has a __toString method
            if ($codebase->classOrInterfaceExists($inputTypePart->value)
                && $codebase->methodExists($inputTypePart->value . '::__toString')
            ) {
                $toStringCast = true;

                return true;
            }

            // PHP 5.6 doesn't support this natively, so this introduces a bug *just* when checking PHP 5.6 code
            if ($inputTypePart->value === 'ReflectionType') {
                $toStringCast = true;

                return true;
            }
        }

        if ($containerTypePart instanceof Type\Atomic\Fn && $inputTypePart instanceof TCallable) {
            $typeCoerced = true;

            return false;
        }

        if ($containerTypePart instanceof TCallable &&
            (
                $inputTypePart instanceof TString ||
                $inputTypePart instanceof TArray ||
                $inputTypePart instanceof ObjectLike ||
                (
                    $inputTypePart instanceof TNamedObject &&
                    $codebase->classExists($inputTypePart->value) &&
                    $codebase->methodExists($inputTypePart->value . '::__invoke')
                )
            )
        ) {
            // @todo add value checks if possible here
            return true;
        }

        if ($inputTypePart instanceof TNumeric) {
            if ($containerTypePart->isNumericType()) {
                $hasScalarMatch = true;
            }
        }

        if ($inputTypePart instanceof Scalar) {
            if ($containerTypePart instanceof Scalar
                && !$containerTypePart instanceof TLiteralInt
                && !$containerTypePart instanceof TLiteralString
                && !$containerTypePart instanceof TLiteralFloat
            ) {
                $hasScalarMatch = true;
            }
        } elseif ($containerTypePart instanceof TObject &&
            !$inputTypePart instanceof TArray &&
            !$inputTypePart instanceof TResource
        ) {
            return true;
        } elseif ($inputTypePart instanceof TObject && $containerTypePart instanceof TNamedObject) {
            $typeCoerced = true;
        } elseif ($containerTypePart instanceof TNamedObject
            && $inputTypePart instanceof TNamedObject
            && $codebase->classOrInterfaceExists($inputTypePart->value)
            && (
                (
                    $codebase->classExists($containerTypePart->value)
                    && $codebase->classExtendsOrImplements(
                        $containerTypePart->value,
                        $inputTypePart->value
                    )
                )
                ||
                (
                    $codebase->interfaceExists($containerTypePart->value)
                    && $codebase->interfaceExtends(
                        $containerTypePart->value,
                        $inputTypePart->value
                    )
                )
            )
        ) {
            $typeCoerced = true;
        }

        return false;
    }

    /**
     * @param  Codebase    $codebase
     * @param  Type\Atomic $inputTypePart
     * @param  Type\Atomic $containerTypePart
     * @param  bool        &$hasScalarMatch
     * @param  bool        &$typeCoerced
     * @param  bool        &$typeCoercedFromMixed
     * @param  bool        &$toStringCast
     *
     * @return bool
     */
    private static function isMatchingTypeContainedBy(
        Codebase $codebase,
        Type\Atomic $inputTypePart,
        Type\Atomic $containerTypePart,
        &$hasScalarMatch,
        &$typeCoerced,
        &$typeCoercedFromMixed,
        &$toStringCast
    ) {
        $allTypesContain = true;

        if ($containerTypePart instanceof TGenericObject) {
            if (!$inputTypePart instanceof TGenericObject) {
                $typeCoerced = true;
                $typeCoercedFromMixed = true;

                return false;
            }

            foreach ($inputTypePart->typeParams as $i => $inputParam) {
                if (!isset($containerTypePart->typeParams[$i])) {
                    break;
                }

                $containerParam = $containerTypePart->typeParams[$i];

                if (!$inputParam->isEmpty() &&
                    !self::isContainedBy(
                        $codebase,
                        $inputParam,
                        $containerParam,
                        $inputParam->ignoreNullableIssues,
                        $inputParam->ignoreFalsableIssues,
                        $hasScalarMatch,
                        $typeCoerced,
                        $typeCoercedFromMixed
                    )
                ) {
                    $allTypesContain = false;
                }
            }
        }

        if ($containerTypePart instanceof Type\Atomic\Fn) {
            if (!$inputTypePart instanceof Type\Atomic\Fn) {
                $typeCoerced = true;
                $typeCoercedFromMixed = true;

                return false;
            }

            if (self::compareCallable(
                $codebase,
                $inputTypePart,
                $containerTypePart,
                $typeCoerced,
                $typeCoercedFromMixed,
                $hasScalarMatch,
                $allTypesContain
            ) === false
            ) {
                return false;
            }
        }

        if (($inputTypePart instanceof TArray || $inputTypePart instanceof ObjectLike)
            && ($containerTypePart instanceof TArray || $containerTypePart instanceof ObjectLike)
        ) {
            if ($containerTypePart instanceof ObjectLike) {
                $genericContainerTypePart = $containerTypePart->getGenericArrayType();

                $containerParamsCanBeUndefined = (bool) array_reduce(
                    $containerTypePart->properties,
                    /**
                     * @param bool $carry
                     *
                     * @return bool
                     */
                    function ($carry, Type\Union $item) {
                        return $carry || $item->possiblyUndefined;
                    },
                    false
                );

                if (!$inputTypePart instanceof ObjectLike
                    && !$inputTypePart->typeParams[0]->isMixed()
                    && !($inputTypePart->typeParams[1]->isEmpty()
                        && $containerParamsCanBeUndefined)
                ) {
                    $allTypesContain = false;
                    $typeCoerced = true;
                }

                $containerTypePart = $genericContainerTypePart;
            }

            if ($inputTypePart instanceof ObjectLike) {
                $inputTypePart = $inputTypePart->getGenericArrayType();
            }

            foreach ($inputTypePart->typeParams as $i => $inputParam) {
                $containerParam = $containerTypePart->typeParams[$i];

                if ($i === 0
                    && $inputParam->isMixed()
                    && $containerParam->hasString()
                    && $containerParam->hasInt()
                ) {
                    continue;
                }

                if (!$inputParam->isEmpty() &&
                    !self::isContainedBy(
                        $codebase,
                        $inputParam,
                        $containerParam,
                        $inputParam->ignoreNullableIssues,
                        $inputParam->ignoreFalsableIssues,
                        $hasScalarMatch,
                        $typeCoerced,
                        $typeCoercedFromMixed
                    )
                ) {
                    $allTypesContain = false;
                }
            }
        }

        if ($allTypesContain) {
            $toStringCast = false;

            return true;
        }

        return false;
    }

    /**
     * @param  TCallable|Type\Atomic\Fn   $inputTypePart
     * @param  TCallable|Type\Atomic\Fn   $containerTypePart
     * @param  bool   &$typeCoerced
     * @param  bool   &$typeCoercedFromMixed
     * @param  bool   $hasScalarMatch
     * @param  bool   &$allTypesContain
     *
     * @return null|false
     *
     * @psalm-suppress ConflictingReferenceConstraint
     */
    private static function compareCallable(
        Codebase $codebase,
        $inputTypePart,
        $containerTypePart,
        &$typeCoerced,
        &$typeCoercedFromMixed,
        &$hasScalarMatch,
        &$allTypesContain
    ) {
        if ($containerTypePart->params !== null && $inputTypePart->params === null) {
            $typeCoerced = true;
            $typeCoercedFromMixed = true;

            return false;
        }

        if ($containerTypePart->params !== null) {
            foreach ($containerTypePart->params as $i => $containerParam) {
                if (!isset($inputTypePart->params[$i])) {
                    if ($containerParam->isOptional) {
                        break;
                    }

                    $typeCoerced = true;
                    $typeCoercedFromMixed = true;

                    $allTypesContain = false;
                    break;
                }

                $inputParam = $inputTypePart->params[$i];

                if (!self::isContainedBy(
                    $codebase,
                    $inputParam->type ?: Type::getMixed(),
                    $containerParam->type ?: Type::getMixed(),
                    false,
                    false,
                    $hasScalarMatch,
                    $typeCoerced,
                    $typeCoercedFromMixed
                )
                ) {
                    $allTypesContain = false;
                }
            }

            if (isset($containerTypePart->returnType)) {
                if (!isset($inputTypePart->returnType)) {
                    $typeCoerced = true;
                    $typeCoercedFromMixed = true;

                    $allTypesContain = false;
                } else {
                    if (!$containerTypePart->returnType->isVoid()
                        && !self::isContainedBy(
                            $codebase,
                            $inputTypePart->returnType,
                            $containerTypePart->returnType,
                            false,
                            false,
                            $hasScalarMatch,
                            $typeCoerced,
                            $typeCoercedFromMixed
                        )
                    ) {
                        $allTypesContain = false;
                    }
                }
            }
        }
    }

    /**
     * Takes two arrays of types and merges them
     *
     * @param  array<string, Type\Union>  $newTypes
     * @param  array<string, Type\Union>  $existingTypes
     *
     * @return array<string, Type\Union>
     */
    public static function combineKeyedTypes(array $newTypes, array $existingTypes)
    {
        $keys = array_merge(array_keys($newTypes), array_keys($existingTypes));
        $keys = array_unique($keys);

        $resultTypes = [];

        if (empty($newTypes)) {
            return $existingTypes;
        }

        if (empty($existingTypes)) {
            return $newTypes;
        }

        foreach ($keys as $key) {
            if (!isset($existingTypes[$key])) {
                $resultTypes[$key] = $newTypes[$key];
                continue;
            }

            if (!isset($newTypes[$key])) {
                $resultTypes[$key] = $existingTypes[$key];
                continue;
            }

            $existingVarTypes = $existingTypes[$key];
            $newVarTypes = $newTypes[$key];

            if ($newVarTypes->getId() === $existingVarTypes->getId()) {
                $resultTypes[$key] = $newVarTypes;
            } else {
                $resultTypes[$key] = Type::combineUnionTypes($newVarTypes, $existingVarTypes);
            }
        }

        return $resultTypes;
    }

    /**
     * @return Type\Union
     */
    public static function simplifyUnionType(Codebase $codebase, Type\Union $union)
    {
        $unionTypeCount = count($union->getTypes());

        if ($unionTypeCount === 1 || ($unionTypeCount === 2 && $union->isNullable())) {
            return $union;
        }

        $fromDocblock = $union->fromDocblock;
        $ignoreNullableIssues = $union->ignoreNullableIssues;
        $ignoreFalsableIssues = $union->ignoreFalsableIssues;
        $possiblyUndefined = $union->possiblyUndefined;

        $uniqueTypes = [];

        $inverseContains = [];

        foreach ($union->getTypes() as $typePart) {
            $isContainedByOther = false;

            // don't try to simplify intersection types
            if ($typePart instanceof TNamedObject && $typePart->extraTypes) {
                return $union;
            }

            foreach ($union->getTypes() as $containerTypePart) {
                $stringContainerPart = $containerTypePart->getId();
                $stringInputPart = $typePart->getId();

                if ($typePart !== $containerTypePart &&
                    !(
                        $containerTypePart instanceof TInt
                        || $containerTypePart instanceof TFloat
                        || $containerTypePart instanceof TCallable
                        || ($containerTypePart instanceof TString && $typePart instanceof TCallable)
                        || ($containerTypePart instanceof TArray && $typePart instanceof TCallable)
                    ) &&
                    !isset($inverseContains[$stringInputPart][$stringContainerPart]) &&
                    TypeChecker::isAtomicContainedBy(
                        $codebase,
                        $typePart,
                        $containerTypePart,
                        $hasScalarMatch,
                        $typeCoerced,
                        $typeCoercedFromMixed,
                        $toStringCast
                    ) &&
                    !$toStringCast
                ) {
                    $inverseContains[$stringContainerPart][$stringInputPart] = true;

                    $isContainedByOther = true;
                    break;
                }
            }

            if (!$isContainedByOther) {
                $uniqueTypes[] = $typePart;
            }
        }

        if (count($uniqueTypes) === 0) {
            throw new \UnexpectedValueException('There must be more than one unique type');
        }

        $uniqueType = new Type\Union($uniqueTypes);

        $uniqueType->fromDocblock = $fromDocblock;
        $uniqueType->ignoreNullableIssues = $ignoreNullableIssues;
        $uniqueType->ignoreFalsableIssues = $ignoreFalsableIssues;
        $uniqueType->possiblyUndefined = $possiblyUndefined;

        return $uniqueType;
    }
}
