<?php
namespace Psalm\Type;

use Psalm\Exception\TypeParseTreeException;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\ObjectLike;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TEmpty;
use Psalm\Type\Atomic\TEmptyMixed;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TLiteralFloat;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TNumeric;
use Psalm\Type\Atomic\TObject;
use Psalm\Type\Atomic\TResource;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TTrue;
use Psalm\Type\Atomic\TVoid;
use Psalm\Type\ParseTree;
use Psalm\Type\TypeCombination;
use Psalm\Type\Union;

class TypeCombination
{
    /** @var array<string, Atomic> */
    private $valueTypes = [];

    /** @var array<string, array<int, Union>> */
    private $typeParams = [];

    /** @var array<int, bool>|null */
    private $arrayCounts = [];

    /** @var array<string|int, Union> */
    private $objectlikeEntries = [];

    /** @var bool */
    private $objectlikeSealed = true;

    /** @var array<int, Atomic\TLiteralString>|null */
    private $strings = [];

    /** @var array<int, Atomic\TLiteralInt>|null */
    private $ints = [];

    /** @var array<int, Atomic\TLiteralFloat>|null */
    private $floats = [];

    /**
     * Combines types together
     *  - so `int + string = int|string`
     *  - so `array<int> + array<string> = array<int|string>`
     *  - and `array<int> + string = array<int>|string`
     *  - and `array<empty> + array<empty> = array<empty>`
     *  - and `array<string> + array<empty> = array<string>`
     *  - and `array + array<string> = array<mixed>`
     *
     * @param  array<Atomic>    $types
     *
     * @return Union
     * @psalm-suppress TypeCoercion
     */
    public static function combineTypes(array $types)
    {
        if (in_array(null, $types, true)) {
            return Type::getMixed();
        }

        if (count($types) === 1) {
            $unionType = new Union([$types[0]]);

            if ($types[0]->fromDocblock) {
                $unionType->fromDocblock = true;
            }

            return $unionType;
        }

        if (!$types) {
            throw new \InvalidArgumentException('You must pass at least one type to combineTypes');
        }

        $combination = new TypeCombination();

        $fromDocblock = false;

        $hasNull = false;
        $hasMixed = false;
        $hasNonMixed = false;

        foreach ($types as $type) {
            $fromDocblock = $fromDocblock || $type->fromDocblock;

            $result = self::scrapeTypeProperties($type, $combination);

            if ($type instanceof TNull) {
                $hasNull = true;
            }

            if ($type instanceof TMixed) {
                $hasMixed = true;
            } else {
                $hasNonMixed = true;
            }

            if ($result) {
                if ($fromDocblock) {
                    $result->fromDocblock = true;
                }

                return $result;
            }
        }

        if ($hasNull && $hasMixed) {
            return Type::getMixed();
        }

        if (!$hasNonMixed) {
            return Type::getMixed(true);
        }

        if (count($combination->valueTypes) === 1
            && !count($combination->objectlikeEntries)
            && !count($combination->typeParams)
            && !$combination->strings
            && !$combination->ints
            && !$combination->floats
        ) {
            if (isset($combination->valueTypes['false'])) {
                $unionType = Type::getFalse();

                if ($fromDocblock) {
                    $unionType->fromDocblock = true;
                }

                return $unionType;
            }

            if (isset($combination->valueTypes['true'])) {
                $unionType = Type::getTrue();

                if ($fromDocblock) {
                    $unionType->fromDocblock = true;
                }

                return $unionType;
            }
        } elseif (isset($combination->valueTypes['void'])) {
            unset($combination->valueTypes['void']);

            // if we're merging with another type, we cannot represent it in PHP
            $fromDocblock = true;

            if (!isset($combination->valueTypes['null'])) {
                $combination->valueTypes['null'] = new TNull();
            }
        }

        if (isset($combination->valueTypes['true']) && isset($combination->valueTypes['false'])) {
            unset($combination->valueTypes['true'], $combination->valueTypes['false']);

            $combination->valueTypes['bool'] = new TBool();
        }

        $newTypes = [];

        if (count($combination->objectlikeEntries) &&
            (!isset($combination->typeParams['array'])
                || $combination->typeParams['array'][1]->isEmpty())
        ) {
            $objectlike = new ObjectLike($combination->objectlikeEntries);

            if ($combination->objectlikeSealed && !isset($combination->typeParams['array'])) {
                $objectlike->sealed = true;
            }

            $newTypes[] = $objectlike;

            // if we're merging an empty array with an object-like, clobber empty array
            unset($combination->typeParams['array']);
        }

        foreach ($combination->typeParams as $genericType => $genericTypeParams) {
            if ($genericType === 'array') {
                if ($combination->objectlikeEntries) {
                    $objectlikeGenericType = null;

                    $objectlikeKeys = [];

                    foreach ($combination->objectlikeEntries as $propertyName => $propertyType) {
                        if ($objectlikeGenericType) {
                            $objectlikeGenericType = Type::combineUnionTypes(
                                $propertyType,
                                $objectlikeGenericType
                            );
                        } else {
                            $objectlikeGenericType = clone $propertyType;
                        }

                        if (is_int($propertyName)) {
                            if (!isset($objectlikeKeys['int'])) {
                                $objectlikeKeys['int'] = new TInt;
                            }
                        } else {
                            if (!isset($objectlikeKeys['string'])) {
                                $objectlikeKeys['string'] = new TString;
                            }
                        }
                    }

                    if (!$objectlikeGenericType) {
                        throw new \InvalidArgumentException('Cannot be null');
                    }

                    $objectlikeGenericType->possiblyUndefined = false;

                    $objectlikeKeyType = new Type\Union(array_values($objectlikeKeys));

                    $genericTypeParams[0] = Type::combineUnionTypes(
                        $genericTypeParams[0],
                        $objectlikeKeyType
                    );
                    $genericTypeParams[1] = Type::combineUnionTypes(
                        $genericTypeParams[1],
                        $objectlikeGenericType
                    );
                }

                $arrayType = new TArray($genericTypeParams);

                if ($combination->arrayCounts && count($combination->arrayCounts) === 1) {
                    $arrayType->count = array_keys($combination->arrayCounts)[0];
                }

                $newTypes[] = $arrayType;
            } elseif (!isset($combination->valueTypes[$genericType])) {
                $newTypes[] = new TGenericObject($genericType, $genericTypeParams);
            }
        }

        if ($combination->strings) {
            $newTypes = array_merge($newTypes, $combination->strings);
        }

        if ($combination->ints) {
            $newTypes = array_merge($newTypes, $combination->ints);
        }

        if ($combination->floats) {
            $newTypes = array_merge($newTypes, $combination->floats);
        }

        foreach ($combination->valueTypes as $type) {
            if (!($type instanceof TEmpty)
                || (count($combination->valueTypes) === 1
                    && !count($newTypes))
            ) {
                $newTypes[] = $type;
            }
        }

        $unionType = new Union($newTypes);

        if ($fromDocblock) {
            $unionType->fromDocblock = true;
        }

        return $unionType;
    }

