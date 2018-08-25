<?php
namespace Psalm\Checker\Statements\Expression;

use PhpParser;
use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\FunctionLikeChecker;
use Psalm\Checker\MethodChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Checker\TypeChecker;
use Psalm\Codebase\CallMap;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Issue\ImplicitToStringCast;
use Psalm\Issue\InvalidArgument;
use Psalm\Issue\InvalidPassByReference;
use Psalm\Issue\InvalidScalarArgument;
use Psalm\Issue\MixedArgument;
use Psalm\Issue\MixedTypeCoercion;
use Psalm\Issue\NullArgument;
use Psalm\Issue\PossiblyFalseArgument;
use Psalm\Issue\PossiblyInvalidArgument;
use Psalm\Issue\PossiblyNullArgument;
use Psalm\Issue\TooFewArguments;
use Psalm\Issue\TooManyArguments;
use Psalm\Issue\TypeCoercion;
use Psalm\Issue\UndefinedFunction;
use Psalm\IssueBuffer;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Type;
use Psalm\Type\Atomic\ObjectLike;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TNamedObject;

class CallChecker
{
    /**
     * @param   FunctionLikeChecker $source
     * @param   string              $methodName
     * @param   Context             $context
     *
     * @return  void
     */
    public static function collectSpecialInformation(
        FunctionLikeChecker $source,
        $methodName,
        Context $context
    ) {
        $fqClassName = (string)$source->getFQCLN();

        $projectChecker = $source->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;

        if ($context->collectMutations &&
            $context->self &&
            (
                $context->self === $fqClassName ||
                $codebase->classExtends(
                    $context->self,
                    $fqClassName
                )
            )
        ) {
            $methodId = $fqClassName . '::' . strtolower($methodName);

            if ($methodId !== $source->getMethodId()) {
                if ($context->collectInitializations) {
                    if (isset($context->initializedMethods[$methodId])) {
                        return;
                    }

                    if ($context->initializedMethods === null) {
                        $context->initializedMethods = [];
                    }

                    $context->initializedMethods[$methodId] = true;
                }

                $projectChecker->getMethodMutations($methodId, $context);
            }
        } elseif ($context->collectInitializations &&
            $context->self &&
            (
                $context->self === $fqClassName ||
                $codebase->classlikes->classExtends(
                    $context->self,
                    $fqClassName
                )
            ) &&
            $source->getMethodName() !== $methodName
        ) {
            $methodId = $fqClassName . '::' . strtolower($methodName);

            $declaringMethodId = (string) $codebase->methods->getDeclaringMethodId($methodId);

            if (isset($context->initializedMethods[$declaringMethodId])) {
                return;
            }

            if ($context->initializedMethods === null) {
                $context->initializedMethods = [];
            }

            $context->initializedMethods[$declaringMethodId] = true;

            $methodStorage = $codebase->methods->getStorage($declaringMethodId);

            $classChecker = $source->getSource();

            if ($classChecker instanceof ClassLikeChecker &&
                ($methodStorage->visibility === ClassLikeChecker::VISIBILITY_PRIVATE || $methodStorage->final)
            ) {
                $localVarsInScope = [];
                $localVarsPossiblyInScope = [];

                foreach ($context->varsInScope as $var => $_) {
                    if (strpos($var, '$this->') !== 0 && $var !== '$this') {
                        $localVarsInScope[$var] = $context->varsInScope[$var];
                    }
                }

                foreach ($context->varsPossiblyInScope as $var => $_) {
                    if (strpos($var, '$this->') !== 0 && $var !== '$this') {
                        $localVarsPossiblyInScope[$var] = $context->varsPossiblyInScope[$var];
                    }
                }

                $classChecker->getMethodMutations(strtolower($methodName), $context);

                foreach ($localVarsInScope as $var => $type) {
                    $context->varsInScope[$var] = $type;
                }

                foreach ($localVarsPossiblyInScope as $var => $_) {
                    $context->varsPossiblyInScope[$var] = true;
                }
            }
        }
    }

    /**
     * @param  string|null                      $methodId
     * @param  array<int, PhpParser\Node\Arg>   $args
     * @param  array<string, Type\Union>|null   &$genericParams
     * @param  Context                          $context
     * @param  CodeLocation                     $codeLocation
     * @param  StatementsChecker                $statementsChecker
     *
     * @return false|null
     */
    protected static function checkMethodArgs(
        $methodId,
        array $args,
        &$genericParams,
        Context $context,
        CodeLocation $codeLocation,
        StatementsChecker $statementsChecker
    ) {
        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;

        $methodParams = $methodId
            ? FunctionLikeChecker::getMethodParamsById($projectChecker, $methodId, $args)
            : null;

        if (self::checkFunctionArguments(
            $statementsChecker,
            $args,
            $methodParams,
            $methodId,
            $context
        ) === false) {
            return false;
        }

        if (!$methodId || $methodParams === null) {
            return;
        }

        list($fqClassName, $methodName) = explode('::', $methodId);

        $classStorage = $projectChecker->classlikeStorageProvider->get($fqClassName);

        $methodStorage = null;

        if (isset($classStorage->declaringMethodIds[strtolower($methodName)])) {
            $declaringMethodId = $classStorage->declaringMethodIds[strtolower($methodName)];

            list($declaringFqClassName, $declaringMethodName) = explode('::', $declaringMethodId);

            if ($declaringFqClassName !== $fqClassName) {
                $declaringClassStorage = $projectChecker->classlikeStorageProvider->get($declaringFqClassName);
            } else {
                $declaringClassStorage = $classStorage;
            }

            if (!isset($declaringClassStorage->methods[strtolower($declaringMethodName)])) {
                throw new \UnexpectedValueException('Storage should not be empty here');
            }

            $methodStorage = $declaringClassStorage->methods[strtolower($declaringMethodName)];

            if ($context->collectExceptions) {
                $context->possiblyThrownExceptions += $methodStorage->throws;
            }
        }

        if (!$classStorage->userDefined) {
            // check again after we've processed args
            $methodParams = FunctionLikeChecker::getMethodParamsById(
                $projectChecker,
                $methodId,
                $args
            );
        }

        if (self::checkFunctionLikeArgumentsMatch(
            $statementsChecker,
            $args,
            $methodId,
            $methodParams,
            $methodStorage,
            $classStorage,
            $genericParams,
            $codeLocation,
            $context
        ) === false) {
            return false;
        }

        return null;
    }

