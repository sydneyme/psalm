<?php
namespace Psalm\Checker;

use PhpParser;
use Psalm\Checker\Statements\Expression\AssertionFinder;
use Psalm\Codebase\CallMap;
use Psalm\CodeLocation;
use Psalm\Issue\InvalidArgument;
use Psalm\Issue\InvalidReturnType;
use Psalm\IssueBuffer;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Reconciler;
use Psalm\Type\TypeCombination;

class FunctionChecker extends FunctionLikeChecker
{
    /**
     * @param StatementsSource              $source
     */
    public function __construct(PhpParser\Node\Stmt\Function_ $function, StatementsSource $source)
    {
        parent::__construct($function, $source);
    }

    /**
     * @param  string                      $functionId
     * @param  array<PhpParser\Node\Arg>   $callArgs
     * @param  CodeLocation                $codeLocation
     * @param  array                       $suppressedIssues
     *
     * @return Type\Union
     */
    public static function getReturnTypeFromCallMapWithArgs(
        StatementsChecker $statementsChecker,
        $functionId,
        array $callArgs,
        CodeLocation $codeLocation,
        array $suppressedIssues
    ) {
        $callMapKey = strtolower($functionId);

        $callMap = CallMap::getCallMap();

        if (!isset($callMap[$callMapKey])) {
            throw new \InvalidArgumentException('Function ' . $functionId . ' was not found in callmap');
        }

        if (!$callArgs) {
            switch ($callMapKey) {
                case 'getenv':
                    return new Type\Union([new Type\Atomic\TArray([Type::getMixed(), Type::getString()])]);

                case 'gettimeofday':
                    return new Type\Union([
                        new Type\Atomic\TArray([
                            Type::getString(),
                            Type::getInt()
                        ])
                    ]);
            }
        } else {
            switch ($callMapKey) {
                case 'str_replace':
                case 'str_ireplace':
                case 'substr_replace':
                case 'preg_replace':
                case 'preg_replace_callback':
                    if (isset($callArgs[2]->value->inferredType)) {
                        $subjectType = $callArgs[2]->value->inferredType;

                        if (!$subjectType->hasString() && $subjectType->hasArray()) {
                            return Type::getArray();
                        }

                        $returnType = Type::getString();

                        if (in_array($callMapKey, ['preg_replace', 'preg_replace_callback'], true)) {
                            $returnType->addType(new Type\Atomic\TNull());
                            $returnType->ignoreNullableIssues = true;
                        }

                        return $returnType;
                    }

                    return Type::getMixed();

                case 'pathinfo':
                    if (isset($callArgs[1])) {
                        return Type::getString();
                    }

                    return Type::getArray();

                case 'count':
                    if (isset($callArgs[0]->value->inferredType)) {
                        $atomicTypes = $callArgs[0]->value->inferredType->getTypes();

                        if (count($atomicTypes) === 1 && isset($atomicTypes['array'])) {
                            if ($atomicTypes['array'] instanceof Type\Atomic\TArray) {
                                return new Type\Union([
                                    $atomicTypes['array']->count !== null
                                        ? new Type\Atomic\TLiteralInt($atomicTypes['array']->count)
                                        : new Type\Atomic\TInt
                                ]);
                            } elseif ($atomicTypes['array'] instanceof Type\Atomic\ObjectLike
                                && $atomicTypes['array']->sealed
                            ) {
                                return new Type\Union([
                                    new Type\Atomic\TLiteralInt(count($atomicTypes['array']->properties))
                                ]);
                            }
                        }
                    }

                    break;

                case 'var_export':
                case 'highlight_string':
                case 'highlight_file':
                    if (isset($callArgs[1]->value->inferredType)) {
                        $subjectType = $callArgs[1]->value->inferredType;

                        if ((string) $subjectType === 'true') {
                            return Type::getString();
                        }

                        return new Type\Union([
                            new Type\Atomic\TString,
                            $callMapKey === 'var_export' ? new Type\Atomic\TNull : new Type\Atomic\TBool
                        ]);
                    }

                    return $callMapKey === 'var_export' ? Type::getVoid() : Type::getBool();

                case 'getenv':
                    return new Type\Union([new Type\Atomic\TString, new Type\Atomic\TFalse]);

                case 'gettimeofday':
                    if (isset($callArgs[0]->value->inferredType)) {
                        $subjectType = $callArgs[0]->value->inferredType;

                        if ((string) $subjectType === 'true') {
                            return Type::getFloat();
                        }

                        if ((string) $subjectType === 'false') {
                            return new Type\Union([
                                new Type\Atomic\TArray([
                                    Type::getString(),
                                    Type::getInt()
                                ])
                            ]);
                        }
                    }

                    break;

                case 'array_map':
                    return self::getArrayMapReturnType(
                        $statementsChecker,
                        $callArgs
                    );

                case 'array_filter':
                    return self::getArrayFilterReturnType(
                        $statementsChecker,
                        $callArgs,
                        $codeLocation,
                        $suppressedIssues
                    );

                case 'array_reduce':
                    return self::getArrayReduceReturnType(
                        $statementsChecker,
                        $callArgs
                    );

                case 'array_merge':
                    return self::getArrayMergeReturnType($callArgs);

                case 'array_rand':
                    return self::getArrayRandReturnType($callArgs);

                case 'explode':
                    if ($callArgs[0]->value instanceof PhpParser\Node\Scalar\String_) {
                        if ($callArgs[0]->value->value === '') {
                            return Type::getFalse();
                        }

                        return new Type\Union([
                            new Type\Atomic\TArray([
                                Type::getInt(),
                                Type::getString()
                            ])
                        ]);
                    }

                    break;

                case 'iterator_to_array':
                    if (isset($callArgs[0]->value->inferredType)
                        && $callArgs[0]->value->inferredType->hasObjectType()
                    ) {
                        $valueType = null;

                        foreach ($callArgs[0]->value->inferredType->getTypes() as $callArgAtomicType) {
                            if ($callArgAtomicType instanceof Type\Atomic\TGenericObject) {
                                $typeParams = $callArgAtomicType->typeParams;
                                $lastParamType = $typeParams[count($typeParams) - 1];

                                $valueType = $valueType
                                    ? Type::combineUnionTypes($valueType, $lastParamType)
                                    : $lastParamType;
                            }
                        }

                        if ($valueType) {
                            return new Type\Union([
                                new Type\Atomic\TArray([
                                    Type::getMixed(),
                                    $valueType
                                ])
                            ]);
                        }
                    }

                    break;

                case 'array_column':
                    $rowShape = null;
                    // calculate row shape
                    if (isset($callArgs[0]->value->inferredType)
                        && $callArgs[0]->value->inferredType->isSingle()
                        && $callArgs[0]->value->inferredType->hasArray()
                    ) {
                        $inputArray = $callArgs[0]->value->inferredType->getTypes()['array'];
                        if ($inputArray instanceof Type\Atomic\ObjectLike) {
                            $rowType = $inputArray->getGenericArrayType()->typeParams[1];
                            if ($rowType->isSingle() && $rowType->hasArray()) {
                                $rowShape = $rowType->getTypes()['array'];
                            }
                        } elseif ($inputArray instanceof Type\Atomic\TArray) {
                            $rowType = $inputArray->typeParams[1];
                            if ($rowType->isSingle() && $rowType->hasArray()) {
                                $rowShape = $rowType->getTypes()['array'];
                            }
                        }
                    }

                    $valueColumnName = null;
                    // calculate value column name
                    if (isset($callArgs[1]->value->inferredType)) {
                        $valueColumnNameArg= $callArgs[1]->value->inferredType;
                        if ($valueColumnNameArg->isSingleIntLiteral()) {
                            $valueColumnName = $valueColumnNameArg->getSingleIntLiteral()->value;
                        } elseif ($valueColumnNameArg->isSingleStringLiteral()) {
                            $valueColumnName = $valueColumnNameArg->getSingleStringLiteral()->value;
                        }
                    }

                    $keyColumnName = null;
                    // calculate key column name
                    if (isset($callArgs[2]->value->inferredType)) {
                        $keyColumnNameArg = $callArgs[2]->value->inferredType;
                        if ($keyColumnNameArg->isSingleIntLiteral()) {
                            $keyColumnName = $keyColumnNameArg->getSingleIntLiteral()->value;
                        } elseif ($keyColumnNameArg->isSingleStringLiteral()) {
                            $keyColumnName = $keyColumnNameArg->getSingleStringLiteral()->value;
                        }
                    }

                    $resultKeyType = Type::getMixed();
                    $resultElementType = null;
                    // calculate results
                    if ($rowShape instanceof Type\Atomic\ObjectLike) {
                        if ((null !== $valueColumnName) && isset($rowShape->properties[$valueColumnName])) {
                            $resultElementType = $rowShape->properties[$valueColumnName];
                        } else {
                            $resultElementType = Type::getMixed();
                        }

                        if ((null !== $keyColumnName) && isset($rowShape->properties[$keyColumnName])) {
                            $resultKeyType = $rowShape->properties[$keyColumnName];
                        }
                    }

                    if ($resultElementType) {
                        return new Type\Union([
                            new Type\Atomic\TArray([
                                $resultKeyType,
                                $resultElementType
                            ])
                        ]);
                    }
                    break;

                case 'abs':
                    if (isset($callArgs[0]->value)) {
                        $firstArg = $callArgs[0]->value;

                        if (isset($firstArg->inferredType)) {
                            $numericTypes = [];

                            foreach ($firstArg->inferredType->getTypes() as $innerType) {
                                if ($innerType->isNumericType()) {
                                    $numericTypes[] = $innerType;
                                }
                            }

                            if ($numericTypes) {
                                return new Type\Union($numericTypes);
                            }
                        }
                    }

                    break;

                case 'version_compare':
                    if (count($callArgs) > 2) {
                        if (isset($callArgs[2]->value->inferredType)) {
                            $operatorType = $callArgs[2]->value->inferredType;

                            if (!$operatorType->isMixed()) {
                                $projectChecker = $statementsChecker->getFileChecker()->projectChecker;
                                $codebase = $projectChecker->codebase;

                                $acceptableOperatorType = new Type\Union([
                                    new Type\Atomic\TLiteralString('<'),
                                    new Type\Atomic\TLiteralString('lt'),
                                    new Type\Atomic\TLiteralString('<='),
                                    new Type\Atomic\TLiteralString('le'),
                                    new Type\Atomic\TLiteralString('>'),
                                    new Type\Atomic\TLiteralString('gt'),
                                    new Type\Atomic\TLiteralString('>='),
                                    new Type\Atomic\TLiteralString('ge'),
                                    new Type\Atomic\TLiteralString('=='),
                                    new Type\Atomic\TLiteralString('='),
                                    new Type\Atomic\TLiteralString('eq'),
                                    new Type\Atomic\TLiteralString('!='),
                                    new Type\Atomic\TLiteralString('<>'),
                                    new Type\Atomic\TLiteralString('ne'),
                                ]);

                                if (TypeChecker::isContainedBy(
                                    $codebase,
                                    $operatorType,
                                    $acceptableOperatorType
                                )) {
                                    return Type::getBool();
                                }
                            }
                        }

                        return new Type\Union([
                            new Type\Atomic\TBool,
                            new Type\Atomic\TNull
                        ]);
                    }

                    return new Type\Union([
                        new Type\Atomic\TLiteralInt(-1),
                        new Type\Atomic\TLiteralInt(0),
                        new Type\Atomic\TLiteralInt(1)
                    ]);

                case 'parse_url':
                    if (count($callArgs) > 1) {
                        if (isset($callArgs[1]->value->inferredType)) {
                            $componentType = $callArgs[1]->value->inferredType;

                            if (!$componentType->isMixed()) {
                                $projectChecker = $statementsChecker->getFileChecker()->projectChecker;
                                $codebase = $projectChecker->codebase;

                                $acceptableStringComponentType = new Type\Union([
                                    new Type\Atomic\TLiteralInt(PHP_URL_SCHEME),
                                    new Type\Atomic\TLiteralInt(PHP_URL_USER),
                                    new Type\Atomic\TLiteralInt(PHP_URL_PASS),
                                    new Type\Atomic\TLiteralInt(PHP_URL_HOST),
                                    new Type\Atomic\TLiteralInt(PHP_URL_PATH),
                                    new Type\Atomic\TLiteralInt(PHP_URL_QUERY),
                                    new Type\Atomic\TLiteralInt(PHP_URL_FRAGMENT),
                                ]);

                                $acceptableIntComponentType = new Type\Union([
                                    new Type\Atomic\TLiteralInt(PHP_URL_PORT)
                                ]);

                                if (TypeChecker::isContainedBy(
                                    $codebase,
                                    $componentType,
                                    $acceptableStringComponentType
                                )) {
                                    $nullableString = new Type\Union([
                                        new Type\Atomic\TString,
                                        new Type\Atomic\TNull
                                    ]);

                                    $nullableString->ignoreNullableIssues = true;

                                    return $nullableString;
                                }

                                if (TypeChecker::isContainedBy(
                                    $codebase,
                                    $componentType,
                                    $acceptableIntComponentType
                                )) {
                                    $nullableInt = new Type\Union([
                                        new Type\Atomic\TInt,
                                        new Type\Atomic\TNull
                                    ]);

                                    $nullableInt->ignoreNullableIssues = true;

                                    return $nullableInt;
                                }
                            }
                        }

                        $nullableStringOrInt = new Type\Union([
                            new Type\Atomic\TString,
                            new Type\Atomic\TInt,
                            new Type\Atomic\TNull
                        ]);

                        $nullableStringOrInt->ignoreNullableIssues = true;

                        return $nullableStringOrInt;
                    }

                    $componentKeyType = new Type\Union([
                        new Type\Atomic\TLiteralString('scheme'),
                        new Type\Atomic\TLiteralString('user'),
                        new Type\Atomic\TLiteralString('pass'),
                        new Type\Atomic\TLiteralString('host'),
                        new Type\Atomic\TLiteralString('port'),
                        new Type\Atomic\TLiteralString('path'),
                        new Type\Atomic\TLiteralString('query'),
                        new Type\Atomic\TLiteralString('fragment'),
                    ]);

                    $nullableStringOrInt = new Type\Union([
                        new Type\Atomic\TArray([$componentKeyType, Type::getMixed()]),
                        new Type\Atomic\TFalse
                    ]);

                    $nullableStringOrInt->ignoreFalsableIssues = true;

                    return $nullableStringOrInt;

                case 'min':
                case 'max':
                    if (isset($callArgs[0])) {
                        $firstArg = $callArgs[0]->value;

                        if (isset($firstArg->inferredType)) {
                            if ($firstArg->inferredType->hasArray()) {
                                $arrayType = $firstArg->inferredType->getTypes()['array'];
                                if ($arrayType instanceof Type\Atomic\ObjectLike) {
                                    return $arrayType->getGenericValueType();
                                }

                                if ($arrayType instanceof Type\Atomic\TArray) {
                                    return clone $arrayType->typeParams[1];
                                }
                            } elseif ($firstArg->inferredType->hasScalarType() &&
                                ($secondArg = $callArgs[1]->value) &&
                                isset($secondArg->inferredType) &&
                                $secondArg->inferredType->hasScalarType()
                            ) {
                                return Type::combineUnionTypes($firstArg->inferredType, $secondArg->inferredType);
                            }
                        }
                    }

                    break;
            }
        }

        if (!$callMap[$callMapKey][0]) {
            return Type::getMixed();
        }

        $callMapReturnType = Type::parseString($callMap[$callMapKey][0]);

        switch ($callMapKey) {
            case 'mb_strpos':
            case 'mb_strrpos':
            case 'mb_stripos':
            case 'mb_strripos':
            case 'strpos':
            case 'strrpos':
            case 'stripos':
            case 'strripos':
                break;

            default:
                if ($callMapReturnType->isFalsable()) {
                    $callMapReturnType->ignoreFalsableIssues = true;
                }
        }

        return $callMapReturnType;
    }

