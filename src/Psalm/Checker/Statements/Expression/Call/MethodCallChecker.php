<?php
namespace Psalm\Checker\Statements\Expression\Call;

use PhpParser;
use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\FunctionLikeChecker;
use Psalm\Checker\MethodChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Checker\TypeChecker;
use Psalm\Codebase\CallMap;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\Context;
use Psalm\FileManipulation\FileManipulationBuffer;
use Psalm\Issue\InvalidMethodCall;
use Psalm\Issue\InvalidPropertyAssignmentValue;
use Psalm\Issue\InvalidScope;
use Psalm\Issue\MixedMethodCall;
use Psalm\Issue\MixedTypeCoercion;
use Psalm\Issue\NullReference;
use Psalm\Issue\PossiblyFalseReference;
use Psalm\Issue\PossiblyInvalidMethodCall;
use Psalm\Issue\PossiblyInvalidPropertyAssignmentValue;
use Psalm\Issue\PossiblyNullReference;
use Psalm\Issue\PossiblyUndefinedMethod;
use Psalm\Issue\TypeCoercion;
use Psalm\Issue\UndefinedMethod;
use Psalm\Issue\UndefinedThisPropertyAssignment;
use Psalm\Issue\UndefinedThisPropertyFetch;
use Psalm\IssueBuffer;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;

