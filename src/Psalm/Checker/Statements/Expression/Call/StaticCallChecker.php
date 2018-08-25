<?php
namespace Psalm\Checker\Statements\Expression\Call;

use PhpParser;
use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\FunctionLikeChecker;
use Psalm\Checker\MethodChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\Context;
use Psalm\FileManipulation\FileManipulationBuffer;
use Psalm\Issue\DeprecatedClass;
use Psalm\Issue\InvalidStringClass;
use Psalm\Issue\ParentNotFound;
use Psalm\Issue\UndefinedClass;
use Psalm\Issue\UndefinedMethod;
use Psalm\IssueBuffer;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;

class StaticCallChecker extends \Psalm\Checker\Statements\Expression\CallChecker
{
    /**
     * @param   StatementsChecker               $statementsChecker
     * @param   PhpParser\Node\Expr\StaticCall  $stmt
     * @param   Context                         $context
     *
     * @return  false|null
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\StaticCall $stmt,
        Context $context
    ) {
        $methodId = null;

        $lhsType = null;

        $fileChecker = $statementsChecker->getFileChecker();
        $projectChecker = $fileChecker->projectChecker;
        $codebase = $projectChecker->codebase;
        $source = $statementsChecker->getSource();

        $stmt->inferredType = null;

        $config = $projectChecker->config;

        if ($stmt->class instanceof PhpParser\Node\Name) {
            $fqClassName = null;

            if (count($stmt->class->parts) === 1
                && in_array(strtolower($stmt->class->parts[0]), ['self', 'static', 'parent'], true)
            ) {
                if ($stmt->class->parts[0] === 'parent') {
                    $childFqClassName = $context->self;

                    $classStorage = $childFqClassName
                        ? $projectChecker->classlikeStorageProvider->get($childFqClassName)
                        : null;

                    if (!$classStorage || !$classStorage->parentClasses) {
                        if (IssueBuffer::accepts(
                            new ParentNotFound(
                                'Cannot call method on parent as this class does not extend another',
                                new CodeLocation($statementsChecker->getSource(), $stmt)
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            return false;
                        }

                        return;
                    }

                    $fqClassName = reset($classStorage->parentClasses);

                    $classStorage = $projectChecker->classlikeStorageProvider->get($fqClassName);

                    $fqClassName = $classStorage->name;

                    if ($stmt->name instanceof PhpParser\Node\Identifier
                        && $classStorage->userDefined
                        && ($context->collectMutations || $context->collectInitializations)
                    ) {
                        $methodId = $fqClassName . '::' . strtolower($stmt->name->name);

                        $appearingMethodId = $codebase->getAppearingMethodId($methodId);

                        if (!$appearingMethodId) {
                            if (IssueBuffer::accepts(
                                new UndefinedMethod(
                                    'Method ' . $methodId . ' does not exist',
                                    new CodeLocation($statementsChecker->getSource(), $stmt),
                                    $methodId
                                ),
                                $statementsChecker->getSuppressedIssues()
                            )) {
                                return false;
                            }

                            return;
                        }

                        list($appearingMethodClassName) = explode('::', $appearingMethodId);

                        $oldContextIncludeLocation = $context->includeLocation;
                        $oldSelf = $context->self;
                        $context->includeLocation = new CodeLocation($statementsChecker->getSource(), $stmt);
                        $context->self = $appearingMethodClassName;

                        if ($context->collectMutations) {
                            $fileChecker->getMethodMutations($methodId, $context);
                        } else {
                            // collecting initializations
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

                            if (!isset($context->initializedMethods[$methodId])) {
                                if ($context->initializedMethods === null) {
                                    $context->initializedMethods = [];
                                }

                                $context->initializedMethods[$methodId] = true;

                                $fileChecker->getMethodMutations($methodId, $context);

                                foreach ($localVarsInScope as $var => $type) {
                                    $context->varsInScope[$var] = $type;
                                }

                                foreach ($localVarsPossiblyInScope as $var => $type) {
                                    $context->varsPossiblyInScope[$var] = $type;
                                }
                            }
                        }

                        $context->includeLocation = $oldContextIncludeLocation;
                        $context->self = $oldSelf;

                        if (isset($context->varsInScope['$this']) && $oldSelf) {
                            $context->varsInScope['$this'] = Type::parseString($oldSelf);
                        }
                    }
                } elseif ($context->self) {
                    if ($stmt->class->parts[0] === 'static' && isset($context->varsInScope['$this'])) {
                        $fqClassName = (string) $context->varsInScope['$this'];
                        $lhsType = clone $context->varsInScope['$this'];
                    } else {
                        $fqClassName = $context->self;
                    }
                } else {
                    $namespace = $statementsChecker->getNamespace()
                        ? $statementsChecker->getNamespace() . '\\'
                        : '';

                    $fqClassName = $namespace . $statementsChecker->getClassName();
                }

                if ($context->isPhantomClass($fqClassName)) {
                    return null;
                }
            } elseif ($context->checkClasses) {
                $fqClassName = ClassLikeChecker::getFQCLNFromNameObject(
                    $stmt->class,
                    $statementsChecker->getAliases()
                );

                if ($context->isPhantomClass($fqClassName)) {
                    return null;
                }

                $doesClassExist = false;

                if ($context->self) {
                    $selfStorage = $projectChecker->classlikeStorageProvider->get($context->self);

                    if (isset($selfStorage->usedTraits[strtolower($fqClassName)])) {
                        $fqClassName = $context->self;
                        $doesClassExist = true;
                    }
                }

                if (!$doesClassExist) {
                    $doesClassExist = ClassLikeChecker::checkFullyQualifiedClassLikeName(
                        $statementsChecker,
                        $fqClassName,
                        new CodeLocation($source, $stmt->class),
                        $statementsChecker->getSuppressedIssues(),
                        false
                    );
                }

                if (!$doesClassExist) {
                    return $doesClassExist;
                }
            }

            if ($fqClassName && !$lhsType) {
                $lhsType = new Type\Union([new TNamedObject($fqClassName)]);
            }
        } else {
            ExpressionChecker::analyze($statementsChecker, $stmt->class, $context);
            $lhsType = $stmt->class->inferredType;
        }

        if (!$context->checkMethods || !$lhsType) {
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

        foreach ($lhsType->getTypes() as $lhsTypePart) {
            if (!$lhsTypePart instanceof TNamedObject) {
                // this is always OK
                if ($lhsTypePart instanceof Type\Atomic\TClassString) {
                    continue;
                }

                // ok for now
                if ($lhsTypePart instanceof Type\Atomic\TLiteralClassString) {
                    continue;
                }

                if ($lhsTypePart instanceof Type\Atomic\TString) {
                    if ($config->allowStringStandinForClass
                        && !$lhsTypePart instanceof Type\Atomic\TNumericString
                    ) {
                        continue;
                    }

                    if (IssueBuffer::accepts(
                        new InvalidStringClass(
                            'String cannot be used as a class',
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }

                    continue;
                }

                if ($lhsTypePart instanceof Type\Atomic\TMixed
                    || $lhsTypePart instanceof Type\Atomic\TGenericParam
                ) {
                    continue;
                }

                if ($lhsTypePart instanceof Type\Atomic\TNull
                    && $lhsType->ignoreNullableIssues
                ) {
                    continue;
                }

                if (IssueBuffer::accepts(
                    new UndefinedClass(
                        'Type ' . $lhsTypePart . ' cannot be called as a class',
                        new CodeLocation($statementsChecker->getSource(), $stmt),
                        (string) $lhsTypePart
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    // fall through
                }

                continue;
            }

            $fqClassName = $lhsTypePart->value;

            $isMock = ExpressionChecker::isMock($fqClassName);

            $hasMock = $hasMock || $isMock;

            $methodId = null;

            if ($stmt->name instanceof PhpParser\Node\Identifier
                && !$codebase->methodExists($fqClassName . '::__callStatic')
                && !$isMock
            ) {
                $methodId = $fqClassName . '::' . strtolower($stmt->name->name);
                $casedMethodId = $fqClassName . '::' . $stmt->name->name;

                $sourceMethodId = $source instanceof FunctionLikeChecker
                    ? $source->getMethodId()
                    : null;

                $doesMethodExist = MethodChecker::checkMethodExists(
                    $projectChecker,
                    $casedMethodId,
                    new CodeLocation($source, $stmt),
                    $statementsChecker->getSuppressedIssues(),
                    $sourceMethodId
                );

                if (!$doesMethodExist) {
                    return $doesMethodExist;
                }

                $classStorage = $projectChecker->classlikeStorageProvider->get($fqClassName);

                if ($classStorage->deprecated) {
                    if (IssueBuffer::accepts(
                        new DeprecatedClass(
                            $fqClassName . ' is marked deprecated',
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }

                if (MethodChecker::checkMethodVisibility(
                    $methodId,
                    $context->self,
                    $statementsChecker->getSource(),
                    new CodeLocation($source, $stmt),
                    $statementsChecker->getSuppressedIssues()
                ) === false) {
                    return false;
                }

                if ($stmt->class instanceof PhpParser\Node\Name
                    && ($stmt->class->parts[0] !== 'parent' || $statementsChecker->isStatic())
                    && (
                        !$context->self
                        || $statementsChecker->isStatic()
                        || !$codebase->classExtends($context->self, $fqClassName)
                    )
                ) {
                    if (MethodChecker::checkStatic(
                        $methodId,
                        strtolower($stmt->class->parts[0]) === 'self' || $context->self === $fqClassName,
                        !$statementsChecker->isStatic(),
                        $projectChecker,
                        new CodeLocation($source, $stmt),
                        $statementsChecker->getSuppressedIssues(),
                        $isDynamicThisMethod
                    ) === false) {
                        // fall through
                    }

                    if ($isDynamicThisMethod) {
                        $fakeMethodCallExpr = new PhpParser\Node\Expr\MethodCall(
                            new PhpParser\Node\Expr\Variable(
                                'this',
                                $stmt->class->getAttributes()
                            ),
                            $stmt->name,
                            $stmt->args,
                            $stmt->getAttributes()
                        );

                        if (MethodCallChecker::analyze(
                            $statementsChecker,
                            $fakeMethodCallExpr,
                            $context
                        ) === false) {
                            return false;
                        }

                        if (isset($fakeMethodCallExpr->inferredType)) {
                            $stmt->inferredType = $fakeMethodCallExpr->inferredType;
                        }

                        return null;
                    }
                }

                if (MethodChecker::checkMethodNotDeprecated(
                    $projectChecker,
                    $methodId,
                    new CodeLocation($statementsChecker->getSource(), $stmt),
                    $statementsChecker->getSuppressedIssues()
                ) === false) {
                    // fall through
                }

                if (self::checkMethodArgs(
                    $methodId,
                    $stmt->args,
                    $foundGenericParams,
                    $context,
                    new CodeLocation($statementsChecker->getSource(), $stmt),
                    $statementsChecker
                ) === false) {
                    return false;
                }

                $fqClassName = $stmt->class instanceof PhpParser\Node\Name && $stmt->class->parts === ['parent']
                    ? (string) $statementsChecker->getFQCLN()
                    : $fqClassName;

                $selfFqClassName = $fqClassName;

                $returnTypeCandidate = $codebase->methods->getMethodReturnType(
                    $methodId,
                    $selfFqClassName,
                    $stmt->args
                );

                if ($returnTypeCandidate) {
                    $returnTypeCandidate = clone $returnTypeCandidate;

                    if ($foundGenericParams) {
                        $returnTypeCandidate->replaceTemplateTypesWithArgTypes(
                            $foundGenericParams
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
                }

                try {
                    $methodStorage = $codebase->methods->getUserMethodStorage($methodId);

                    if ($methodStorage->assertions) {
                        self::applyAssertionsToContext(
                            $methodStorage->assertions,
                            $stmt->args,
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
                } catch (\UnexpectedValueException $e) {
                    // do nothing for non-user-defined methods
                }

                if ($config->afterMethodChecks) {
                    $fileManipulations = [];

                    $appearingMethodId = $codebase->methods->getAppearingMethodId($methodId);
                    $declaringMethodId = $codebase->methods->getDeclaringMethodId($methodId);

                    $codeLocation = new CodeLocation($source, $stmt);

                    foreach ($config->afterMethodChecks as $pluginFqClassName) {
                        $pluginFqClassName::afterMethodCallCheck(
                            $statementsChecker,
                            $methodId,
                            $appearingMethodId,
                            $declaringMethodId,
                            null,
                            $stmt->args,
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
                    if (isset($stmt->inferredType)) {
                        $stmt->inferredType = Type::combineUnionTypes($stmt->inferredType, $returnTypeCandidate);
                    } else {
                        $stmt->inferredType = $returnTypeCandidate;
                    }
                }
            } else {
                if (self::checkFunctionArguments(
                    $statementsChecker,
                    $stmt->args,
                    null,
                    null,
                    $context
                ) === false) {
                    return false;
                }
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
    }
}