    /**
     * @param  array<PhpParser\Node\Arg>    $callArgs
     *
     * @return Type\Union
     */
    private static function getArrayMergeReturnType(array $callArgs)
    {
        $innerValueTypes = [];
        $innerKeyTypes = [];

        $genericProperties = [];

        foreach ($callArgs as $callArg) {
            if (!isset($callArg->value->inferredType)) {
                return Type::getArray();
            }

            foreach ($callArg->value->inferredType->getTypes() as $typePart) {
                if ($callArg->unpack) {
                    if (!$typePart instanceof Type\Atomic\TArray) {
                        if ($typePart instanceof Type\Atomic\ObjectLike) {
                            $typePartValueType = $typePart->getGenericValueType();
                        } else {
                            return Type::getArray();
                        }
                    } else {
                        $typePartValueType = $typePart->typeParams[1];
                    }

                    $unpackedTypeParts = [];

                    foreach ($typePartValueType->getTypes() as $valueTypePart) {
                        $unpackedTypeParts[] = $valueTypePart;
                    }
                } else {
                    $unpackedTypeParts = [$typePart];
                }

                foreach ($unpackedTypeParts as $unpackedTypePart) {
                    if (!$unpackedTypePart instanceof Type\Atomic\TArray) {
                        if ($unpackedTypePart instanceof Type\Atomic\ObjectLike) {
                            if ($genericProperties !== null) {
                                $genericProperties = array_merge(
                                    $genericProperties,
                                    $unpackedTypePart->properties
                                );
                            }

                            $unpackedTypePart = $unpackedTypePart->getGenericArrayType();
                        } else {
                            if ($unpackedTypePart instanceof Type\Atomic\TMixed
                                && $unpackedTypePart->fromIsset
                            ) {
                                $unpackedTypePart = new Type\Atomic\TArray([
                                    Type::getMixed(),
                                    Type::getMixed(true)
                                ]);
                            } else {
                                return Type::getArray();
                            }
                        }
                    } elseif (!$unpackedTypePart->typeParams[0]->isEmpty()) {
                        $genericProperties = null;
                    }

                    if ($unpackedTypePart->typeParams[1]->isEmpty()) {
                        continue;
                    }

                    $innerKeyTypes = array_merge(
                        $innerKeyTypes,
                        array_values($unpackedTypePart->typeParams[0]->getTypes())
                    );
                    $innerValueTypes = array_merge(
                        $innerValueTypes,
                        array_values($unpackedTypePart->typeParams[1]->getTypes())
                    );
                }
            }
        }

        if ($genericProperties) {
            return new Type\Union([
                new Type\Atomic\ObjectLike($genericProperties),
            ]);
        }

        if ($innerValueTypes) {
            return new Type\Union([
                new Type\Atomic\TArray([
                    TypeCombination::combineTypes($innerKeyTypes),
                    TypeCombination::combineTypes($innerValueTypes),
                ]),
            ]);
        }

        return Type::getArray();
    }

