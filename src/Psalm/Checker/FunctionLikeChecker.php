<?php
namespace Psalm\Checker;

use PhpParser;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use Psalm\Checker\FunctionLike\ReturnTypeChecker;
use Psalm\Checker\FunctionLike\ReturnTypeCollector;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Codebase\CallMap;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\FileManipulation\FunctionDocblockManipulator;
use Psalm\Issue\InvalidParamDefault;
use Psalm\Issue\MismatchingDocblockParamType;
use Psalm\Issue\MissingClosureParamType;
use Psalm\Issue\MissingParamType;
use Psalm\Issue\MissingThrowsDocblock;
use Psalm\Issue\ReservedWord;
use Psalm\Issue\UnusedParam;
use Psalm\IssueBuffer;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;

abstract class FunctionLikeChecker extends SourceChecker implements StatementsSource
{
    /**
     * @var Closure|Function_|ClassMethod
     */
    protected $function;

    /**
     * @var array<string>
     */
    protected $suppressedIssues;

    /**
     * @var bool
     */
    protected $isStatic = false;

    /**
     * @var StatementsSource
     */
    protected $source;

    /**
     * @var FileChecker
     */
    public $fileChecker;

    /**
     * @var array<string, array<string, Type\Union>>
     */
    protected $returnVarsInScope = [];

    /**
     * @var array<string, array<string, bool>>
     */
    protected $returnVarsPossiblyInScope = [];

    /**
     * @var Type\Union|null
     */
    private $localReturnType;

    /**
     * @var array<string, array>
     */
    protected static $noEffectsHashes = [];

    /**
     * @param Closure|Function_|ClassMethod $function
     * @param StatementsSource $source
     */
    public function __construct($function, StatementsSource $source)
    {
        $this->function = $function;
        $this->source = $source;
        $this->fileChecker = $source->getFileChecker();
        $this->suppressedIssues = $source->getSuppressedIssues();
    }