class MethodCallChecker extends \Psalm\Checker\Statements\Expression\CallChecker
{
    /**
     * @param   StatementsChecker               $statementsChecker
     * @param   PhpParser\Node\Expr\MethodCall  $stmt
     * @param   Context                         $context
     *
     * @return  false|null
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\MethodCall $stmt,
        Context $context
    ) {
        $stmt->inferredType = null;

        if (ExpressionChecker::analyze($statementsChecker, $stmt->var, $context) === false) {
            return false;
        }

        if (!$stmt->name instanceof PhpParser\Node\Identifier) {
            if (ExpressionChecker::analyze($statementsChecker, $stmt->name, $context) === false) {
                return false;
            }
        }

        $methodId = null;

        if ($stmt->var instanceof PhpParser\Node\Expr\Variable) {
            if (is_string($stmt->var->name) && $stmt->var->name === 'this' && !$statementsChecker->getFQCLN()) {
                if (IssueBuffer::accepts(
                    new InvalidScope(
                        'Use of $this in non-class context',
                        new CodeLocation($statementsChecker->getSource(), $stmt)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }
            }
        }

        $varId = ExpressionChecker::getArrayVarId(
            $stmt->var,
            $statementsChecker->getFQCLN(),
            $statementsChecker
        );

        $classType = $varId && $context->hasVariable($varId, $statementsChecker)
            ? $context->varsInScope[$varId]
            : null;

        if (isset($stmt->var->inferredType)) {
            $classType = $stmt->var->inferredType;
        } elseif (!$classType) {
            $stmt->inferredType = Type::getMixed();
        }

        $source = $statementsChecker->getSource();

        if (!$context->checkMethods || !$context->checkClasses) {
            if (self::checkFunctionArguments(
                $statementsChecker,
                $stmt->args,
                null,
                null,
                $context
            ) === false) {
                return false;
            }

            return null;
        }

        $hasMock = false;

        if ($classType && $stmt->name instanceof PhpParser\Node\Identifier && $classType->isNull()) {
            if (IssueBuffer::accepts(
                new NullReference(
                    'Cannot call method ' . $stmt->name->name . ' on null variable ' . $varId,
                    new CodeLocation($statementsChecker->getSource(), $stmt->var)
                ),
                $statementsChecker->getSuppressedIssues()
            )) {
                return false;
            }

            return null;
        }

        if ($classType
            && $stmt->name instanceof PhpParser\Node\Identifier
            && $classType->isNullable()
            && !$classType->ignoreNullableIssues
        ) {
            if (IssueBuffer::accepts(
                new PossiblyNullReference(
                    'Cannot call method ' . $stmt->name->name . ' on possibly null variable ' . $varId,
                    new CodeLocation($statementsChecker->getSource(), $stmt->var)
                ),
                $statementsChecker->getSuppressedIssues()
            )) {
                return false;
            }
        }

        if ($classType
            && $stmt->name instanceof PhpParser\Node\Identifier
            && $classType->isFalsable()
            && !$classType->ignoreFalsableIssues
        ) {
            if (IssueBuffer::accepts(
                new PossiblyFalseReference(
                    'Cannot call method ' . $stmt->name->name . ' on possibly false variable ' . $varId,
                    new CodeLocation($statementsChecker->getSource(), $stmt->var)
                ),
                $statementsChecker->getSuppressedIssues()
            )) {
                return false;
            }
        }

        $config = Config::getInstance();
        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;

        $nonExistentMethodIds = [];
        $existentMethodIds = [];

        $invalidMethodCallTypes = [];
        $hasValidMethodCallType = false;

        $codeLocation = new CodeLocation($source, $stmt);
        $nameCodeLocation = new CodeLocation($source, $stmt->name);

        $returnsByRef = false;

        if ($classType) {
            $returnType = null;

            $lhsTypes = $classType->getTypes();

            foreach ($lhsTypes as $lhsTypePart) {
                if (!$lhsTypePart instanceof TNamedObject) {
                    switch (get_class($lhsTypePart)) {
                        case Type\Atomic\TNull::class:
                        case Type\Atomic\TFalse::class:
                            // handled above
                            break;

                        case Type\Atomic\TInt::class:
                        case Type\Atomic\TLiteralInt::class:
                        case Type\Atomic\TFloat::class:
                        case Type\Atomic\TLiteralFloat::class:
                        case Type\Atomic\TBool::class:
                        case Type\Atomic\TTrue::class:
                        case Type\Atomic\TArray::class:
                        case Type\Atomic\ObjectLike::class:
                        case Type\Atomic\TString::class:
                        case Type\Atomic\TSingleLetter::class:
                        case Type\Atomic\TLiteralString::class:
                        case Type\Atomic\TLiteralClassString::class:
                        case Type\Atomic\TNumericString::class:
                        case Type\Atomic\TClassString::class:
                        case Type\Atomic\TEmptyMixed::class:
                            $invalidMethodCallTypes[] = (string)$lhsTypePart;
                            break;

                        case Type\Atomic\TMixed::class:
                        case Type\Atomic\TGenericParam::class:
                        case Type\Atomic\TObject::class:
                            $codebase->analyzer->incrementMixedCount($statementsChecker->getFilePath());

                            if (IssueBuffer::accepts(
                                new MixedMethodCall(
                                    'Cannot call method on a mixed variable ' . $varId,
                                    $nameCodeLocation
                                ),
                                $statementsChecker->getSuppressedIssues()
                            )) {
                                // fall through
                            }

                            if (self::checkFunctionArguments(
                                $statementsChecker,
                                $stmt->args,
                                null,
                                null,
                                $context
                            ) === false) {
                                return false;
                            }

                            $returnType = Type::getMixed();
                            break;
                    }

                    continue;
                }

                $codebase->analyzer->incrementNonMixedCount($statementsChecker->getFilePath());

                $hasValidMethodCallType = true;

                $fqClassName = $lhsTypePart->value;

                $intersectionTypes = $lhsTypePart->getIntersectionTypes();

                $isMock = ExpressionChecker::isMock($fqClassName);

                $hasMock = $hasMock || $isMock;

                if ($fqClassName === 'static') {
                    $fqClassName = (string) $context->self;
                }

                if ($isMock ||
                    $context->isPhantomClass($fqClassName)
                ) {
                    $returnType = Type::getMixed();
                    continue;
                }

                if ($varId === '$this') {
                    $doesClassExist = true;
                } else {
                    $doesClassExist = ClassLikeChecker::checkFullyQualifiedClassLikeName(
                        $statementsChecker,
                        $fqClassName,
                        new CodeLocation($source, $stmt->var),
                        $statementsChecker->getSuppressedIssues()
                    );
                }

                if (!$doesClassExist) {
                    return $doesClassExist;
                }

                if ($fqClassName === 'iterable') {
                    if (IssueBuffer::accepts(
                        new UndefinedMethod(
                            $fqClassName . ' has no defined methods',
                            new CodeLocation($source, $stmt->var),
                            $fqClassName . '::'
                                . (!$stmt->name instanceof PhpParser\Node\Identifier ? '$method' : $stmt->name->name)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }

                    return;
                }

                if (!$stmt->name instanceof PhpParser\Node\Identifier) {
                    $returnType = Type::getMixed();
                    break;
                }

                $methodNameLc = strtolower($stmt->name->name);

                $methodId = $fqClassName . '::' . $methodNameLc;

                $intersectionMethodId = $intersectionTypes
                    ? '(' . $lhsTypePart . ')'  . '::' . $stmt->name->name
                    : null;

                $args = $stmt->args;

                if ($codebase->methodExists($fqClassName . '::__call')) {
                    if (!$codebase->methodExists($methodId)
                        || !MethodChecker::isMethodVisible(
                            $methodId,
                            $context->self,
                            $statementsChecker->getSource()
                        )
                    ) {
                        if ($varId !== '$this') {
                            $classStorage = $projectChecker->classlikeStorageProvider->get($fqClassName);

                            if (isset($classStorage->pseudoMethods[$methodNameLc])) {
                                $hasValidMethodCallType = true;
                                $existentMethodIds[] = $methodId;

                                $pseudoMethodStorage = $classStorage->pseudoMethods[$methodNameLc];

                                if (self::checkFunctionArguments(
                                    $statementsChecker,
                                    $args,
                                    $pseudoMethodStorage->params,
                                    $methodId,
                                    $context
                                ) === false) {
                                    return false;
                                }

                                $genericParams = [];

                                if (self::checkFunctionLikeArgumentsMatch(
                                    $statementsChecker,
                                    $args,
                                    null,
                                    $pseudoMethodStorage->params,
                                    $pseudoMethodStorage,
                                    null,
                                    $genericParams,
                                    $codeLocation,
                                    $context
                                ) === false) {
                                    return false;
                                }

                                if ($pseudoMethodStorage->returnType) {
                                    $returnTypeCandidate = clone $pseudoMethodStorage->returnType;

                                    if (!$returnType) {
                                        $returnType = $returnTypeCandidate;
                                    } else {
                                        $returnType = Type::combineUnionTypes($returnTypeCandidate, $returnType);
                                    }

                                    continue;
                                }
                            } else {
                                if (self::checkFunctionArguments(
                                    $statementsChecker,
                                    $args,
                                    null,
                                    null,
                                    $context
                                ) === false) {
                                    return false;
                                }

                                if ($classStorage->sealedMethods) {
                                    $nonExistentMethodIds[] = $methodId;
                                    continue;
                                }
                            }
                        }

                        $hasValidMethodCallType = true;
                        $existentMethodIds[] = $methodId;

                        $arrayValues = array_map(
                            /**
                             * @return PhpParser\Node\Expr\ArrayItem
                             */
                            function (PhpParser\Node\Arg $arg) {
                                return new PhpParser\Node\Expr\ArrayItem($arg->value);
                            },
                            $args
                        );

                        $args = [
                            new PhpParser\Node\Arg(new PhpParser\Node\Scalar\String_($methodNameLc)),
                            new PhpParser\Node\Arg(new PhpParser\Node\Expr\Array_($arrayValues)),
                        ];

                        $methodId = $fqClassName . '::__call';
                    }
                }

                $sourceSource = $statementsChecker->getSource();

                /**
                 * @var \Psalm\Checker\ClassLikeChecker|null
                 */
                $classlikeSource = $sourceSource->getSource();
                $classlikeSourceFqcln = $classlikeSource ? $classlikeSource->getFQCLN() : null;

                if ($varId === '$this'
                    && $context->self
                    && $classlikeSourceFqcln
                    && $fqClassName !== $context->self
                    && $codebase->methodExists($context->self . '::' . $methodNameLc)
                ) {
                    $methodId = $context->self . '::' . $methodNameLc;
                    $fqClassName = $context->self;
                }

                if ($intersectionTypes && !$codebase->methodExists($methodId)) {
                    foreach ($intersectionTypes as $intersectionType) {
                        $methodId = $intersectionType->value . '::' . $methodNameLc;
                        $fqClassName = $intersectionType->value;

                        if ($codebase->methodExists($methodId)) {
                            break;
                        }
                    }
                }

                $sourceMethodId = $source instanceof FunctionLikeChecker
                    ? $source->getMethodId()
                    : null;

                if (!$codebase->methodExists($methodId, $methodId !== $sourceMethodId ? $codeLocation : null)) {
                    if ($config->usePhpdocMethodsWithoutCall) {
                        $classStorage = $projectChecker->classlikeStorageProvider->get($fqClassName);

                        if (isset($classStorage->pseudoMethods[$methodNameLc])) {
                            $hasValidMethodCallType = true;
                            $existentMethodIds[] = $methodId;

                            $pseudoMethodStorage = $classStorage->pseudoMethods[$methodNameLc];

                            if (self::checkFunctionArguments(
                                $statementsChecker,
                                $args,
                                $pseudoMethodStorage->params,
                                $methodId,
                                $context
                            ) === false) {
                                return false;
                            }

                            $genericParams = [];

                            if (self::checkFunctionLikeArgumentsMatch(
                                $statementsChecker,
                                $args,
                                null,
                                $pseudoMethodStorage->params,
                                $pseudoMethodStorage,
                                null,
                                $genericParams,
                                $codeLocation,
                                $context
                            ) === false) {
                                return false;
                            }

                            if ($pseudoMethodStorage->returnType) {
                                $returnTypeCandidate = clone $pseudoMethodStorage->returnType;

                                if (!$returnType) {
                                    $returnType = $returnTypeCandidate;
                                } else {
                                    $returnType = Type::combineUnionTypes($returnTypeCandidate, $returnType);
                                }

                                continue;
                            }

                            $returnType = Type::getMixed();

                            continue;
                        }
                    }

                    $nonExistentMethodIds[] = $intersectionMethodId ?: $methodId;
                    continue;
                }

                $existentMethodIds[] = $methodId;

                $classTemplateParams = null;

                if ($stmt->var instanceof PhpParser\Node\Expr\Variable
                    && ($context->collectInitializations || $context->collectMutations)
                    && $stmt->var->name === 'this'
                    && $source instanceof FunctionLikeChecker
                ) {
                    self::collectSpecialInformation($source, $stmt->name->name, $context);
                }

                $classStorage = $projectChecker->classlikeStorageProvider->get($fqClassName);

                if ($classStorage->templateTypes) {
                    $classTemplateParams = [];

                    if ($lhsTypePart instanceof TGenericObject) {
                        $reversedClassTemplateTypes = array_reverse(array_keys($classStorage->templateTypes));

                        $providedTypeParamCount = count($lhsTypePart->typeParams);

                        foreach ($reversedClassTemplateTypes as $i => $typeName) {
                            if (isset($lhsTypePart->typeParams[$providedTypeParamCount - 1 - $i])) {
                                $classTemplateParams[$typeName] =
                                    $lhsTypePart->typeParams[$providedTypeParamCount - 1 - $i];
                            } else {
                                $classTemplateParams[$typeName] = Type::getMixed();
                            }
                        }
                    } else {
                        foreach ($classStorage->templateTypes as $typeName => $_) {
                            if (!$stmt->var instanceof PhpParser\Node\Expr\Variable
                                || $stmt->var->name !== 'this'
                            ) {
                                $classTemplateParams[$typeName] = Type::getMixed();
                            }
                        }
                    }
                }

                if (self::checkMethodArgs(
                    $methodId,
                    $args,
                    $classTemplateParams,
                    $context,
                    $codeLocation,
                    $statementsChecker
                ) === false) {
                    return false;
                }

                switch (strtolower($stmt->name->name)) {
                    case '__tostring':
                        $returnType = Type::getString();
                        continue 2;
                }

                $callMapId = strtolower(
                    $codebase->methods->getDeclaringMethodId($methodId) ?: $methodId
                );

                if ($methodNameLc === '__tostring') {
                    $returnTypeCandidate = Type::getString();
                } elseif ($callMapId && CallMap::inCallMap($callMapId)) {
                    if (($classTemplateParams || $classStorage->stubbed)
                        && isset($classStorage->methods[$methodNameLc])
                        && ($methodStorage = $classStorage->methods[$methodNameLc])
                        && $methodStorage->returnType
                    ) {
                        $returnTypeCandidate = clone $methodStorage->returnType;

                        if ($classTemplateParams) {
                            $returnTypeCandidate->replaceTemplateTypesWithArgTypes(
                                $classTemplateParams
                            );
                        }
                    } else {
                        if ($callMapId === 'domnode::appendchild'
                            && isset($args[0]->value->inferredType)
                            && $args[0]->value->inferredType->hasObjectType()
                        ) {
                            $returnTypeCandidate = clone $args[0]->value->inferredType;
                        } elseif ($callMapId === 'simplexmlelement::asxml' && !count($args)) {
                            $returnTypeCandidate = Type::parseString('string|false');
                        } else {
                            $returnTypeCandidate = CallMap::getReturnTypeFromCallMap($callMapId);
                            if ($returnTypeCandidate->isFalsable()) {
                                $returnTypeCandidate->ignoreFalsableIssues = true;
                            }
                        }
                    }

                    $returnTypeCandidate = ExpressionChecker::fleshOutType(
                        $projectChecker,
                        $returnTypeCandidate,
                        $fqClassName,
                        $fqClassName
                    );
                } else {
                    if (MethodChecker::checkMethodVisibility(
                        $methodId,
                        $context->self,
                        $statementsChecker->getSource(),
                        $nameCodeLocation,
                        $statementsChecker->getSuppressedIssues()
                    ) === false) {
                        return false;
                    }

                    if (MethodChecker::checkMethodNotDeprecated(
                        $projectChecker,
                        $methodId,
                        $nameCodeLocation,
                        $statementsChecker->getSuppressedIssues()
                    ) === false) {
                        return false;
                    }

                    if (!self::checkMagicGetterOrSetterProperty(
                        $statementsChecker,
                        $projectChecker,
                        $stmt,
                        $fqClassName
                    )) {
                        return false;
                    }

                    $selfFqClassName = $fqClassName;

                    $returnTypeCandidate = $codebase->methods->getMethodReturnType(
                        $methodId,
                        $selfFqClassName,
                        $args
                    );

                    if (isset($stmt->inferredType)) {
                        $returnTypeCandidate = $stmt->inferredType;
                    }

                    if ($returnTypeCandidate) {
                        $returnTypeCandidate = clone $returnTypeCandidate;

                        if ($classTemplateParams) {
                            $returnTypeCandidate->replaceTemplateTypesWithArgTypes(
                                $classTemplateParams
                            );
                        }

                        $returnTypeCandidate = ExpressionChecker::fleshOutType(
                            $projectChecker,
                            $returnTypeCandidate,
                            $selfFqClassName,
                            $fqClassName
                        );

                        $returnTypeLocation = $codebase->methods->getMethodReturnTypeLocation(
                            $methodId,
                            $secondaryReturnTypeLocation
                        );

                        if ($secondaryReturnTypeLocation) {
                            $returnTypeLocation = $secondaryReturnTypeLocation;
                        }

                        // only check the type locally if it's defined externally
                        if ($returnTypeLocation && !$config->isInProjectDirs($returnTypeLocation->filePath)) {
                            $returnTypeCandidate->check(
                                $statementsChecker,
                                new CodeLocation($source, $stmt),
                                $statementsChecker->getSuppressedIssues(),
                                $context->phantomClasses
                            );
                        }
                    } else {
                        $returnsByRef =
                            $returnsByRef
                                || $codebase->methods->getMethodReturnsByRef($methodId);
                    }

                    if (count($lhsTypes) === 1) {
                        $methodStorage = $codebase->methods->getUserMethodStorage($methodId);

                        if ($methodStorage->assertions) {
                            self::applyAssertionsToContext(
                                $methodStorage->assertions,
                                $args,
                                $context,
                                $statementsChecker
                            );
                        }

                        if ($methodStorage->ifTrueAssertions) {
                            $stmt->ifTrueAssertions = $methodStorage->ifTrueAssertions;
                        }

                        if ($methodStorage->ifFalseAssertions) {
                            $stmt->ifFalseAssertions = $methodStorage->ifFalseAssertions;
                        }
                    }
                }

                if (!$args && $varId) {
                    if ($config->memoizeMethodCalls) {
                        $methodVarId = $varId . '->' . $methodNameLc . '()';

                        if (isset($context->varsInScope[$methodVarId])) {
                            $returnTypeCandidate = clone $context->varsInScope[$methodVarId];
                        } elseif ($returnTypeCandidate) {
                            $context->varsInScope[$methodVarId] = $returnTypeCandidate;
                        }
                    }
                }

                if ($config->afterMethodChecks) {
                    $fileManipulations = [];

                    $appearingMethodId = $codebase->methods->getAppearingMethodId($methodId);
                    $declaringMethodId = $codebase->methods->getDeclaringMethodId($methodId);

                    foreach ($config->afterMethodChecks as $pluginFqClassName) {
                        $pluginFqClassName::afterMethodCallCheck(
                            $statementsChecker,
                            $methodId,
                            $appearingMethodId,
                            $declaringMethodId,
                            $varId,
                            $args,
                            $codeLocation,
                            $context,
                            $fileManipulations,
                            $returnTypeCandidate
                        );
                    }

                    if ($fileManipulations) {
                        /** @psalm-suppress MixedTypeCoercion */
                        FileManipulationBuffer::add($statementsChecker->getFilePath(), $fileManipulations);
                    }
                }

                if ($returnTypeCandidate) {
                    if (!$returnType) {
                        $returnType = $returnTypeCandidate;
                    } else {
                        $returnType = Type::combineUnionTypes($returnTypeCandidate, $returnType);
                    }
                } else {
                    $returnType = Type::getMixed();
                }
            }

            if ($invalidMethodCallTypes) {
                $invalidClassType = $invalidMethodCallTypes[0];

                if ($hasValidMethodCallType) {
                    if (IssueBuffer::accepts(
                        new PossiblyInvalidMethodCall(
                            'Cannot call method on possible ' . $invalidClassType . ' variable ' . $varId,
                            $nameCodeLocation
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }
                } else {
                    if (IssueBuffer::accepts(
                        new InvalidMethodCall(
                            'Cannot call method on ' . $invalidClassType . ' variable ' . $varId,
                            $nameCodeLocation
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }
                }
            }

            if ($nonExistentMethodIds) {
                if ($existentMethodIds) {
                    if (IssueBuffer::accepts(
                        new PossiblyUndefinedMethod(
                            'Method ' . $nonExistentMethodIds[0] . ' does not exist',
                            $nameCodeLocation,
                            $nonExistentMethodIds[0]
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }
                } else {
                    if (IssueBuffer::accepts(
                        new UndefinedMethod(
                            'Method ' . $nonExistentMethodIds[0] . ' does not exist',
                            $nameCodeLocation,
                            $nonExistentMethodIds[0]
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }
                }

                return null;
            }

            $stmt->inferredType = $returnType;

            if ($returnsByRef) {
                if (!$stmt->inferredType) {
                    $stmt->inferredType = Type::getMixed();
                }

                $stmt->inferredType->byRef = $returnsByRef;
            }
        }

        if ($methodId === null) {
            return self::checkMethodArgs(
                $methodId,
                $stmt->args,
                $foundGenericParams,
                $context,
                new CodeLocation($statementsChecker->getSource(), $stmt),
                $statementsChecker
            );
        }

        if (!$config->rememberPropertyAssignmentsAfterCall && !$context->collectInitializations) {
            $context->removeAllObjectVars();
        }

        // if we called a method on this nullable variable, remove the nullable status here
        // because any further calls must have worked
        if ($varId
            && $classType
            && $hasValidMethodCallType
            && !$invalidMethodCallTypes
            && $existentMethodIds
            && ($classType->fromDocblock || $classType->isNullable())
        ) {
            $keysToRemove = [];

            foreach ($classType->getTypes() as $key => $type) {
                if (!$type instanceof TNamedObject) {
                    $keysToRemove[] = $key;
                } else {
                    $type->fromDocblock = false;
                }
            }

            foreach ($keysToRemove as $key) {
                $classType->removeType($key);
            }

            $classType->fromDocblock = false;

            $context->removeVarFromConflictingClauses($varId, null, $statementsChecker);

            $context->varsInScope[$varId] = $classType;
        }
    }

    /**
     * Check properties accessed with magic getters and setters.
     * If `@psalm-seal-properties` is set, they must be defined.
     * If an `@property` annotation is specified, the setter must set something with the correct
     * type.
     *
     * @param StatementsChecker $statementsChecker
     * @param \Psalm\Checker\ProjectChecker $projectChecker
     * @param PhpParser\Node\Expr\MethodCall $stmt
     * @param string $fqClassName
     *
     * @return bool
     */
    private static function checkMagicGetterOrSetterProperty(
        StatementsChecker $statementsChecker,
        \Psalm\Checker\ProjectChecker $projectChecker,
        PhpParser\Node\Expr\MethodCall $stmt,
        $fqClassName
    ) {
        if (!$stmt->name instanceof PhpParser\Node\Identifier) {
            return true;
        }

        $methodName = strtolower($stmt->name->name);
        if (!in_array($methodName, ['__get', '__set'], true)) {
            return true;
        }

        $firstArgValue = $stmt->args[0]->value;
        if (!$firstArgValue instanceof PhpParser\Node\Scalar\String_) {
            return true;
        }

        $propName = $firstArgValue->value;
        $propertyId = $fqClassName . '::$' . $propName;
        $classStorage = $projectChecker->classlikeStorageProvider->get($fqClassName);

        switch ($methodName) {
            case '__set':
                // If `@psalm-seal-properties` is set, the property must be defined with
                // a `@property` annotation
                if ($classStorage->sealedProperties
                    && !isset($classStorage->pseudoPropertySetTypes['$' . $propName])
                    && IssueBuffer::accepts(
                        new UndefinedThisPropertyAssignment(
                            'Instance property ' . $propertyId . ' is not defined',
                            new CodeLocation($statementsChecker->getSource(), $stmt),
                            $propertyId
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )
                ) {
                    return false;
                }

                // If a `@property` annotation is set, the type of the value passed to the
                // magic setter must match the annotation.
                $secondArgType = isset($stmt->args[1]->value->inferredType)
                    ? $stmt->args[1]->value->inferredType
                    : null;

                if (isset($classStorage->pseudoPropertySetTypes['$' . $propName]) && $secondArgType) {
                    $pseudoSetType = ExpressionChecker::fleshOutType(
                        $projectChecker,
                        $classStorage->pseudoPropertySetTypes['$' . $propName],
                        $fqClassName,
                        $fqClassName
                    );

                    $typeMatchFound = TypeChecker::isContainedBy(
                        $projectChecker->codebase,
                        $secondArgType,
                        $pseudoSetType,
                        $secondArgType->ignoreNullableIssues,
                        $secondArgType->ignoreFalsableIssues,
                        $hasScalarMatch,
                        $typeCoerced,
                        $typeCoercedFromMixed,
                        $toStringCast
                    );

                    if ($typeCoerced) {
                        if ($typeCoercedFromMixed) {
                            if (IssueBuffer::accepts(
                                new MixedTypeCoercion(
                                    $propName . ' expects \'' . $pseudoSetType . '\', '
                                        . ' parent type `' . $secondArgType . '` provided',
                                    new CodeLocation($statementsChecker->getSource(), $stmt)
                                ),
                                $statementsChecker->getSuppressedIssues()
                            )) {
                                // keep soldiering on
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new TypeCoercion(
                                    $propName . ' expects \'' . $pseudoSetType . '\', '
                                        . ' parent type `' . $secondArgType . '` provided',
                                    new CodeLocation($statementsChecker->getSource(), $stmt)
                                ),
                                $statementsChecker->getSuppressedIssues()
                            )) {
                                // keep soldiering on
                            }
                        }
                    }

                    if (!$typeMatchFound && !$typeCoercedFromMixed) {
                        if (TypeChecker::canBeContainedBy(
                            $projectChecker->codebase,
                            $secondArgType,
                            $pseudoSetType
                        )) {
                            if (IssueBuffer::accepts(
                                new PossiblyInvalidPropertyAssignmentValue(
                                    $propName . ' with declared type \''
                                    . $pseudoSetType
                                    . '\' cannot be assigned possibly different type \'' . $secondArgType . '\'',
                                    new CodeLocation($statementsChecker->getSource(), $stmt),
                                    $propertyId
                                ),
                                $statementsChecker->getSuppressedIssues()
                            )) {
                                return false;
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new InvalidPropertyAssignmentValue(
                                    $propName . ' with declared type \''
                                    . $pseudoSetType
                                    . '\' cannot be assigned type \'' . $secondArgType . '\'',
                                    new CodeLocation($statementsChecker->getSource(), $stmt),
                                    $propertyId
                                ),
                                $statementsChecker->getSuppressedIssues()
                            )) {
                                return false;
                            }
                        }
                    }
                }
                break;

            case '__get':
                // If `@psalm-seal-properties` is set, the property must be defined with
                // a `@property` annotation
                if ($classStorage->sealedProperties
                    && !isset($classStorage->pseudoPropertyGetTypes['$' . $propName])
                    && IssueBuffer::accepts(
                        new UndefinedThisPropertyFetch(
                            'Instance property ' . $propertyId . ' is not defined',
                            new CodeLocation($statementsChecker->getSource(), $stmt),
                            $propertyId
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )
                ) {
                    return false;
                }

                if (isset($classStorage->pseudoPropertyGetTypes['$' . $propName])) {
                    $stmt->inferredType = clone $classStorage->pseudoPropertyGetTypes['$' . $propName];
                }

                break;
        }

        return true;
    }
}