    /**
     * @param   StatementsChecker                       $statementsChecker
     * @param   array<int, PhpParser\Node\Arg>          $args
     * @param   array<int, FunctionLikeParameter>|null  $functionParams
     * @param   string|null                             $methodId
     * @param   Context                                 $context
     *
     * @return  false|null
     */
    protected static function checkFunctionArguments(
        StatementsChecker $statementsChecker,
        array $args,
        $functionParams,
        $methodId,
        Context $context
    ) {
        $lastParam = $functionParams
            ? $functionParams[count($functionParams) - 1]
            : null;

        // if this modifies the array type based on further args
        if ($methodId && in_array($methodId, ['array_push', 'array_unshift'], true) && $functionParams) {
            $arrayArg = $args[0]->value;

            if (ExpressionChecker::analyze(
                $statementsChecker,
                $arrayArg,
                $context
            ) === false) {
                return false;
            }

            if (isset($arrayArg->inferredType) && $arrayArg->inferredType->hasArray()) {
                /** @var TArray|ObjectLike */
                $arrayType = $arrayArg->inferredType->getTypes()['array'];

                if ($arrayType instanceof ObjectLike) {
                    $arrayType = $arrayType->getGenericArrayType();
                }

                $byRefType = new Type\Union([clone $arrayType]);

                foreach ($args as $argumentOffset => $arg) {
                    if ($argumentOffset === 0) {
                        continue;
                    }

                    if (ExpressionChecker::analyze(
                        $statementsChecker,
                        $arg->value,
                        $context
                    ) === false) {
                        return false;
                    }

                    if (!isset($arg->value->inferredType) || $arg->value->inferredType->isMixed()) {
                        $byRefType = Type::combineUnionTypes(
                            $byRefType,
                            new Type\Union([new TArray([Type::getInt(), Type::getMixed()])])
                        );
                    } elseif ($arg->unpack) {
                        if ($arg->value->inferredType->hasArray()) {
                            /** @var Type\Atomic\TArray|Type\Atomic\ObjectLike */
                            $arrayAtomicType = $arg->value->inferredType->getTypes()['array'];

                            if ($arrayAtomicType instanceof Type\Atomic\ObjectLike) {
                                $arrayAtomicType = $arrayAtomicType->getGenericArrayType();
                            }

                            $byRefType = Type::combineUnionTypes(
                                $byRefType,
                                new Type\Union(
                                    [
                                        new TArray(
                                            [
                                                Type::getInt(),
                                                clone $arrayAtomicType->typeParams[1]
                                            ]
                                        ),
                                    ]
                                )
                            );
                        }
                    } else {
                        $byRefType = Type::combineUnionTypes(
                            $byRefType,
                            new Type\Union(
                                [
                                    new TArray(
                                        [
                                            Type::getInt(),
                                            clone $arg->value->inferredType
                                        ]
                                    ),
                                ]
                            )
                        );
                    }
                }

                ExpressionChecker::assignByRefParam(
                    $statementsChecker,
                    $arrayArg,
                    $byRefType,
                    $context,
                    false
                );
            }

            return;
        }

        if ($methodId && $methodId === 'array_splice' && $functionParams && count($args) > 1) {
            $arrayArg = $args[0]->value;

            if (ExpressionChecker::analyze(
                $statementsChecker,
                $arrayArg,
                $context
            ) === false) {
                return false;
            }

            $offsetArg = $args[1]->value;

            if (ExpressionChecker::analyze(
                $statementsChecker,
                $offsetArg,
                $context
            ) === false) {
                return false;
            }

            if (!isset($args[2])) {
                return;
            }

            $lengthArg = $args[2]->value;

            if (ExpressionChecker::analyze(
                $statementsChecker,
                $lengthArg,
                $context
            ) === false) {
                return false;
            }

            if (!isset($args[3])) {
                return;
            }

            $replacementArg = $args[3]->value;

            if (ExpressionChecker::analyze(
                $statementsChecker,
                $replacementArg,
                $context
            ) === false) {
                return false;
            }

            if (isset($replacementArg->inferredType)
                && !$replacementArg->inferredType->hasArray()
                && $replacementArg->inferredType->hasString()
                && $replacementArg->inferredType->isSingle()
            ) {
                $replacementArg->inferredType = new Type\Union([
                    new Type\Atomic\TArray([Type::getInt(), $replacementArg->inferredType])
                ]);
            }

            if (isset($arrayArg->inferredType)
                && $arrayArg->inferredType->hasArray()
                && isset($replacementArg->inferredType)
                && $replacementArg->inferredType->hasArray()
            ) {
                /** @var TArray|ObjectLike */
                $arrayType = $arrayArg->inferredType->getTypes()['array'];

                if ($arrayType instanceof ObjectLike) {
                    $arrayType = $arrayType->getGenericArrayType();
                }

                /** @var TArray|ObjectLike */
                $replacementArrayType = $replacementArg->inferredType->getTypes()['array'];

                if ($replacementArrayType instanceof ObjectLike) {
                    $replacementArrayType = $replacementArrayType->getGenericArrayType();
                }

                $byRefType = Type\TypeCombination::combineTypes([$arrayType, $replacementArrayType]);

                ExpressionChecker::assignByRefParam(
                    $statementsChecker,
                    $arrayArg,
                    $byRefType,
                    $context,
                    false
                );

                return;
            }

            ExpressionChecker::assignByRefParam(
                $statementsChecker,
                $arrayArg,
                Type::getArray(),
                $context,
                false
            );

            return;
        }

        foreach ($args as $argumentOffset => $arg) {
            if ($functionParams !== null) {
                $byRef = $argumentOffset < count($functionParams)
                    ? $functionParams[$argumentOffset]->byRef
                    : $lastParam && $lastParam->isVariadic && $lastParam->byRef;

                $byRefType = null;

                if ($byRef && $lastParam) {
                    if ($argumentOffset < count($functionParams)) {
                        $byRefType = $functionParams[$argumentOffset]->type;
                    } else {
                        $byRefType = $lastParam->type;
                    }

                    $byRefType = $byRefType ? clone $byRefType : Type::getMixed();
                }

                if ($byRef
                    && $byRefType
                    && !($arg->value instanceof PhpParser\Node\Expr\Closure
                        || $arg->value instanceof PhpParser\Node\Expr\ConstFetch
                        || $arg->value instanceof PhpParser\Node\Expr\FuncCall
                        || $arg->value instanceof PhpParser\Node\Expr\MethodCall
                    )
                ) {
                    // special handling for array sort
                    if ($argumentOffset === 0
                        && $methodId
                        && in_array(
                            $methodId,
                            [
                                'shuffle', 'sort', 'rsort', 'usort', 'ksort', 'asort',
                                'krsort', 'arsort', 'natcasesort', 'natsort', 'reset',
                                'end', 'next', 'prev', 'array_pop', 'array_shift',
                            ],
                            true
                        )
                    ) {
                        if (ExpressionChecker::analyze(
                            $statementsChecker,
                            $arg->value,
                            $context
                        ) === false) {
                            return false;
                        }

                        if (in_array($methodId, ['array_pop', 'array_shift'], true)) {
                            $varId = ExpressionChecker::getVarId(
                                $arg->value,
                                $statementsChecker->getFQCLN(),
                                $statementsChecker
                            );

                            if ($varId) {
                                $context->removeVarFromConflictingClauses($varId, null, $statementsChecker);

                                if (isset($context->varsInScope[$varId])) {
                                    $arrayType = clone $context->varsInScope[$varId];

                                    $arrayAtomicTypes = $arrayType->getTypes();

                                    foreach ($arrayAtomicTypes as $arrayAtomicType) {
                                        if ($arrayAtomicType instanceof ObjectLike) {
                                            $genericArrayType = $arrayAtomicType->getGenericArrayType();

                                            if ($genericArrayType->count) {
                                                $genericArrayType->count--;
                                            }

                                            $arrayType->addType($genericArrayType);
                                        } elseif ($arrayAtomicType instanceof TArray && $arrayAtomicType->count) {
                                            $arrayAtomicType->count--;
                                        }
                                    }

                                    $context->varsInScope[$varId] = $arrayType;
                                }
                            }

                            continue;
                        }

                        // noops
                        if (in_array($methodId, ['reset', 'end', 'next', 'prev'], true)) {
                            continue;
                        }

                        if (isset($arg->value->inferredType)
                            && $arg->value->inferredType->hasArray()
                        ) {
                            /** @var TArray|ObjectLike */
                            $arrayType = $arg->value->inferredType->getTypes()['array'];

                            if ($arrayType instanceof ObjectLike) {
                                $arrayType = $arrayType->getGenericArrayType();
                            }

                            if (in_array($methodId, ['shuffle', 'sort', 'rsort', 'usort'], true)) {
                                $tvalue = $arrayType->typeParams[1];
                                $byRefType = new Type\Union([new TArray([Type::getInt(), clone $tvalue])]);
                            } else {
                                $byRefType = new Type\Union([clone $arrayType]);
                            }

                            ExpressionChecker::assignByRefParam(
                                $statementsChecker,
                                $arg->value,
                                $byRefType,
                                $context,
                                false
                            );

                            continue;
                        }
                    }

                    if ($methodId === 'socket_select') {
                        if (ExpressionChecker::analyze(
                            $statementsChecker,
                            $arg->value,
                            $context
                        ) === false) {
                            return false;
                        }
                    }
                } else {
                    $toggledClassExists = false;

                    if ($methodId === 'class_exists'
                        && $argumentOffset === 0
                        && !$context->insideClassExists
                    ) {
                        $context->insideClassExists = true;
                        $toggledClassExists = true;
                    }

                    if (ExpressionChecker::analyze($statementsChecker, $arg->value, $context) === false) {
                        return false;
                    }

                    if ($context->collectReferences
                        && ($arg->value instanceof PhpParser\Node\Expr\AssignOp
                            || $arg->value instanceof PhpParser\Node\Expr\PreInc
                            || $arg->value instanceof PhpParser\Node\Expr\PreDec)
                    ) {
                        $varId = ExpressionChecker::getVarId(
                            $arg->value->var,
                            $statementsChecker->getFQCLN(),
                            $statementsChecker
                        );

                        if ($varId) {
                            $context->hasVariable($varId, $statementsChecker);
                        }
                    }

                    if ($toggledClassExists) {
                        $context->insideClassExists = false;
                    }
                }
            } else {
                // if it's a closure, we want to evaluate it anyway
                if ($arg->value instanceof PhpParser\Node\Expr\Closure
                    || $arg->value instanceof PhpParser\Node\Expr\ConstFetch
                    || $arg->value instanceof PhpParser\Node\Expr\FuncCall
                    || $arg->value instanceof PhpParser\Node\Expr\MethodCall) {
                    if (ExpressionChecker::analyze($statementsChecker, $arg->value, $context) === false) {
                        return false;
                    }
                }

                if ($arg->value instanceof PhpParser\Node\Expr\PropertyFetch
                    && $arg->value->name instanceof PhpParser\Node\Identifier
                ) {
                    $varId = '$' . $arg->value->name->name;
                } else {
                    $varId = ExpressionChecker::getVarId(
                        $arg->value,
                        $statementsChecker->getFQCLN(),
                        $statementsChecker
                    );
                }

                if ($varId) {
                    if (!$context->hasVariable($varId, $statementsChecker)
                        || $context->varsInScope[$varId]->isNull()
                    ) {
                        // we don't know if it exists, assume it's passed by reference
                        $context->varsInScope[$varId] = Type::getMixed();
                        $context->varsPossiblyInScope[$varId] = true;

                        if (strpos($varId, '-') === false
                            && strpos($varId, '[') === false
                            && !$statementsChecker->hasVariable($varId)
                        ) {
                            $location = new CodeLocation($statementsChecker, $arg->value);
                            $statementsChecker->registerVariable(
                                $varId,
                                $location,
                                null
                            );

                            $statementsChecker->registerVariableUses([$location->getHash() => $location]);
                        }
                    } else {
                        $context->removeVarFromConflictingClauses(
                            $varId,
                            $context->varsInScope[$varId],
                            $statementsChecker
                        );

                        foreach ($context->varsInScope[$varId]->getTypes() as $type) {
                            if ($type instanceof TArray && $type->typeParams[1]->isEmpty()) {
                                $context->varsInScope[$varId]->removeType('array');
                                $context->varsInScope[$varId]->addType(
                                    new TArray(
                                        [Type::getMixed(), Type::getMixed()]
                                    )
                                );
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param   StatementsChecker                       $statementsChecker
     * @param   array<int, PhpParser\Node\Arg>          $args
     * @param   string|null                             $methodId
     * @param   array<int,FunctionLikeParameter>        $functionParams
     * @param   FunctionLikeStorage|null                $functionStorage
     * @param   ClassLikeStorage|null                   $classStorage
     * @param   array<string, Type\Union>|null          $genericParams
     * @param   CodeLocation                            $codeLocation
     * @param   Context                                 $context
     *
     * @return  false|null
     */
    protected static function checkFunctionLikeArgumentsMatch(
        StatementsChecker $statementsChecker,
        array $args,
        $methodId,
        array $functionParams,
        $functionStorage,
        $classStorage,
        &$genericParams,
        CodeLocation $codeLocation,
        Context $context
    ) {
        $inCallMap = $methodId ? CallMap::inCallMap($methodId) : false;

        $casedMethodId = $methodId;

        $isVariadic = false;

        $fqClassName = null;

        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;

        if ($methodId) {
            if ($inCallMap || !strpos($methodId, '::')) {
                $isVariadic = $codebase->functions->isVariadic(
                    $projectChecker,
                    strtolower($methodId),
                    $statementsChecker->getRootFilePath()
                );
            } else {
                $fqClassName = explode('::', $methodId)[0];
                $isVariadic = $codebase->methods->isVariadic($methodId);
            }
        }

        if ($methodId && strpos($methodId, '::') && !$inCallMap) {
            $casedMethodId = $codebase->methods->getCasedMethodId($methodId);
        } elseif ($functionStorage) {
            $casedMethodId = $functionStorage->casedName;
        }

        if ($methodId && strpos($methodId, '::')) {
            $declaringMethodId = $codebase->methods->getDeclaringMethodId($methodId);

            if ($declaringMethodId && $declaringMethodId !== $methodId) {
                list($fqClassName) = explode('::', $declaringMethodId);
                $classStorage = $projectChecker->classlikeStorageProvider->get($fqClassName);
            }
        }

        if ($functionParams) {
            foreach ($functionParams as $functionParam) {
                $isVariadic = $isVariadic || $functionParam->isVariadic;
            }
        }

        $hasPackedVar = false;

        foreach ($args as $arg) {
            $hasPackedVar = $hasPackedVar || $arg->unpack;
        }

        $lastParam = $functionParams
            ? $functionParams[count($functionParams) - 1]
            : null;

        $templateTypes = null;

        if ($functionStorage) {
            $templateTypes = [];

            if ($functionStorage->templateTypes) {
                $templateTypes = $functionStorage->templateTypes;
            }
            if ($classStorage && $classStorage->templateTypes) {
                $templateTypes = array_merge($templateTypes, $classStorage->templateTypes);
            }
        }

        $existingGenericParamsToStrings = $genericParams ?: [];

        foreach ($args as $argumentOffset => $arg) {
            $functionParam = count($functionParams) > $argumentOffset
                ? $functionParams[$argumentOffset]
                : ($lastParam && $lastParam->isVariadic ? $lastParam : null);

            if ($functionParam
                && $functionParam->byRef
                && $methodId !== 'extract'
            ) {
                if ($arg->value instanceof PhpParser\Node\Scalar
                    || $arg->value instanceof PhpParser\Node\Expr\Array_
                    || $arg->value instanceof PhpParser\Node\Expr\ClassConstFetch
                    || (
                        (
                        $arg->value instanceof PhpParser\Node\Expr\ConstFetch
                            || $arg->value instanceof PhpParser\Node\Expr\FuncCall
                            || $arg->value instanceof PhpParser\Node\Expr\MethodCall
                        ) && (
                            !isset($arg->value->inferredType)
                            || !$arg->value->inferredType->byRef
                        )
                    )
                ) {
                    if (IssueBuffer::accepts(
                        new InvalidPassByReference(
                            'Parameter ' . ($argumentOffset + 1) . ' of ' . $methodId . ' expects a variable',
                            $codeLocation
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }

                    continue;
                }

                if (!in_array(
                    $methodId,
                    [
                        'shuffle', 'sort', 'rsort', 'usort', 'ksort', 'asort',
                        'krsort', 'arsort', 'natcasesort', 'natsort', 'reset',
                        'end', 'next', 'prev', 'array_pop', 'array_shift',
                        'array_push', 'array_unshift', 'socket_select', 'array_splice',
                    ],
                    true
                )) {
                    $byRefType = null;

                    if ($lastParam) {
                        if ($argumentOffset < count($functionParams)) {
                            $byRefType = $functionParams[$argumentOffset]->type;
                        } else {
                            $byRefType = $lastParam->type;
                        }

                        if ($templateTypes && $byRefType) {
                            if ($genericParams === null) {
                                $genericParams = [];
                            }

                            $byRefType = clone $byRefType;

                            $byRefType->replaceTemplateTypesWithStandins($templateTypes, $genericParams);
                        }
                    }

                    $byRefType = $byRefType ?: Type::getMixed();

                    ExpressionChecker::assignByRefParam(
                        $statementsChecker,
                        $arg->value,
                        $byRefType,
                        $context,
                        $methodId && (strpos($methodId, '::') !== false || !CallMap::inCallMap($methodId))
                    );
                }
            }

            if (isset($arg->value->inferredType)) {
                if ($functionParam && $functionParam->type) {
                    $paramType = clone $functionParam->type;

                    if ($functionParam->isVariadic) {
                        if (!$paramType->hasArray()) {
                            continue;
                        }

                        $arrayAtomicType = $paramType->getTypes()['array'];

                        if (!$arrayAtomicType instanceof TArray) {
                            continue;
                        }

                        $paramType = clone $arrayAtomicType->typeParams[1];
                    }

                    if ($functionStorage) {
                        if (isset($functionStorage->templateTypeofParams[$argumentOffset])) {
                            $templateType = $functionStorage->templateTypeofParams[$argumentOffset];

                            $offsetValueType = null;

                            if ($arg->value instanceof PhpParser\Node\Expr\ClassConstFetch
                                && $arg->value->class instanceof PhpParser\Node\Name
                                && $arg->value->name instanceof PhpParser\Node\Identifier
                                && strtolower($arg->value->name->name) === 'class'
                            ) {
                                $offsetValueType = Type::parseString(
                                    ClassLikeChecker::getFQCLNFromNameObject(
                                        $arg->value->class,
                                        $statementsChecker->getAliases()
                                    )
                                );

                                $offsetValueType = ExpressionChecker::fleshOutType(
                                    $projectChecker,
                                    $offsetValueType,
                                    $context->self,
                                    $context->self
                                );
                            } elseif ($arg->value instanceof PhpParser\Node\Scalar\String_ && $arg->value->value) {
                                $offsetValueType = Type::parseString($arg->value->value);
                            } elseif ($arg->value instanceof PhpParser\Node\Scalar\MagicConst\Class_
                                && $context->self
                            ) {
                                $offsetValueType = Type::parseString($context->self);
                            }

                            if ($offsetValueType) {
                                foreach ($offsetValueType->getTypes() as $offsetValueTypePart) {
                                    // register class if the class exists
                                    if ($offsetValueTypePart instanceof TNamedObject) {
                                        ClassLikeChecker::checkFullyQualifiedClassLikeName(
                                            $statementsChecker,
                                            $offsetValueTypePart->value,
                                            new CodeLocation($statementsChecker->getSource(), $arg->value),
                                            $statementsChecker->getSuppressedIssues()
                                        );
                                    }
                                }

                                $offsetValueType->setFromDocblock();
                            }

                            if ($genericParams === null) {
                                $genericParams = [];
                            }

                            $genericParams[$templateType] = $offsetValueType ?: Type::getMixed();
                        } else {
                            if ($existingGenericParamsToStrings) {
                                $emptyGenericParams = [];

                                $paramType->replaceTemplateTypesWithStandins(
                                    $existingGenericParamsToStrings,
                                    $emptyGenericParams,
                                    $codebase,
                                    $arg->value->inferredType
                                );
                            }

                            if ($templateTypes) {
                                if ($genericParams === null) {
                                    $genericParams = [];
                                }

                                $argType = $arg->value->inferredType;

                                if ($arg->unpack) {
                                    if ($arg->value->inferredType->hasArray()) {
                                        /** @var Type\Atomic\TArray|Type\Atomic\ObjectLike */
                                        $arrayAtomicType = $arg->value->inferredType->getTypes()['array'];

                                        if ($arrayAtomicType instanceof Type\Atomic\ObjectLike) {
                                            $arrayAtomicType = $arrayAtomicType->getGenericArrayType();
                                        }

                                        $argType = $arrayAtomicType->typeParams[1];
                                    } else {
                                        $argType = Type::getMixed();
                                    }
                                }

                                $paramType->replaceTemplateTypesWithStandins(
                                    $templateTypes,
                                    $genericParams,
                                    $codebase,
                                    $argType
                                );
                            }
                        }
                    }

                    if (!$context->checkVariables) {
                        break;
                    }

                    $fleshedOutType = ExpressionChecker::fleshOutType(
                        $projectChecker,
                        $paramType,
                        $fqClassName,
                        $fqClassName
                    );

                    if ($arg->unpack) {
                        if ($arg->value->inferredType->hasArray()) {
                            /** @var Type\Atomic\TArray|Type\Atomic\ObjectLike */
                            $arrayAtomicType = $arg->value->inferredType->getTypes()['array'];

                            if ($arrayAtomicType instanceof Type\Atomic\ObjectLike) {
                                $arrayAtomicType = $arrayAtomicType->getGenericArrayType();
                            }

                            if (self::checkFunctionArgumentType(
                                $statementsChecker,
                                $arrayAtomicType->typeParams[1],
                                $fleshedOutType,
                                $casedMethodId,
                                $argumentOffset,
                                new CodeLocation($statementsChecker->getSource(), $arg->value),
                                $arg->value,
                                $context,
                                $functionParam->byRef,
                                $functionParam->isVariadic
                            ) === false) {
                                return false;
                            }
                        } elseif ($arg->value->inferredType->isMixed()) {
                            $codebase->analyzer->incrementMixedCount($statementsChecker->getFilePath());

                            if (IssueBuffer::accepts(
                                new MixedArgument(
                                    'Argument ' . ($argumentOffset + 1) . ' of ' . $casedMethodId
                                        . ' cannot be mixed, expecting array',
                                    $codeLocation
                                ),
                                $statementsChecker->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        } else {
                            foreach ($arg->value->inferredType->getTypes() as $atomicType) {
                                if (!$atomicType->isIterable($codebase)) {
                                    if (IssueBuffer::accepts(
                                        new InvalidArgument(
                                            'Argument ' . ($argumentOffset + 1) . ' of ' . $casedMethodId
                                                . ' expects array, ' . $atomicType . ' provided',
                                            $codeLocation
                                        ),
                                        $statementsChecker->getSuppressedIssues()
                                    )) {
                                        return false;
                                    }
                                }
                            }
                        }

                        break;
                    }

                    if (self::checkFunctionArgumentType(
                        $statementsChecker,
                        $arg->value->inferredType,
                        $fleshedOutType,
                        $casedMethodId,
                        $argumentOffset,
                        new CodeLocation($statementsChecker->getSource(), $arg->value),
                        $arg->value,
                        $context,
                        $functionParam->byRef,
                        $functionParam->isVariadic
                    ) === false) {
                        return false;
                    }
                }
            } elseif ($functionParam) {
                $codebase->analyzer->incrementMixedCount($statementsChecker->getFilePath());

                if ($functionParam->type && !$functionParam->type->isMixed()) {
                    if (IssueBuffer::accepts(
                        new MixedArgument(
                            'Argument ' . ($argumentOffset + 1) . ' of ' . $casedMethodId
                                . ' cannot be mixed, expecting ' . $functionParam->type,
                            new CodeLocation($statementsChecker->getSource(), $arg->value)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }
            }
        }

        if ($methodId === 'array_map' || $methodId === 'array_filter') {
            if ($methodId === 'array_map' && count($args) < 2) {
                if (IssueBuffer::accepts(
                    new TooFewArguments(
                        'Too few arguments for ' . $methodId,
                        $codeLocation,
                        $methodId
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }
            } elseif ($methodId === 'array_filter' && count($args) < 1) {
                if (IssueBuffer::accepts(
                    new TooFewArguments(
                        'Too few arguments for ' . $methodId,
                        $codeLocation,
                        $methodId
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }
            }

            if (self::checkArrayFunctionArgumentsMatch(
                $statementsChecker,
                $args,
                $methodId
            ) === false
            ) {
                return false;
            }
        }

        if (!$isVariadic
            && count($args) > count($functionParams)
            && (!count($functionParams) || $functionParams[count($functionParams) - 1]->name !== '...=')
        ) {
            if (IssueBuffer::accepts(
                new TooManyArguments(
                    'Too many arguments for method ' . ($casedMethodId ?: $methodId)
                        . ' - expecting ' . count($functionParams) . ' but saw ' . count($args),
                    $codeLocation,
                    $methodId ?: ''
                ),
                $statementsChecker->getSuppressedIssues()
            )) {
                // fall through
            }

            return null;
        }

        if (!$hasPackedVar && count($args) < count($functionParams)) {
            for ($i = count($args), $j = count($functionParams); $i < $j; ++$i) {
                $param = $functionParams[$i];

                if (!$param->isOptional && !$param->isVariadic) {
                    if (IssueBuffer::accepts(
                        new TooFewArguments(
                            'Too few arguments for method ' . $casedMethodId
                                . ' - expecting ' . count($functionParams) . ' but saw ' . count($args),
                            $codeLocation,
                            $methodId ?: ''
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }

                    break;
                }
            }
        }
    }

    /**
     * @param   StatementsChecker              $statementsChecker
     * @param   array<int, PhpParser\Node\Arg> $args
     * @param   string                         $methodId
     *
     * @return  false|null
     */
    protected static function checkArrayFunctionArgumentsMatch(
        StatementsChecker $statementsChecker,
        array $args,
        $methodId
    ) {
        $closureIndex = $methodId === 'array_map' ? 0 : 1;

        $arrayArgTypes = [];

        foreach ($args as $i => $arg) {
            if ($i === 0 && $methodId === 'array_map') {
                continue;
            }

            if ($i === 1 && $methodId === 'array_filter') {
                break;
            }

            $arrayArg = isset($arg->value) ? $arg->value : null;

            /** @var ObjectLike|TArray|null */
            $arrayArgType = $arrayArg
                    && isset($arrayArg->inferredType)
                    && isset($arrayArg->inferredType->getTypes()['array'])
                ? $arrayArg->inferredType->getTypes()['array']
                : null;

            if ($arrayArgType instanceof ObjectLike) {
                $arrayArgType = $arrayArgType->getGenericArrayType();
            }

            $arrayArgTypes[] = $arrayArgType;
        }

        /** @var null|PhpParser\Node\Arg */
        $closureArg = isset($args[$closureIndex]) ? $args[$closureIndex] : null;

        /** @var Type\Union|null */
        $closureArgType = $closureArg && isset($closureArg->value->inferredType)
                ? $closureArg->value->inferredType
                : null;

        if ($closureArg && $closureArgType) {
            $minClosureParamCount = $maxClosureParamCount = count($arrayArgTypes);

            if ($methodId === 'array_filter') {
                $maxClosureParamCount = count($args) > 2 ? 2 : 1;
            }

            foreach ($closureArgType->getTypes() as $closureType) {
                if (self::checkArrayFunctionClosureType(
                    $statementsChecker,
                    $methodId,
                    $closureType,
                    $closureArg,
                    $minClosureParamCount,
                    $maxClosureParamCount,
                    $arrayArgTypes
                ) === false) {
                    return false;
                }
            }
        }
    }

    /**
     * @param  string   $methodId
     * @param  int      $minClosureParamCount
     * @param  int      $maxClosureParamCount [description]
     * @param  (TArray|null)[] $arrayArgTypes
     *
     * @return false|null
     */
    private static function checkArrayFunctionClosureType(
        StatementsChecker $statementsChecker,
        $methodId,
        Type\Atomic $closureType,
        PhpParser\Node\Arg $closureArg,
        $minClosureParamCount,
        $maxClosureParamCount,
        array $arrayArgTypes
    ) {
        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;

        $codebase = $projectChecker->codebase;

        if (!$closureType instanceof Type\Atomic\Fn) {
            if (!$closureArg->value instanceof PhpParser\Node\Scalar\String_
                && !$closureArg->value instanceof PhpParser\Node\Expr\Array_
            ) {
                return;
            }

            $functionIds = self::getFunctionIdsFromCallableArg(
                $statementsChecker,
                $closureArg->value
            );

            $closureTypes = [];

            foreach ($functionIds as $functionId) {
                $functionId = strtolower($functionId);

                if (strpos($functionId, '::') !== false) {
                    $functionIdParts = explode('&', $functionId);

                    foreach ($functionIdParts as $functionIdPart) {
                        list($callableFqClassName, $methodName) = explode('::', $functionIdPart);

                        switch ($callableFqClassName) {
                            case 'self':
                            case 'static':
                            case 'parent':
                                $containerClass = $statementsChecker->getFQCLN();

                                if ($callableFqClassName === 'parent') {
                                    $containerClass = $statementsChecker->getParentFQCLN();
                                }

                                if (!$containerClass) {
                                    continue 2;
                                }

                                $callableFqClassName = $containerClass;
                        }

                        if (!$codebase->classOrInterfaceExists($callableFqClassName)) {
                            return;
                        }

                        $functionIdPart = $callableFqClassName . '::' . $methodName;

                        try {
                            $methodStorage = $codebase->methods->getStorage($functionIdPart);
                        } catch (\UnexpectedValueException $e) {
                            // the method may not exist, but we're suppressing that issue
                            continue;
                        }

                        $closureTypes[] = new Type\Atomic\Fn(
                            'Closure',
                            $methodStorage->params,
                            $methodStorage->returnType ?: Type::getMixed()
                        );
                    }
                } else {
                    $functionStorage = $codebase->functions->getStorage(
                        $statementsChecker,
                        $functionId
                    );

                    if (CallMap::inCallMap($functionId)) {
                        $callmapParamsOptions = CallMap::getParamsFromCallMap($functionId);

                        if ($callmapParamsOptions === null) {
                            throw new \UnexpectedValueException('This should not happen');
                        }

                        $passingCallmapParamsOptions = [];

                        foreach ($callmapParamsOptions as $callmapParamsOption) {
                            $requiredParamCount = 0;

                            foreach ($callmapParamsOption as $i => $param) {
                                if (!$param->isOptional && !$param->isVariadic) {
                                    $requiredParamCount = $i + 1;
                                }
                            }

                            if ($requiredParamCount <= $maxClosureParamCount) {
                                $passingCallmapParamsOptions[] = $callmapParamsOption;
                            }
                        }

                        if ($passingCallmapParamsOptions) {
                            foreach ($passingCallmapParamsOptions as $passingCallmapParamsOption) {
                                $closureTypes[] = new Type\Atomic\Fn(
                                    'Closure',
                                    $passingCallmapParamsOption,
                                    $functionStorage->returnType ?: Type::getMixed()
                                );
                            }
                        } else {
                            $closureTypes[] = new Type\Atomic\Fn(
                                'Closure',
                                $callmapParamsOptions[0],
                                $functionStorage->returnType ?: Type::getMixed()
                            );
                        }
                    } else {
                        $closureTypes[] = new Type\Atomic\Fn(
                            'Closure',
                            $functionStorage->params,
                            $functionStorage->returnType ?: Type::getMixed()
                        );
                    }
                }
            }
        } else {
            $closureTypes = [$closureType];
        }

        foreach ($closureTypes as $closureType) {
            if ($closureType->params === null) {
                continue;
            }

            if (self::checkArrayFunctionClosureTypeArgs(
                $statementsChecker,
                $methodId,
                $closureType,
                $closureArg,
                $minClosureParamCount,
                $maxClosureParamCount,
                $arrayArgTypes
            ) === false) {
                return false;
            }
        }
    }

    /**
     * @param  string   $methodId
     * @param  int      $minClosureParamCount
     * @param  int      $maxClosureParamCount [description]
     * @param  (TArray|null)[] $arrayArgTypes
     *
     * @return false|null
     */
    private static function checkArrayFunctionClosureTypeArgs(
        StatementsChecker $statementsChecker,
        $methodId,
        Type\Atomic\Fn $closureType,
        PhpParser\Node\Arg $closureArg,
        $minClosureParamCount,
        $maxClosureParamCount,
        array $arrayArgTypes
    ) {
        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;

        $closureParams = $closureType->params;

        if ($closureParams === null) {
            throw new \UnexpectedValueException('Closure params should not be null here');
        }

        $requiredParamCount = 0;

        foreach ($closureParams as $i => $param) {
            if (!$param->isOptional && !$param->isVariadic) {
                $requiredParamCount = $i + 1;
            }
        }

        if (count($closureParams) < $minClosureParamCount) {
            $argumentText = $minClosureParamCount === 1 ? 'one argument' : $minClosureParamCount . ' arguments';

            if (IssueBuffer::accepts(
                new TooManyArguments(
                    'The callable passed to ' . $methodId . ' will be called with ' . $argumentText . ', expecting '
                        . $requiredParamCount,
                    new CodeLocation($statementsChecker->getSource(), $closureArg),
                    $methodId
                ),
                $statementsChecker->getSuppressedIssues()
            )) {
                return false;
            }
        } elseif ($requiredParamCount > $maxClosureParamCount) {
            $argumentText = $maxClosureParamCount === 1 ? 'one argument' : $maxClosureParamCount . ' arguments';

            if (IssueBuffer::accepts(
                new TooFewArguments(
                    'The callable passed to ' . $methodId . ' will be called with ' . $argumentText . ', expecting '
                        . $requiredParamCount,
                    new CodeLocation($statementsChecker->getSource(), $closureArg),
                    $methodId
                ),
                $statementsChecker->getSuppressedIssues()
            )) {
                return false;
            }
        }

        // abandon attempt to validate closure params if we have an extra arg for ARRAY_FILTER
        if ($methodId === 'array_filter' && $maxClosureParamCount > 1) {
            return;
        }

        $i = 0;

        foreach ($closureParams as $closureParam) {
            if (!isset($arrayArgTypes[$i])) {
                ++$i;
                continue;
            }

            /** @var Type\Atomic\TArray */
            $arrayArgType = $arrayArgTypes[$i];

            $inputType = $arrayArgType->typeParams[1];

            if ($inputType->isMixed()) {
                ++$i;
                continue;
            }

            $closureParamType = $closureParam->type;

            if (!$closureParamType) {
                ++$i;
                continue;
            }

            $typeMatchFound = TypeChecker::isContainedBy(
                $projectChecker->codebase,
                $inputType,
                $closureParamType,
                false,
                false,
                $scalarTypeMatchFound,
                $typeCoerced,
                $typeCoercedFromMixed
            );

            if ($typeCoerced) {
                if ($typeCoercedFromMixed) {
                    if (IssueBuffer::accepts(
                        new MixedTypeCoercion(
                            'First parameter of closure passed to function ' . $methodId . ' expects ' .
                                $closureParamType->getId() . ', parent type ' . $inputType->getId() . ' provided',
                            new CodeLocation($statementsChecker->getSource(), $closureArg)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // keep soldiering on
                    }
                } else {
                    if (IssueBuffer::accepts(
                        new TypeCoercion(
                            'First parameter of closure passed to function ' . $methodId . ' expects ' .
                                $closureParamType->getId() . ', parent type ' . $inputType->getId() . ' provided',
                            new CodeLocation($statementsChecker->getSource(), $closureArg)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // keep soldiering on
                    }
                }
            }

            if (!$typeCoerced && !$typeMatchFound) {
                $typesCanBeIdentical = TypeChecker::canBeIdenticalTo(
                    $projectChecker->codebase,
                    $inputType,
                    $closureParamType
                );

                if ($scalarTypeMatchFound) {
                    if (IssueBuffer::accepts(
                        new InvalidScalarArgument(
                            'First parameter of closure passed to function ' . $methodId . ' expects ' .
                                $closureParamType . ', ' . $inputType . ' provided',
                            new CodeLocation($statementsChecker->getSource(), $closureArg)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }
                } elseif ($typesCanBeIdentical) {
                    if (IssueBuffer::accepts(
                        new PossiblyInvalidArgument(
                            'First parameter of closure passed to function ' . $methodId . ' expects '
                                . $closureParamType->getId() . ', possibly different type '
                                . $inputType->getId() . ' provided',
                            new CodeLocation($statementsChecker->getSource(), $closureArg)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }
                } elseif (IssueBuffer::accepts(
                    new InvalidArgument(
                        'First parameter of closure passed to function ' . $methodId . ' expects ' .
                            $closureParamType->getId() . ', ' . $inputType->getId() . ' provided',
                        new CodeLocation($statementsChecker->getSource(), $closureArg)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }
            }

            ++$i;
        }
    }

    /**
     * @param   StatementsChecker   $statementsChecker
     * @param   Type\Union          $inputType
     * @param   Type\Union          $paramType
     * @param   string|null         $casedMethodId
     * @param   int                 $argumentOffset
     * @param   CodeLocation        $codeLocation
     * @param   bool                $byRef
     * @param   bool                $variadic
     *
     * @return  null|false
     */
    public static function checkFunctionArgumentType(
        StatementsChecker $statementsChecker,
        Type\Union $inputType,
        Type\Union $paramType,
        $casedMethodId,
        $argumentOffset,
        CodeLocation $codeLocation,
        PhpParser\Node\Expr $inputExpr,
        Context $context,
        $byRef = false,
        $variadic = false
    ) {
        if ($paramType->isMixed()) {
            return null;
        }

        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;

        $methodIdentifier = $casedMethodId ? ' of ' . $casedMethodId : '';

        if ($projectChecker->inferTypesFromUsage && $inputExpr->inferredType) {
            $sourceChecker = $statementsChecker->getSource();

            if ($sourceChecker instanceof FunctionLikeChecker) {
                $context->inferType(
                    $inputExpr,
                    $sourceChecker->getFunctionLikeStorage($statementsChecker),
                    $paramType
                );
            }
        }

        if ($inputType->isMixed()) {
            $codebase->analyzer->incrementMixedCount($statementsChecker->getFilePath());

            if (IssueBuffer::accepts(
                new MixedArgument(
                    'Argument ' . ($argumentOffset + 1) . $methodIdentifier . ' cannot be mixed, expecting ' .
                        $paramType,
                    $codeLocation
                ),
                $statementsChecker->getSuppressedIssues()
            )) {
                // fall through
            }

            return null;
        }

        $codebase->analyzer->incrementNonMixedCount($statementsChecker->getFilePath());

        $paramType = TypeChecker::simplifyUnionType(
            $projectChecker->codebase,
            $paramType
        );

        $typeMatchFound = TypeChecker::isContainedBy(
            $codebase,
            $inputType,
            $paramType,
            true,
            true,
            $scalarTypeMatchFound,
            $typeCoerced,
            $typeCoercedFromMixed,
            $toStringCast
        );

        if ($typeCoerced) {
            if ($typeCoercedFromMixed) {
                if (IssueBuffer::accepts(
                    new MixedTypeCoercion(
                        'Argument ' . ($argumentOffset + 1) . $methodIdentifier . ' expects ' . $paramType->getId() .
                            ', parent type ' . $inputType->getId() . ' provided',
                        $codeLocation
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    // keep soldiering on
                }
            } else {
                if (IssueBuffer::accepts(
                    new TypeCoercion(
                        'Argument ' . ($argumentOffset + 1) . $methodIdentifier . ' expects ' . $paramType->getId() .
                            ', parent type ' . $inputType->getId() . ' provided',
                        $codeLocation
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    // keep soldiering on
                }
            }
        }

        if ($toStringCast && $casedMethodId !== 'echo') {
            if (IssueBuffer::accepts(
                new ImplicitToStringCast(
                    'Argument ' . ($argumentOffset + 1) . $methodIdentifier . ' expects ' .
                        $paramType . ', ' . $inputType . ' provided with a __toString method',
                    $codeLocation
                ),
                $statementsChecker->getSuppressedIssues()
            )) {
                // fall through
            }
        }

        if (!$typeMatchFound && !$typeCoerced) {
            $typesCanBeIdentical = TypeChecker::canBeContainedBy(
                $codebase,
                $inputType,
                $paramType,
                true,
                true
            );

            if ($scalarTypeMatchFound) {
                if ($casedMethodId !== 'echo') {
                    if (IssueBuffer::accepts(
                        new InvalidScalarArgument(
                            'Argument ' . ($argumentOffset + 1) . $methodIdentifier . ' expects ' .
                                $paramType . ', ' . $inputType . ' provided',
                            $codeLocation
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }
                }
            } elseif ($typesCanBeIdentical) {
                if (IssueBuffer::accepts(
                    new PossiblyInvalidArgument(
                        'Argument ' . ($argumentOffset + 1) . $methodIdentifier . ' expects ' . $paramType->getId() .
                            ', possibly different type ' . $inputType->getId() . ' provided',
                        $codeLocation
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }
            } elseif (IssueBuffer::accepts(
                new InvalidArgument(
                    'Argument ' . ($argumentOffset + 1) . $methodIdentifier . ' expects ' . $paramType->getId() .
                        ', ' . $inputType->getId() . ' provided',
                    $codeLocation
                ),
                $statementsChecker->getSuppressedIssues()
            )) {
                return false;
            }
        } elseif ($inputExpr instanceof PhpParser\Node\Scalar\String_
            || $inputExpr instanceof PhpParser\Node\Expr\Array_
        ) {
            foreach ($paramType->getTypes() as $paramTypePart) {
                if ($paramTypePart instanceof TClassString
                    && $inputExpr instanceof PhpParser\Node\Scalar\String_
                ) {
                    if (ClassLikeChecker::checkFullyQualifiedClassLikeName(
                        $statementsChecker,
                        $inputExpr->value,
                        $codeLocation,
                        $statementsChecker->getSuppressedIssues()
                    ) === false
                    ) {
                        return false;
                    }
                } elseif ($paramTypePart instanceof TArray
                    && $inputExpr instanceof PhpParser\Node\Expr\Array_
                ) {
                    foreach ($paramTypePart->typeParams[1]->getTypes() as $paramArrayTypePart) {
                        if ($paramArrayTypePart instanceof TClassString) {
                            foreach ($inputExpr->items as $item) {
                                if ($item && $item->value instanceof PhpParser\Node\Scalar\String_) {
                                    if (ClassLikeChecker::checkFullyQualifiedClassLikeName(
                                        $statementsChecker,
                                        $item->value->value,
                                        $codeLocation,
                                        $statementsChecker->getSuppressedIssues()
                                    ) === false
                                    ) {
                                        return false;
                                    }
                                }
                            }
                        }
                    }
                } elseif ($paramTypePart instanceof TCallable) {
                    $functionIds = self::getFunctionIdsFromCallableArg(
                        $statementsChecker,
                        $inputExpr
                    );

                    foreach ($functionIds as $functionId) {
                        if (strpos($functionId, '::') !== false) {
                            $functionIdParts = explode('&', $functionId);

                            $nonExistentMethodIds = [];
                            $hasValidMethod = false;

                            foreach ($functionIdParts as $functionIdPart) {
                                list($callableFqClassName, $methodName) = explode('::', $functionIdPart);

                                switch ($callableFqClassName) {
                                    case 'self':
                                    case 'static':
                                    case 'parent':
                                        $containerClass = $statementsChecker->getFQCLN();

                                        if ($callableFqClassName === 'parent') {
                                            $containerClass = $statementsChecker->getParentFQCLN();
                                        }

                                        if (!$containerClass) {
                                            continue 2;
                                        }

                                        $callableFqClassName = $containerClass;
                                }

                                $functionIdPart = $callableFqClassName . '::' . $methodName;

                                if (ClassLikeChecker::checkFullyQualifiedClassLikeName(
                                    $statementsChecker,
                                    $callableFqClassName,
                                    $codeLocation,
                                    $statementsChecker->getSuppressedIssues()
                                ) === false
                                ) {
                                    return false;
                                }

                                if (!$codebase->classOrInterfaceExists($callableFqClassName)) {
                                    return;
                                }

                                if (!$codebase->methodExists($functionIdPart)
                                    && !$codebase->methodExists($callableFqClassName . '::__call')
                                ) {
                                    $nonExistentMethodIds[] = $functionIdPart;
                                } else {
                                    $hasValidMethod = true;
                                }
                            }

                            if (!$hasValidMethod && !$paramType->hasString() && !$paramType->hasArray()) {
                                if (MethodChecker::checkMethodExists(
                                    $projectChecker,
                                    $nonExistentMethodIds[0],
                                    $codeLocation,
                                    $statementsChecker->getSuppressedIssues()
                                ) === false
                                ) {
                                    return false;
                                }
                            }
                        } else {
                            if (!$paramType->hasString() && !$paramType->hasArray() && self::checkFunctionExists(
                                $statementsChecker,
                                $functionId,
                                $codeLocation,
                                false
                            ) === false
                            ) {
                                return false;
                            }
                        }
                    }
                }
            }
        }

        if (!$paramType->isNullable() && $casedMethodId !== 'echo') {
            if ($inputType->isNull()) {
                if (IssueBuffer::accepts(
                    new NullArgument(
                        'Argument ' . ($argumentOffset + 1) . $methodIdentifier . ' cannot be null, ' .
                            'null value provided to parameter with type ' . $paramType,
                        $codeLocation
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }

                return null;
            }

            if ($inputType->isNullable() && !$inputType->ignoreNullableIssues) {
                if (IssueBuffer::accepts(
                    new PossiblyNullArgument(
                        'Argument ' . ($argumentOffset + 1) . $methodIdentifier . ' cannot be null, possibly ' .
                            'null value provided',
                        $codeLocation
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }
            }
        }

        if ($inputType->isFalsable()
            && !$paramType->hasBool()
            && !$paramType->hasScalar()
            && !$inputType->ignoreFalsableIssues
        ) {
            if (IssueBuffer::accepts(
                new PossiblyFalseArgument(
                    'Argument ' . ($argumentOffset + 1) . $methodIdentifier . ' cannot be false, possibly ' .
                        'false value provided',
                    $codeLocation
                ),
                $statementsChecker->getSuppressedIssues()
            )) {
                return false;
            }
        }

        if ($typeMatchFound
            && !$paramType->isMixed()
            && !$paramType->fromDocblock
            && !$variadic
            && !$byRef
        ) {
            $varId = ExpressionChecker::getVarId(
                $inputExpr,
                $statementsChecker->getFQCLN(),
                $statementsChecker
            );

            if ($varId) {
                if ($inputType->isNullable() && !$paramType->isNullable()) {
                    $inputType->removeType('null');
                }

                if ($inputType->getId() === $paramType->getId()) {
                    $inputType->fromDocblock = false;

                    foreach ($inputType->getTypes() as $atomicType) {
                        $atomicType->fromDocblock = false;
                    }
                }

                $context->removeVarFromConflictingClauses($varId, null, $statementsChecker);

                $context->varsInScope[$varId] = $inputType;
            }
        }

        return null;
    }

    /**
     * @param  PhpParser\Node\Scalar\String_|PhpParser\Node\Expr\Array_ $callableArg
     *
     * @return string[]
     */
    public static function getFunctionIdsFromCallableArg(
        \Psalm\FileSource $fileSource,
        $callableArg
    ) {
        if ($callableArg instanceof PhpParser\Node\Scalar\String_) {
            return [preg_replace('/^\\\/', '', $callableArg->value)];
        }

        if (count($callableArg->items) !== 2) {
            return [];
        }

        if (!isset($callableArg->items[0]) || !isset($callableArg->items[1])) {
            throw new \UnexpectedValueException('These should never be unset');
        }

        $classArg = $callableArg->items[0]->value;
        $methodNameArg = $callableArg->items[1]->value;

        if (!$methodNameArg instanceof PhpParser\Node\Scalar\String_) {
            return [];
        }

        if ($classArg instanceof PhpParser\Node\Scalar\String_) {
            return [preg_replace('/^\\\/', '', $classArg->value) . '::' . $methodNameArg->value];
        }

        if ($classArg instanceof PhpParser\Node\Expr\ClassConstFetch
            && $classArg->name instanceof PhpParser\Node\Identifier
            && strtolower($classArg->name->name) === 'class'
            && $classArg->class instanceof PhpParser\Node\Name
        ) {
            $fqClassName = ClassLikeChecker::getFQCLNFromNameObject(
                $classArg->class,
                $fileSource->getAliases()
            );

            return [$fqClassName . '::' . $methodNameArg->value];
        }

        if (!isset($classArg->inferredType) || !$classArg->inferredType->hasObjectType()) {
            return [];
        }

        $methodIds = [];

        foreach ($classArg->inferredType->getTypes() as $typePart) {
            if ($typePart instanceof TNamedObject) {
                $methodId = $typePart->value . '::' . $methodNameArg->value;

                if ($typePart->extraTypes) {
                    foreach ($typePart->extraTypes as $extraType) {
                        $methodId .= '&' . $extraType->value . '::' . $methodNameArg->value;
                    }
                }

                $methodIds[] = $methodId;
            }
        }

        return $methodIds;
    }

    /**
     * @param  StatementsChecker    $statementsChecker
     * @param  string               $functionId
     * @param  CodeLocation         $codeLocation
     * @param  bool                 $canBeInRootScope if true, the function can be shortened to the root version
     *
     * @return bool
     */
    protected static function checkFunctionExists(
        StatementsChecker $statementsChecker,
        &$functionId,
        CodeLocation $codeLocation,
        $canBeInRootScope
    ) {
        $casedFunctionId = $functionId;
        $functionId = strtolower($functionId);

        $codebase = $statementsChecker->getFileChecker()->projectChecker->codebase;

        if (!$codebase->functions->functionExists($statementsChecker, $functionId)) {
            $rootFunctionId = preg_replace('/.*\\\/', '', $functionId);

            if ($canBeInRootScope
                && $functionId !== $rootFunctionId
                && $codebase->functions->functionExists($statementsChecker, $rootFunctionId)
            ) {
                $functionId = $rootFunctionId;
            } else {
                if (IssueBuffer::accepts(
                    new UndefinedFunction(
                        'Function ' . $casedFunctionId . ' does not exist',
                        $codeLocation
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    // fall through
                }

                return false;
            }
        }

        return true;
    }

    /**
     * @param  StatementsChecker    $statementsChecker
     * @param  string               $functionId
     * @param  bool                 $canBeInRootScope if true, the function can be shortened to the root version
     *
     * @return string
     */
    protected static function getExistingFunctionId(
        StatementsChecker $statementsChecker,
        $functionId,
        $canBeInRootScope
    ) {
        $functionId = strtolower($functionId);

        $codebase = $statementsChecker->getFileChecker()->projectChecker->codebase;

        if ($codebase->functions->functionExists($statementsChecker, $functionId)) {
            return $functionId;
        }

        if (!$canBeInRootScope) {
            return $functionId;
        }

        $rootFunctionId = preg_replace('/.*\\\/', '', $functionId);

        if ($functionId !== $rootFunctionId
            && $codebase->functions->functionExists($statementsChecker, $rootFunctionId)
        ) {
            return $rootFunctionId;
        }

        return $functionId;
    }

    /**
     * @param  \Psalm\Storage\Assertion[] $assertions
     * @param  array<int, PhpParser\Node\Arg> $args
     * @param  Context           $context
     * @param  StatementsChecker $statementsChecker
     *
     * @return void
     */
    protected static function applyAssertionsToContext(
        array $assertions,
        array $args,
        Context $context,
        StatementsChecker $statementsChecker
    ) {
        $typeAssertions = [];

        foreach ($assertions as $assertion) {
            if (is_int($assertion->varId)) {
                if (!isset($args[$assertion->varId])) {
                    continue;
                }

                $argValue = $args[$assertion->varId]->value;

                $argVarId = ExpressionChecker::getArrayVarId($argValue, null, $statementsChecker);

                if ($argVarId) {
                    $typeAssertions[$argVarId] = $assertion->rule;
                }
            } else {
                $typeAssertions[$assertion->varId] = $assertion->rule;
            }
        }

        $changedVars = [];

        // while in an and, we allow scope to boil over to support
        // statements of the form if ($x && $x->foo())
        $opVarsInScope = \Psalm\Type\Reconciler::reconcileKeyedTypes(
            $typeAssertions,
            $context->varsInScope,
            $changedVars,
            [],
            $statementsChecker,
            null
        );

        foreach ($changedVars as $changedVar) {
            if (isset($opVarsInScope[$changedVar])) {
                $opVarsInScope[$changedVar]->fromDocblock = true;
            }
        }

        $context->varsInScope = $opVarsInScope;
    }
}