    /**
     * @param  array<PhpParser\Node\Arg>    $callArgs
     *
     * @return Type\Union
     */
    private static function getArrayRandReturnType(array $callArgs)
    {
        $firstArg = isset($callArgs[0]->value) ? $callArgs[0]->value : null;
        $secondArg = isset($callArgs[1]->value) ? $callArgs[1]->value : null;

        $firstArgArray = $firstArg
            && isset($firstArg->inferredType)
            && $firstArg->inferredType->hasType('array')
            && ($arrayAtomicType = $firstArg->inferredType->getTypes()['array'])
            && ($arrayAtomicType instanceof Type\Atomic\TArray ||
                $arrayAtomicType instanceof Type\Atomic\ObjectLike)
        ? $arrayAtomicType
        : null;

        if (!$firstArgArray) {
            return Type::getMixed();
        }

        if ($firstArgArray instanceof Type\Atomic\TArray) {
            $keyType = clone $firstArgArray->typeParams[0];
        } else {
            $keyType = $firstArgArray->getGenericKeyType();
        }

        if (!$secondArg
            || ($secondArg instanceof PhpParser\Node\Scalar\LNumber && $secondArg->value === 1)
        ) {
            return $keyType;
        }

        $arrType = new Type\Union([
            new Type\Atomic\TArray([
                Type::getInt(),
                $keyType,
            ]),
        ]);

        if ($secondArg instanceof PhpParser\Node\Scalar\LNumber) {
            return $arrType;
        }

        return Type::combineUnionTypes($keyType, $arrType);
    }