    /**
     * @param Context       $context
     * @param Context|null  $globalContext
     * @param bool          $addMutations  whether or not to add mutations to this method
     *
     * @return false|null
     */
    public function analyze(Context $context, Context $globalContext = null, $addMutations = false)
    {
        $functionStmts = $this->function->getStmts() ?: [];

        $hash = null;
        $realMethodId = null;

        $casedMethodId = null;

        $classStorage = null;

        if ($globalContext) {
            foreach ($globalContext->constants as $constName => $varType) {
                if (!$context->hasVariable($constName)) {
                    $context->varsInScope[$constName] = clone $varType;
                }
            }
        }

        $projectChecker = $this->fileChecker->projectChecker;

        $fileStorageProvider = $projectChecker->fileStorageProvider;

        $implementedDocblockParamTypes = [];

        $projectChecker = $this->fileChecker->projectChecker;
        $codebase = $projectChecker->codebase;

        $classlikeStorageProvider = $projectChecker->classlikeStorageProvider;

        if ($this->function instanceof ClassMethod) {
            $realMethodId = (string)$this->getMethodId();

            $methodId = (string)$this->getMethodId($context->self);

            if ($addMutations) {
                $hash = $realMethodId . json_encode([
                    $context->varsInScope,
                    $context->varsPossiblyInScope,
                ]);

                // if we know that the function has no effects on vars, we don't bother rechecking
                if (isset(self::$noEffectsHashes[$hash])) {
                    list(
                        $context->varsInScope,
                        $context->varsPossiblyInScope
                    ) = self::$noEffectsHashes[$hash];

                    return null;
                }
            } elseif ($context->self) {
                $context->varsInScope['$this'] = new Type\Union([new TNamedObject($context->self)]);
                $context->varsPossiblyInScope['$this'] = true;
            }

            $fqClassName = (string)$context->self;

            $classStorage = $classlikeStorageProvider->get($fqClassName);

            try {
                $storage = $codebase->methods->getStorage($realMethodId);
            } catch (\UnexpectedValueException $e) {
                if (!$classStorage->parentClasses) {
                    throw $e;
                }

                $declaringMethodId = $codebase->methods->getDeclaringMethodId($methodId);

                if (!$declaringMethodId) {
                    throw $e;
                }

                // happens for fake constructors
                $storage = $codebase->methods->getStorage($declaringMethodId);
            }

            $casedMethodId = $fqClassName . '::' . $storage->casedName;

            $overriddenMethodIds = $codebase->methods->getOverriddenMethodIds($methodId);

            if ($this->function->name->name === '__construct') {
                $context->insideConstructor = true;
            }

            $codeLocation = new CodeLocation(
                $this,
                $this->function,
                null,
                true
            );

            if ($overriddenMethodIds
                && $this->function->name->name !== '__construct'
                && !$context->collectInitializations
                && !$context->collectMutations
            ) {
                foreach ($overriddenMethodIds as $overriddenMethodId) {
                    $parentMethodStorage = $codebase->methods->getStorage($overriddenMethodId);

                    list($overriddenFqClassName) = explode('::', $overriddenMethodId);

                    $parentStorage = $classlikeStorageProvider->get($overriddenFqClassName);

                    MethodChecker::compareMethods(
                        $projectChecker,
                        $classStorage,
                        $parentStorage,
                        $storage,
                        $parentMethodStorage,
                        $codeLocation,
                        $storage->suppressedIssues
                    );

                    foreach ($parentMethodStorage->params as $i => $guideParam) {
                        if ($guideParam->type && (!$guideParam->signatureType || !$parentStorage->userDefined)) {
                            $implementedDocblockParamTypes[$i] = true;
                        }
                    }
                }
            }

            MethodChecker::checkMethodSignatureMustOmitReturnType($storage, $codeLocation);
        } elseif ($this->function instanceof Function_) {
            $fileStorage = $fileStorageProvider->get($this->source->getFilePath());

            $functionId = (string)$this->getMethodId();

            if (!isset($fileStorage->functions[$functionId])) {
                throw new \UnexpectedValueException(
                    'Function ' . $functionId . ' should be defined in ' . $this->source->getFilePath()
                );
            }

            $storage = $fileStorage->functions[$functionId];

            $casedMethodId = $this->function->name;
        } else { // Closure
            $functionId = $this->getMethodId();

            $storage = $codebase->getClosureStorage($this->source->getFilePath(), $functionId);

            if ($storage->returnType) {
                $closureReturnType = ExpressionChecker::fleshOutType(
                    $projectChecker,
                    $storage->returnType,
                    $context->self,
                    $context->self
                );
            } else {
                $closureReturnType = Type::getMixed();
            }

            /** @var PhpParser\Node\Expr\Closure $this->function */
            $this->function->inferredType = new Type\Union([
                new Type\Atomic\Fn(
                    'Closure',
                    $storage->params,
                    $closureReturnType
                ),
            ]);
        }

        $this->suppressedIssues = array_merge(
            $this->getSource()->getSuppressedIssues(),
            $storage->suppressedIssues
        );

        if ($storage instanceof MethodStorage && $storage->isStatic) {
            $this->isStatic = true;
        }

        $statementsChecker = new StatementsChecker($this);

        $templateTypes = $storage->templateTypes;

        if ($classStorage && $classStorage->templateTypes) {
            $templateTypes = array_merge($templateTypes ?: [], $classStorage->templateTypes);
        }

        foreach ($storage->params as $offset => $functionParam) {
            $signatureType = $functionParam->signatureType;

            if ($functionParam->type) {
                if ($functionParam->typeLocation) {
                    $functionParam->type->check(
                        $this,
                        $functionParam->typeLocation,
                        $storage->suppressedIssues,
                        [],
                        false
                    );
                }

                $paramType = clone $functionParam->type;

                $paramType = ExpressionChecker::fleshOutType(
                    $projectChecker,
                    $paramType,
                    $context->self,
                    $context->self
                );
            } else {
                $paramType = Type::getMixed();
            }

            $context->varsInScope['$' . $functionParam->name] = $paramType;
            $context->varsPossiblyInScope['$' . $functionParam->name] = true;

            if ($context->collectReferences && $functionParam->location) {
                $context->unreferencedVars['$' . $functionParam->name] = [
                    $functionParam->location->getHash() => $functionParam->location
                ];
            }

            if (!$functionParam->typeLocation || !$functionParam->location) {
                continue;
            }

            /**
             * @psalm-suppress MixedArrayAccess
             *
             * @var PhpParser\Node\Param
             */
            $parserParam = $this->function->getParams()[$offset];

            if ($signatureType) {
                if (!TypeChecker::isContainedBy(
                    $codebase,
                    $paramType,
                    $signatureType,
                    false,
                    false,
                    $hasScalarMatch,
                    $typeCoerced,
                    $typeCoercedFromMixed
                ) && !$typeCoercedFromMixed
                ) {
                    if ($projectChecker->alterCode
                        && isset($projectChecker->getIssuesToFix()['MismatchingDocblockParamType'])
                    ) {
                        $this->addOrUpdateParamType($projectChecker, $functionParam->name, $signatureType, true);

                        continue;
                    }

                    if (IssueBuffer::accepts(
                        new MismatchingDocblockParamType(
                            'Parameter $' . $functionParam->name . ' has wrong type \'' . $paramType .
                                '\', should be \'' . $signatureType . '\'',
                            $functionParam->typeLocation
                        ),
                        $storage->suppressedIssues
                    )) {
                        return false;
                    }

                    $signatureType->check(
                        $this,
                        $functionParam->typeLocation,
                        $storage->suppressedIssues,
                        [],
                        false
                    );

                    continue;
                }
            }

            if ($parserParam->default) {
                ExpressionChecker::analyze($statementsChecker, $parserParam->default, $context);

                $defaultType = isset($parserParam->default->inferredType)
                    ? $parserParam->default->inferredType
                    : null;

                if ($defaultType
                    && !$defaultType->isMixed()
                    && !TypeChecker::isContainedBy(
                        $codebase,
                        $defaultType,
                        $paramType
                    )
                ) {
                    if (IssueBuffer::accepts(
                        new InvalidParamDefault(
                            'Default value type ' . $defaultType . ' for argument ' . ($offset + 1)
                                . ' of method ' . $casedMethodId
                                . ' does not match the given type ' . $paramType,
                            $functionParam->typeLocation
                        )
                    )) {
                        // fall through
                    }
                }
            }

            if ($templateTypes) {
                $substitutedType = clone $paramType;
                $genericTypes = [];
                $substitutedType->replaceTemplateTypesWithStandins($templateTypes, $genericTypes);
                $substitutedType->check(
                    $this->source,
                    $functionParam->typeLocation,
                    $this->suppressedIssues,
                    [],
                    false
                );
            } else {
                if ($paramType->isVoid()) {
                    if (IssueBuffer::accepts(
                        new ReservedWord(
                            'Parameter cannot be void',
                            $functionParam->typeLocation,
                            'void'
                        ),
                        $this->suppressedIssues
                    )) {
                        // fall through
                    }
                }

                $paramType->check(
                    $this->source,
                    $functionParam->typeLocation,
                    $this->suppressedIssues,
                    [],
                    false
                );
            }

            if ($codebase->collectReferences) {
                if ($functionParam->typeLocation !== $functionParam->signatureTypeLocation &&
                    $functionParam->signatureTypeLocation &&
                    $functionParam->signatureType
                ) {
                    $functionParam->signatureType->check(
                        $this->source,
                        $functionParam->signatureTypeLocation,
                        $this->suppressedIssues,
                        [],
                        false
                    );
                }
            }

            if ($functionParam->byRef) {
                $context->byrefConstraints['$' . $functionParam->name]
                    = new \Psalm\ReferenceConstraint(!$paramType->isMixed() ? $paramType : null);
            }

            if ($functionParam->byRef) {
                // register by ref params as having been used, to avoid false positives
                // @todo change the assignment analysis *just* for byref params
                // so that we don't have to do this
                $context->hasVariable('$' . $functionParam->name);
            }

            $statementsChecker->registerVariable(
                '$' . $functionParam->name,
                $functionParam->location,
                null
            );
        }

        if (ReturnTypeChecker::checkSignatureReturnType(
            $this->function,
            $projectChecker,
            $this,
            $storage,
            $context
        ) === false) {
            return false;
        }

        $statementsChecker->analyze($functionStmts, $context, $globalContext, true);

        foreach ($storage->params as $offset => $functionParam) {
            // only complain if there's no type defined by a parent type
            if (!$functionParam->type
                && $functionParam->location
                && !isset($implementedDocblockParamTypes[$offset])
            ) {
                $possibleType = null;

                if (isset($context->possibleParamTypes[$functionParam->name])) {
                    $possibleType = $context->possibleParamTypes[$functionParam->name];
                }

                $inferText = $projectChecker->inferTypesFromUsage
                    ? ', ' . ($possibleType ? 'should be ' . $possibleType : 'could not infer type')
                    : '';

                if ($this->function instanceof Closure) {
                    IssueBuffer::accepts(
                        new MissingClosureParamType(
                            'Parameter $' . $functionParam->name . ' has no provided type' . $inferText,
                            $functionParam->location
                        ),
                        $storage->suppressedIssues
                    );
                } else {
                    IssueBuffer::accepts(
                        new MissingParamType(
                            'Parameter $' . $functionParam->name . ' has no provided type' . $inferText,
                            $functionParam->location
                        ),
                        $storage->suppressedIssues
                    );
                }
            }
        }

        if ($this->function instanceof Closure) {
            $this->verifyReturnType(
                $storage->returnType,
                $this->source->getFQCLN(),
                $storage->returnTypeLocation
            );

            $closureYieldTypes = [];

            $closureReturnTypes = ReturnTypeCollector::getReturnTypes(
                $this->function->stmts,
                $closureYieldTypes,
                $ignoreNullableIssues,
                $ignoreFalsableIssues,
                true
            );

            if ($closureReturnTypes) {
                $closureReturnType = new Type\Union($closureReturnTypes);

                if (!$storage->returnType
                    || $storage->returnType->isMixed()
                    || TypeChecker::isContainedBy(
                        $projectChecker->codebase,
                        $closureReturnType,
                        $storage->returnType
                    )
                ) {
                    if ($this->function->inferredType) {
                        /** @var Type\Atomic\Fn */
                        $closureAtomic = $this->function->inferredType->getTypes()['Closure'];
                        $closureAtomic->returnType = $closureReturnType;
                    }
                }
            }
        }

        if ($context->collectReferences
            && !$context->collectInitializations
            && $projectChecker->codebase->findUnusedCode
            && $context->checkVariables
        ) {
            foreach ($statementsChecker->getUnusedVarLocations() as list($varName, $originalLocation)) {
                if (!array_key_exists(substr($varName, 1), $storage->paramTypes)) {
                    continue;
                }

                if (strpos($varName, '$_') === 0 || (strpos($varName, '$unused') === 0 && $varName !== '$unused')) {
                    continue;
                }

                $position = array_search(substr($varName, 1), array_keys($storage->paramTypes), true);

                if ($position === false) {
                    throw new \UnexpectedValueException('$position should not be false here');
                }

                if ($storage->params[$position]->byRef) {
                    continue;
                }

                if (!($storage instanceof MethodStorage)
                    || $storage->visibility === ClassLikeChecker::VISIBILITY_PRIVATE
                ) {
                    if (IssueBuffer::accepts(
                        new UnusedParam(
                            'Param ' . $varName . ' is never referenced in this method',
                            $originalLocation
                        ),
                        $this->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                } else {
                    $fqClassName = (string)$context->self;

                    $classStorage = $codebase->classlikeStorageProvider->get($fqClassName);

                    $methodNameLc = strtolower($storage->casedName);

                    if ($storage->abstract || !isset($classStorage->overriddenMethodIds[$methodNameLc])) {
                        continue;
                    }

                    $parentMethodId = end($classStorage->overriddenMethodIds[$methodNameLc]);

                    if ($parentMethodId) {
                        $parentMethodStorage = $codebase->methods->getStorage($parentMethodId);

                        // if the parent method has a param at that position and isn't abstract
                        if (!$parentMethodStorage->abstract
                            && isset($parentMethodStorage->params[$position])
                        ) {
                            continue;
                        }
                    }

                    $storage->unusedParams[$position] = $originalLocation;
                }
            }

            if ($storage instanceof MethodStorage && $classStorage) {
                foreach ($storage->params as $i => $_) {
                    if (!isset($storage->unusedParams[$i])) {
                        $storage->usedParams[$i] = true;

                        /** @var ClassMethod $this->function */
                        $methodNameLc = strtolower($storage->casedName);

                        if (!isset($classStorage->overriddenMethodIds[$methodNameLc])) {
                            continue;
                        }

                        foreach ($classStorage->overriddenMethodIds[$methodNameLc] as $parentMethodId) {
                            $parentMethodStorage = $codebase->methods->getStorage($parentMethodId);

                            $parentMethodStorage->usedParams[$i] = true;
                        }
                    }
                }
            }
        }

        if ($context->collectExceptions) {
            if ($context->possiblyThrownExceptions) {
                $ignoredExceptions = array_change_key_case($codebase->config->ignoredExceptions);

                $undocumentedThrows = array_diff_key($context->possiblyThrownExceptions, $storage->throws);

                foreach ($undocumentedThrows as $possiblyThrownException => $_) {
                    if (isset($ignoredExceptions[strtolower($possiblyThrownException)])) {
                        continue;
                    }

                    if (IssueBuffer::accepts(
                        new MissingThrowsDocblock(
                            $possiblyThrownException . ' is thrown but not caught - please either catch'
                                . ' or add a @throws annotation',
                            new CodeLocation(
                                $this,
                                $this->function,
                                null,
                                true
                            )
                        )
                    )) {
                        // fall through
                    }
                }
            }
        }

        if ($addMutations) {
            if (isset($this->returnVarsInScope[''])) {
                $context->varsInScope = TypeChecker::combineKeyedTypes(
                    $context->varsInScope,
                    $this->returnVarsInScope['']
                );
            }

            if (isset($this->returnVarsPossiblyInScope[''])) {
                $context->varsPossiblyInScope = array_merge(
                    $context->varsPossiblyInScope,
                    $this->returnVarsPossiblyInScope['']
                );
            }

            foreach ($context->varsInScope as $var => $_) {
                if (strpos($var, '$this->') !== 0 && $var !== '$this') {
                    unset($context->varsInScope[$var]);
                }
            }

            foreach ($context->varsPossiblyInScope as $var => $_) {
                if (strpos($var, '$this->') !== 0 && $var !== '$this') {
                    unset($context->varsPossiblyInScope[$var]);
                }
            }

            if ($hash && $realMethodId && $this instanceof MethodChecker) {
                $newHash = $realMethodId . json_encode([
                    $context->varsInScope,
                    $context->varsPossiblyInScope,
                ]);

                if ($newHash === $hash) {
                    self::$noEffectsHashes[$hash] = [
                        $context->varsInScope,
                        $context->varsPossiblyInScope,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @param Type\Union|null     $returnType
     * @param string              $fqClassName
     * @param CodeLocation|null   $returnTypeLocation
     *
     * @return  false|null
     */
    public function verifyReturnType(
        Type\Union $returnType = null,
        $fqClassName = null,
        CodeLocation $returnTypeLocation = null
    ) {
        ReturnTypeChecker::verifyReturnType(
            $this->function,
            $this->source,
            $this,
            $returnType,
            $fqClassName,
            $returnTypeLocation
        );
    }

    /**
     * @param string $paramName
     * @param bool $docblockOnly
     *
     * @return void
     */
    private function addOrUpdateParamType(
        ProjectChecker $projectChecker,
        $paramName,
        Type\Union $inferredReturnType,
        $docblockOnly = false
    ) {
        $manipulator = FunctionDocblockManipulator::getForFunction(
            $projectChecker,
            $this->source->getFilePath(),
            $this->getMethodId(),
            $this->function
        );
        $manipulator->setParamType(
            $paramName,
            !$docblockOnly && $projectChecker->phpMajorVersion >= 7
                ? $inferredReturnType->toPhpString(
                    $this->source->getNamespace(),
                    $this->source->getAliasedClassesFlipped(),
                    $this->source->getFQCLN(),
                    $projectChecker->phpMajorVersion,
                    $projectChecker->phpMinorVersion
                ) : null,
            $inferredReturnType->toNamespacedString(
                $this->source->getNamespace(),
                $this->source->getAliasedClassesFlipped(),
                $this->source->getFQCLN(),
                false
            ),
            $inferredReturnType->toNamespacedString(
                $this->source->getNamespace(),
                $this->source->getAliasedClassesFlipped(),
                $this->source->getFQCLN(),
                true
            ),
            $inferredReturnType->canBeFullyExpressedInPhp()
        );
    }

    /**
     * Adds return types for the given function
     *
     * @param   string  $returnType
     * @param   Context $context
     *
     * @return  void
     */
    public function addReturnTypes($returnType, Context $context)
    {
        if (isset($this->returnVarsInScope[$returnType])) {
            $this->returnVarsInScope[$returnType] = TypeChecker::combineKeyedTypes(
                $context->varsInScope,
                $this->returnVarsInScope[$returnType]
            );
        } else {
            $this->returnVarsInScope[$returnType] = $context->varsInScope;
        }

        if (isset($this->returnVarsPossiblyInScope[$returnType])) {
            $this->returnVarsPossiblyInScope[$returnType] = array_merge(
                $context->varsPossiblyInScope,
                $this->returnVarsPossiblyInScope[$returnType]
            );
        } else {
            $this->returnVarsPossiblyInScope[$returnType] = $context->varsPossiblyInScope;
        }
    }

    /**
     * @return null|string
     */
    public function getMethodName()
    {
        if ($this->function instanceof ClassMethod) {
            return (string)$this->function->name;
        }
    }

    /**
     * @param string|null $contextSelf
     *
     * @return string
     */
    public function getMethodId($contextSelf = null)
    {
        if ($this->function instanceof ClassMethod) {
            $functionName = (string)$this->function->name;

            return ($contextSelf ?: $this->source->getFQCLN()) . '::' . strtolower($functionName);
        }

        if ($this->function instanceof Function_) {
            $namespace = $this->source->getNamespace();

            return ($namespace ? strtolower($namespace) . '\\' : '') . strtolower($this->function->name->name);
        }

        return $this->getFilePath()
            . ':' . $this->function->getLine()
            . ':' . (int)$this->function->getAttribute('startFilePos')
            . ':-:closure';
    }

    /**
     * @param string|null $contextSelf
     *
     * @return string
     */
    public function getCorrectlyCasedMethodId($contextSelf = null)
    {
        if ($this->function instanceof ClassMethod) {
            $functionName = (string)$this->function->name;

            return ($contextSelf ?: $this->source->getFQCLN()) . '::' . $functionName;
        }

        if ($this->function instanceof Function_) {
            $namespace = $this->source->getNamespace();

            return ($namespace ? $namespace . '\\' : '') . $this->function->name;
        }

        return $this->getMethodId();
    }

    /**
     * @return FunctionLikeStorage
     */
    public function getFunctionLikeStorage(StatementsChecker $statementsChecker = null)
    {
        $projectChecker = $this->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;

        if ($this->function instanceof ClassMethod) {
            $methodId = (string) $this->getMethodId();
            $codebaseMethods = $codebase->methods;

            try {
                return $codebaseMethods->getStorage($methodId);
            } catch (\UnexpectedValueException $e) {
                $declaringMethodId = $codebaseMethods->getDeclaringMethodId($methodId);

                if (!$declaringMethodId) {
                    throw new \UnexpectedValueException('Cannot get storage for function that doesn‘t exist');
                }

                // happens for fake constructors
                return $codebaseMethods->getStorage($declaringMethodId);
            }
        }

        return $codebase->functions->getStorage($statementsChecker, (string) $this->getMethodId());
    }

    /**
     * @return array<string, string>
     */
    public function getAliasedClassesFlipped()
    {
        if ($this->source instanceof NamespaceChecker ||
            $this->source instanceof FileChecker ||
            $this->source instanceof ClassLikeChecker
        ) {
            return $this->source->getAliasedClassesFlipped();
        }

        return [];
    }

    /**
     * @return string|null
     */
    public function getFQCLN()
    {
        return $this->source->getFQCLN();
    }

    /**
     * @return null|string
     */
    public function getClassName()
    {
        return $this->source->getClassName();
    }

    /**
     * @return string|null
     */
    public function getParentFQCLN()
    {
        return $this->source->getParentFQCLN();
    }

    /**
     * @return bool
     */
    public function isStatic()
    {
        return $this->isStatic;
    }

    /**
     * @return StatementsSource
     */
    public function getSource()
    {
        return $this->source;
    }

    /**
     * @param  string                           $methodId
     * @param  array<int, PhpParser\Node\Arg>   $args
     *
     * @return array<int, FunctionLikeParameter>
     */
    public static function getMethodParamsById(ProjectChecker $projectChecker, $methodId, array $args)
    {
        $fqClassName = strpos($methodId, '::') !== false ? explode('::', $methodId)[0] : null;

        $codebase = $projectChecker->codebase;

        if ($fqClassName) {
            $classStorage = $projectChecker->codebase->classlikeStorageProvider->get($fqClassName);

            if ($classStorage->userDefined || $classStorage->stubbed) {
                $methodParams = $codebase->methods->getMethodParams($methodId);

                return $methodParams;
            }
        }

        $declaringMethodId = $codebase->methods->getDeclaringMethodId($methodId);

        if (CallMap::inCallMap($declaringMethodId ?: $methodId)) {
            $functionParamOptions = CallMap::getParamsFromCallMap($declaringMethodId ?: $methodId);

            if ($functionParamOptions === null) {
                throw new \UnexpectedValueException(
                    'Not expecting $functionParamOptions to be null for ' . $methodId
                );
            }

            return self::getMatchingParamsFromCallMapOptions($projectChecker, $functionParamOptions, $args);
        }

        return $codebase->methods->getMethodParams($methodId);
    }

    /**
     * @param  string                           $methodId
     * @param  array<int, PhpParser\Node\Arg>   $args
     *
     * @return array<int, FunctionLikeParameter>
     */
    public static function getFunctionParamsFromCallMapById(ProjectChecker $projectChecker, $methodId, array $args)
    {
        $functionParamOptions = CallMap::getParamsFromCallMap($methodId);

        if ($functionParamOptions === null) {
            throw new \UnexpectedValueException(
                'Not expecting $functionParamOptions to be null for ' . $methodId
            );
        }

        return self::getMatchingParamsFromCallMapOptions($projectChecker, $functionParamOptions, $args);
    }

    /**
     * @param  array<int, array<int, FunctionLikeParameter>>  $functionParamOptions
     * @param  array<int, PhpParser\Node\Arg>                 $args
     *
     * @return array<int, FunctionLikeParameter>
     */
    protected static function getMatchingParamsFromCallMapOptions(
        ProjectChecker $projectChecker,
        array $functionParamOptions,
        array $args
    ) {
        if (count($functionParamOptions) === 1) {
            return $functionParamOptions[0];
        }

        foreach ($functionParamOptions as $possibleFunctionParams) {
            $allArgsMatch = true;

            $lastParam = count($possibleFunctionParams)
                ? $possibleFunctionParams[count($possibleFunctionParams) - 1]
                : null;

            $mandatoryParamCount = count($possibleFunctionParams);

            foreach ($possibleFunctionParams as $i => $possibleFunctionParam) {
                if ($possibleFunctionParam->isOptional) {
                    $mandatoryParamCount = $i;
                    break;
                }
            }

            if ($mandatoryParamCount > count($args)) {
                continue;
            }

            foreach ($args as $argumentOffset => $arg) {
                if ($argumentOffset >= count($possibleFunctionParams)) {
                    if (!$lastParam || !$lastParam->isVariadic) {
                        $allArgsMatch = false;
                    }

                    break;
                }

                $paramType = $possibleFunctionParams[$argumentOffset]->type;

                if (!$paramType) {
                    continue;
                }

                if (!isset($arg->value->inferredType)) {
                    continue;
                }

                if ($arg->value->inferredType->isMixed()) {
                    continue;
                }

                if (TypeChecker::isContainedBy(
                    $projectChecker->codebase,
                    $arg->value->inferredType,
                    $paramType,
                    true,
                    true
                )) {
                    continue;
                }

                $allArgsMatch = false;
                break;
            }

            if ($allArgsMatch) {
                return $possibleFunctionParams;
            }
        }

        // if we don't succeed in finding a match, set to the first possible and wait for issues below
        return $functionParamOptions[0];
    }

    /**
     * Get a list of suppressed issues
     *
     * @return array<string>
     */
    public function getSuppressedIssues()
    {
        return $this->suppressedIssues;
    }

    /**
     * @param array<int, string> $newIssues
     *
     * @return void
     */
    public function addSuppressedIssues(array $newIssues)
    {
        $this->suppressedIssues = array_merge($newIssues, $this->suppressedIssues);
    }

    /**
     * @param array<int, string> $newIssues
     *
     * @return void
     */
    public function removeSuppressedIssues(array $newIssues)
    {
        $this->suppressedIssues = array_diff($this->suppressedIssues, $newIssues);
    }

    /**
     * Adds a suppressed issue, useful when creating a method checker from scratch
     *
     * @param string $issueName
     *
     * @return void
     */
    public function addSuppressedIssue($issueName)
    {
        $this->suppressedIssues[] = $issueName;
    }

    /**
     * @return void
     */
    public static function clearCache()
    {
        self::$noEffectsHashes = [];
    }

    /**
     * @return FileChecker
     */
    public function getFileChecker()
    {
        return $this->fileChecker;
    }

    /**
     * @return Type\Union
     */
    public function getLocalReturnType(Type\Union $storageReturnType)
    {
        if ($this->localReturnType) {
            return $this->localReturnType;
        }

        $this->localReturnType = ExpressionChecker::fleshOutType(
            $this->fileChecker->projectChecker,
            $storageReturnType,
            $this->getFQCLN(),
            $this->getFQCLN()
        );

        return $this->localReturnType;
    }
}