    /**
     * @param  Atomic  $type
     * @param  TypeCombination $combination
     *
     * @return null|Union
     */
    private static function scrapeTypeProperties(Atomic $type, TypeCombination $combination)
    {
        if ($type instanceof TMixed) {
            if ($type->fromIsset || $type instanceof TEmptyMixed) {
                return null;
            }

            return Type::getMixed();
        }

        // deal with false|bool => bool
        if (($type instanceof TFalse || $type instanceof TTrue) && isset($combination->valueTypes['bool'])) {
            return null;
        }

        if (get_class($type) === TBool::class && isset($combination->valueTypes['false'])) {
            unset($combination->valueTypes['false']);
        }

        if (get_class($type) === TBool::class && isset($combination->valueTypes['true'])) {
            unset($combination->valueTypes['true']);
        }

        $typeKey = $type->getKey();

        if ($type instanceof TArray || $type instanceof TGenericObject) {
            foreach ($type->typeParams as $i => $typeParam) {
                if (isset($combination->typeParams[$typeKey][$i])) {
                    $combination->typeParams[$typeKey][$i] = Type::combineUnionTypes(
                        $combination->typeParams[$typeKey][$i],
                        $typeParam
                    );
                } else {
                    $combination->typeParams[$typeKey][$i] = $typeParam;
                }
            }

            if ($type instanceof TArray && $combination->arrayCounts !== null) {
                if ($type->count === null) {
                    $combination->arrayCounts = null;
                } else {
                    $combination->arrayCounts[$type->count] = true;
                }
            }
        } elseif ($type instanceof ObjectLike) {
            $existingObjectlikeEntries = (bool) $combination->objectlikeEntries;
            $possiblyUndefinedEntries = $combination->objectlikeEntries;
            $combination->objectlikeSealed = $combination->objectlikeSealed && $type->sealed;

            foreach ($type->properties as $candidatePropertyName => $candidatePropertyType) {
                $valueType = isset($combination->objectlikeEntries[$candidatePropertyName])
                    ? $combination->objectlikeEntries[$candidatePropertyName]
                    : null;

                if (!$valueType) {
                    $combination->objectlikeEntries[$candidatePropertyName] = clone $candidatePropertyType;
                    // it's possibly undefined if there are existing objectlike entries
                    $combination->objectlikeEntries[$candidatePropertyName]->possiblyUndefined
                        = $existingObjectlikeEntries || $candidatePropertyType->possiblyUndefined;
                } else {
                    $combination->objectlikeEntries[$candidatePropertyName] = Type::combineUnionTypes(
                        $valueType,
                        $candidatePropertyType
                    );
                }

                unset($possiblyUndefinedEntries[$candidatePropertyName]);
            }

            if ($combination->arrayCounts !== null) {
                $combination->arrayCounts[count($type->properties)] = true;
            }

            foreach ($possiblyUndefinedEntries as $type) {
                $type->possiblyUndefined = true;
            }
        } else {
            if ($type instanceof TString) {
                if ($type instanceof TLiteralString) {
                    if ($combination->strings !== null && count($combination->strings) < 30) {
                        $combination->strings[] = $type;
                    } else {
                        $combination->strings = null;

                        if (isset($combination->valueTypes['string'])
                            && $combination->valueTypes['string'] instanceof TClassString
                            && $type instanceof TLiteralClassString
                        ) {
                            // do nothing
                        } elseif ($type instanceof TLiteralClassString) {
                            $combination->valueTypes['string'] = new TClassString();
                        } else {
                            $combination->valueTypes['string'] = new TString();
                        }
                    }
                } else {
                    $combination->strings = null;

                    if (!isset($combination->valueTypes['string'])) {
                        $combination->valueTypes[$typeKey] = $type;
                    } elseif (get_class($combination->valueTypes['string']) !== TString::class) {
                        if (get_class($type) === TString::class) {
                            $combination->valueTypes[$typeKey] = $type;
                        } elseif (get_class($combination->valueTypes['string']) !== get_class($type)) {
                            $combination->valueTypes[$typeKey] = new TString();
                        }
                    }
                }
            } elseif ($type instanceof TInt) {
                if ($type instanceof TLiteralInt) {
                    if ($combination->ints !== null && count($combination->ints) < 30) {
                        $combination->ints[] = $type;
                    } else {
                        $combination->ints = null;
                        $combination->valueTypes['int'] = new TInt();
                    }
                } else {
                    $combination->ints = null;
                    $combination->valueTypes[$typeKey] = $type;
                }
            } elseif ($type instanceof TFloat) {
                if ($type instanceof TLiteralFloat) {
                    if ($combination->floats !== null && count($combination->floats) < 30) {
                        $combination->floats[] = $type;
                    } else {
                        $combination->floats = null;
                        $combination->valueTypes['float'] = new TFloat();
                    }
                } else {
                    $combination->floats = null;
                    $combination->valueTypes[$typeKey] = $type;
                }
            } else {
                $combination->valueTypes[$typeKey] = $type;
            }
        }
    }
}