    /**
     * @param  array<PhpParser\Node\Arg>    $callArgs
     * @param  CodeLocation                 $codeLocation
     * @param  array                        $suppressedIssues
     *
     * @return Type\Union
     */
    private static function getArrayMapReturnType(
        StatementsChecker $statementsChecker,
        $callArgs
    ) {
        $arrayArg = isset($callArgs[1]->value) ? $callArgs[1]->value : null;

        $arrayArgType = null;

        if ($arrayArg && isset($arrayArg->inferredType)) {
            $argTypes = $arrayArg->inferredType->getTypes();

            if (isset($argTypes['array'])
                && ($argTypes['array'] instanceof Type\Atomic\TArray
                    || $argTypes['array'] instanceof Type\Atomic\ObjectLike)
            ) {
                $arrayArgType = $argTypes['array'];
            }
        }

        if (isset($callArgs[0])) {
            $functionCallArg = $callArgs[0];

            if (count($callArgs) === 2) {
                if ($arrayArgType instanceof Type\Atomic\ObjectLike) {
                    $genericKeyType = $arrayArgType->getGenericKeyType();
                } else {
                    $genericKeyType = $arrayArgType ? clone $arrayArgType->typeParams[0] : Type::getMixed();
                }
            } else {
                $genericKeyType = Type::getInt();
            }

            if (isset($functionCallArg->value->inferredType)
                && ($firstArgAtomicTypes = $functionCallArg->value->inferredType->getTypes())
                && ($closureAtomicType = isset($firstArgAtomicTypes['Closure'])
                    ? $firstArgAtomicTypes['Closure']
                    : null)
                && $closureAtomicType instanceof Type\Atomic\Fn
            ) {
                $closureReturnType = $closureAtomicType->returnType ?: Type::getMixed();

                if ($closureReturnType->isVoid()) {
                    $closureReturnType = Type::getNull();
                }

                $innerType = clone $closureReturnType;

                if ($arrayArgType instanceof Type\Atomic\ObjectLike && count($callArgs) === 2) {
                    return new Type\Union([
                        new Type\Atomic\ObjectLike(
                            array_map(
                                /**
                                 * @return Type\Union
                                 */
                                function (Type\Union $_) use ($innerType) {
                                    return clone $innerType;
                                },
                                $arrayArgType->properties
                            )
                        ),
                    ]);
                }

                return new Type\Union([
                    new Type\Atomic\TArray([
                        $genericKeyType,
                        $innerType,
                    ]),
                ]);
            } elseif ($functionCallArg->value instanceof PhpParser\Node\Scalar\String_
                || $functionCallArg->value instanceof PhpParser\Node\Expr\Array_
            ) {
                $mappingFunctionIds = Statements\Expression\CallChecker::getFunctionIdsFromCallableArg(
                    $statementsChecker,
                    $functionCallArg->value
                );

                $callMap = CallMap::getCallMap();

                $mappingReturnType = null;

                $projectChecker = $statementsChecker->getFileChecker()->projectChecker;
                $codebase = $projectChecker->codebase;

                foreach ($mappingFunctionIds as $mappingFunctionId) {
                    $mappingFunctionId = strtolower($mappingFunctionId);

                    $mappingFunctionIdParts = explode('&', $mappingFunctionId);

                    $partMatchFound = false;

                    foreach ($mappingFunctionIdParts as $mappingFunctionIdPart) {
                        if (isset($callMap[$mappingFunctionIdPart][0])) {
                            if ($callMap[$mappingFunctionIdPart][0]) {
                                $mappedFunctionReturn =
                                    Type::parseString($callMap[$mappingFunctionIdPart][0]);

                                if ($mappingReturnType) {
                                    $mappingReturnType = Type::combineUnionTypes(
                                        $mappingReturnType,
                                        $mappedFunctionReturn
                                    );
                                } else {
                                    $mappingReturnType = $mappedFunctionReturn;
                                }

                                $partMatchFound = true;
                            }
                        } else {
                            if (strpos($mappingFunctionIdPart, '::') !== false) {
                                list($callableFqClassName) = explode('::', $mappingFunctionIdPart);

                                if (in_array($callableFqClassName, ['self', 'static', 'parent'], true)) {
                                    continue;
                                }

                                if (!$codebase->methodExists($mappingFunctionIdPart)) {
                                    continue;
                                }

                                $partMatchFound = true;

                                $selfClass = 'self';

                                $returnType = $codebase->methods->getMethodReturnType(
                                    $mappingFunctionIdPart,
                                    $selfClass
                                ) ?: Type::getMixed();

                                if ($mappingReturnType) {
                                    $mappingReturnType = Type::combineUnionTypes(
                                        $mappingReturnType,
                                        $returnType
                                    );
                                } else {
                                    $mappingReturnType = $returnType;
                                }
                            } else {
                                if (!$codebase->functions->functionExists(
                                    $statementsChecker,
                                    $mappingFunctionIdPart
                                )) {
                                    $mappingReturnType = Type::getMixed();
                                    continue;
                                }

                                $partMatchFound = true;

                                $functionStorage = $codebase->functions->getStorage(
                                    $statementsChecker,
                                    $mappingFunctionIdPart
                                );

                                $returnType = $functionStorage->returnType ?: Type::getMixed();

                                if ($mappingReturnType) {
                                    $mappingReturnType = Type::combineUnionTypes(
                                        $mappingReturnType,
                                        $returnType
                                    );
                                } else {
                                    $mappingReturnType = $returnType;
                                }
                            }
                        }
                    }

                    if ($partMatchFound === false) {
                        $mappingReturnType = Type::getMixed();
                    }
                }

                if ($mappingReturnType) {
                    if ($arrayArgType instanceof Type\Atomic\ObjectLike && count($callArgs) === 2) {
                        return new Type\Union([
                            new Type\Atomic\ObjectLike(
                                array_map(
                                    /**
                                     * @return Type\Union
                                     */
                                    function (Type\Union $_) use ($mappingReturnType) {
                                        return clone $mappingReturnType;
                                    },
                                    $arrayArgType->properties
                                )
                            ),
                        ]);
                    }

                    return new Type\Union([
                        new Type\Atomic\TArray([
                            $genericKeyType,
                            $mappingReturnType,
                        ]),
                    ]);
                }
            }
        }

        return Type::getArray();
    }

    /**
     * @param  array<PhpParser\Node\Arg>    $callArgs
     * @param  CodeLocation                 $codeLocation
     * @param  array                        $suppressedIssues
     *
     * @return Type\Union
     */
    private static function getArrayReduceReturnType(
        StatementsChecker $statementsChecker,
        $callArgs
    ) {
        if (!isset($callArgs[0]) || !isset($callArgs[1])) {
            return Type::getMixed();
        }

        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;

        $arrayArg = $callArgs[0]->value;
        $functionCallArg = $callArgs[1]->value;

        if (!isset($arrayArg->inferredType) || !isset($functionCallArg->inferredType)) {
            return Type::getMixed();
        }

        $arrayArgType = null;

        $arrayArgTypes = $arrayArg->inferredType->getTypes();

        if (isset($arrayArgTypes['array'])
            && ($arrayArgTypes['array'] instanceof Type\Atomic\TArray
                || $arrayArgTypes['array'] instanceof Type\Atomic\ObjectLike)
        ) {
            $arrayArgType = $arrayArgTypes['array'];

            if ($arrayArgType instanceof Type\Atomic\ObjectLike) {
                $arrayArgType = $arrayArgType->getGenericArrayType();
            }
        }

        if (!isset($callArgs[2])) {
            $reduceReturnType = Type::getNull();
            $reduceReturnType->ignoreNullableIssues = true;
        } else {
            if (!isset($callArgs[2]->value->inferredType)) {
                return Type::getMixed();
            }

            $reduceReturnType = $callArgs[2]->value->inferredType;

            if ($reduceReturnType->isMixed()) {
                return Type::getMixed();
            }
        }

        $initialType = $reduceReturnType;

        if (($firstArgAtomicTypes = $functionCallArg->inferredType->getTypes())
            && ($closureAtomicType = isset($firstArgAtomicTypes['Closure'])
                ? $firstArgAtomicTypes['Closure']
                : null)
            && $closureAtomicType instanceof Type\Atomic\Fn
        ) {
            $closureReturnType = $closureAtomicType->returnType ?: Type::getMixed();

            if ($closureReturnType->isVoid()) {
                $closureReturnType = Type::getNull();
            }

            $reduceReturnType = Type::combineUnionTypes($closureReturnType, $reduceReturnType);

            if ($closureAtomicType->params !== null) {
                if (count($closureAtomicType->params) < 2) {
                    if (IssueBuffer::accepts(
                        new InvalidArgument(
                            'The closure passed to array_reduce needs two params',
                            new CodeLocation($statementsChecker->getSource(), $functionCallArg)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }

                    return Type::getMixed();
                }

                $carryParam = $closureAtomicType->params[0];
                $itemParam = $closureAtomicType->params[1];

                if ($carryParam->type
                    && (!TypeChecker::isContainedBy(
                        $projectChecker->codebase,
                        $initialType,
                        $carryParam->type
                    ) || (!$reduceReturnType->isMixed()
                            && !TypeChecker::isContainedBy(
                                $projectChecker->codebase,
                                $reduceReturnType,
                                $carryParam->type
                            )
                        )
                    )
                ) {
                    if (IssueBuffer::accepts(
                        new InvalidArgument(
                            'The first param of the closure passed to array_reduce must take '
                                . $reduceReturnType . ' but only accepts ' . $carryParam->type,
                            $carryParam->typeLocation
                                ?: new CodeLocation($statementsChecker->getSource(), $functionCallArg)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }

                    return Type::getMixed();
                }

                if ($itemParam->type
                    && $arrayArgType
                    && !$arrayArgType->typeParams[1]->isMixed()
                    && !TypeChecker::isContainedBy(
                        $projectChecker->codebase,
                        $arrayArgType->typeParams[1],
                        $itemParam->type
                    )
                ) {
                    if (IssueBuffer::accepts(
                        new InvalidArgument(
                            'The second param of the closure passed to array_reduce must take '
                                . $arrayArgType->typeParams[1] . ' but only accepts ' . $itemParam->type,
                            $itemParam->typeLocation
                                ?: new CodeLocation($statementsChecker->getSource(), $functionCallArg)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }

                    return Type::getMixed();
                }
            }

            return $reduceReturnType;
        }

        if ($functionCallArg instanceof PhpParser\Node\Scalar\String_
            || $functionCallArg instanceof PhpParser\Node\Expr\Array_
        ) {
            $mappingFunctionIds = Statements\Expression\CallChecker::getFunctionIdsFromCallableArg(
                $statementsChecker,
                $functionCallArg
            );

            $callMap = CallMap::getCallMap();

            $projectChecker = $statementsChecker->getFileChecker()->projectChecker;
            $codebase = $projectChecker->codebase;

            foreach ($mappingFunctionIds as $mappingFunctionId) {
                $mappingFunctionId = strtolower($mappingFunctionId);

                $mappingFunctionIdParts = explode('&', $mappingFunctionId);

                $partMatchFound = false;

                foreach ($mappingFunctionIdParts as $mappingFunctionIdPart) {
                    if (isset($callMap[$mappingFunctionIdPart][0])) {
                        if ($callMap[$mappingFunctionIdPart][0]) {
                            $mappedFunctionReturn =
                                Type::parseString($callMap[$mappingFunctionIdPart][0]);

                            $reduceReturnType = Type::combineUnionTypes(
                                $reduceReturnType,
                                $mappedFunctionReturn
                            );

                            $partMatchFound = true;
                        }
                    } else {
                        if (strpos($mappingFunctionIdPart, '::') !== false) {
                            list($callableFqClassName) = explode('::', $mappingFunctionIdPart);

                            if (in_array($callableFqClassName, ['self', 'static', 'parent'], true)) {
                                continue;
                            }

                            if (!$codebase->methodExists($mappingFunctionIdPart)) {
                                continue;
                            }

                            $partMatchFound = true;

                            $selfClass = 'self';

                            $returnType = $codebase->methods->getMethodReturnType(
                                $mappingFunctionIdPart,
                                $selfClass
                            ) ?: Type::getMixed();

                            $reduceReturnType = Type::combineUnionTypes(
                                $reduceReturnType,
                                $returnType
                            );
                        } else {
                            if (!$codebase->functions->functionExists(
                                $statementsChecker,
                                $mappingFunctionIdPart
                            )) {
                                return Type::getMixed();
                            }

                            $partMatchFound = true;

                            $functionStorage = $codebase->functions->getStorage(
                                $statementsChecker,
                                $mappingFunctionIdPart
                            );

                            $returnType = $functionStorage->returnType ?: Type::getMixed();

                            $reduceReturnType = Type::combineUnionTypes(
                                $reduceReturnType,
                                $returnType
                            );
                        }
                    }
                }

                if ($partMatchFound === false) {
                    return Type::getMixed();
                }
            }

            return $reduceReturnType;
        }

        return Type::getMixed();
    }

    /**
     * @param  array<PhpParser\Node\Arg>    $callArgs
     * @param  CodeLocation                 $codeLocation
     * @param  array                        $suppressedIssues
     *
     * @return Type\Union
     */
    private static function getArrayFilterReturnType(
        StatementsChecker $statementsChecker,
        $callArgs,
        CodeLocation $codeLocation,
        array $suppressedIssues
    ) {
        $arrayArg = isset($callArgs[0]->value) ? $callArgs[0]->value : null;

        $firstArgArray = $arrayArg
            && isset($arrayArg->inferredType)
            && $arrayArg->inferredType->hasType('array')
            && ($arrayAtomicType = $arrayArg->inferredType->getTypes()['array'])
            && ($arrayAtomicType instanceof Type\Atomic\TArray ||
                $arrayAtomicType instanceof Type\Atomic\ObjectLike)
            ? $arrayAtomicType
            : null;

        if (!$firstArgArray) {
            return Type::getArray();
        }

        if ($firstArgArray instanceof Type\Atomic\TArray) {
            $innerType = $firstArgArray->typeParams[1];
            $keyType = clone $firstArgArray->typeParams[0];
        } else {
            $innerType = $firstArgArray->getGenericValueType();
            $keyType = $firstArgArray->getGenericKeyType();
        }

        if (!isset($callArgs[1])) {
            $innerType->removeType('null');
            $innerType->removeType('false');
        } elseif (!isset($callArgs[2])) {
            $functionCallArg = $callArgs[1];

            if ($functionCallArg->value instanceof PhpParser\Node\Expr\Closure
                && isset($functionCallArg->value->inferredType)
                && ($closureAtomicType = $functionCallArg->value->inferredType->getTypes()['Closure'])
                && $closureAtomicType instanceof Type\Atomic\Fn
            ) {
                $closureReturnType = $closureAtomicType->returnType ?: Type::getMixed();

                if ($closureReturnType->isVoid()) {
                    IssueBuffer::accepts(
                        new InvalidReturnType(
                            'No return type could be found in the closure passed to array_filter',
                            $codeLocation
                        ),
                        $suppressedIssues
                    );

                    return Type::getArray();
                }

                if (count($functionCallArg->value->stmts) === 1 && count($functionCallArg->value->params)) {
                    $firstParam = $functionCallArg->value->params[0];
                    $stmt = $functionCallArg->value->stmts[0];

                    if ($firstParam->variadic === false
                        && $firstParam->var instanceof PhpParser\Node\Expr\Variable
                        && is_string($firstParam->var->name)
                        && $stmt instanceof PhpParser\Node\Stmt\Return_
                        && $stmt->expr
                    ) {
                        AssertionFinder::scrapeAssertions($stmt->expr, null, $statementsChecker);

                        $assertions = isset($stmt->expr->assertions) ? $stmt->expr->assertions : null;

                        if (isset($assertions['$' . $firstParam->var->name])) {
                            $changedVarIds = [];

                            $reconciledTypes = Reconciler::reconcileKeyedTypes(
                                ['$innerType' => $assertions['$' . $firstParam->var->name]],
                                ['$innerType' => $innerType],
                                $changedVarIds,
                                ['$innerType' => true],
                                $statementsChecker,
                                new CodeLocation($statementsChecker->getSource(), $stmt),
                                $statementsChecker->getSuppressedIssues()
                            );

                            if (isset($reconciledTypes['$innerType'])) {
                                $innerType = $reconciledTypes['$innerType'];
                            }
                        }
                    }
                }
            }

            return new Type\Union([
                new Type\Atomic\TArray([
                    $keyType,
                    $innerType,
                ]),
            ]);
        }

        return new Type\Union([
            new Type\Atomic\TArray([
                $keyType,
                $innerType,
            ]),
        ]);
    }
}
