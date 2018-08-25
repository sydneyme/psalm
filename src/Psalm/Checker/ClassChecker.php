<?php
namespace Psalm\Checker;

use PhpParser;
use Psalm\Aliases;
use Psalm\Checker\FunctionLike\ReturnTypeChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\Context;
use Psalm\Issue\DeprecatedClass;
use Psalm\Issue\DeprecatedInterface;
use Psalm\Issue\DeprecatedTrait;
use Psalm\Issue\InaccessibleMethod;
use Psalm\Issue\MissingConstructor;
use Psalm\Issue\MissingPropertyType;
use Psalm\Issue\OverriddenPropertyAccess;
use Psalm\Issue\PropertyNotSetInConstructor;
use Psalm\Issue\ReservedWord;
use Psalm\Issue\UndefinedTrait;
use Psalm\Issue\UnimplementedAbstractMethod;
use Psalm\Issue\UnimplementedInterfaceMethod;
use Psalm\IssueBuffer;
use Psalm\StatementsSource;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;

class ClassChecker extends ClassLikeChecker
{
    /**
     * @param PhpParser\Node\Stmt\Class_    $class
     * @param StatementsSource              $source
     * @param string|null                   $fqClassName
     */
    public function __construct(PhpParser\Node\Stmt\Class_ $class, StatementsSource $source, $fqClassName)
    {
        if (!$fqClassName) {
            $fqClassName = self::getAnonymousClassName($class, $source->getFilePath());
        }

        parent::__construct($class, $source, $fqClassName);

        if (!$this->class instanceof PhpParser\Node\Stmt\Class_) {
            throw new \InvalidArgumentException('Bad');
        }

        if ($this->class->extends) {
            $this->parentFqClassName = self::getFQCLNFromNameObject(
                $this->class->extends,
                $this->source->getAliases()
            );
        }
    }

    /**
     * @param  PhpParser\Node\Stmt\Class_ $class
     * @param  string                     $filePath
     *
     * @return string
     */
    public static function getAnonymousClassName(PhpParser\Node\Stmt\Class_ $class, $filePath)
    {
        return preg_replace('/[^A-Za-z0-9]/', '_', $filePath)
            . '_' . $class->getLine() . '_' . (int)$class->getAttribute('startFilePos');
    }

    /**
     * @param Context|null  $classContext
     * @param Context|null  $globalContext
     *
     * @return null|false
     */
    public function analyze(
        Context $classContext = null,
        Context $globalContext = null
    ) {
        $class = $this->class;

        if (!$class instanceof PhpParser\Node\Stmt\Class_) {
            throw new \LogicException('Something went badly wrong');
        }

        $fqClassName = $classContext && $classContext->self ? $classContext->self : $this->fqClassName;

        $storage = $this->storage;

        if ($class->name && preg_match(
            '/(^|\\\)(int|float|bool|string|void|null|false|true|resource|object|numeric|mixed)$/i',
            $fqClassName
        )) {
            $classNameParts = explode('\\', $fqClassName);
            $className = array_pop($classNameParts);

            if (IssueBuffer::accepts(
                new ReservedWord(
                    $className . ' is a reserved word',
                    new CodeLocation(
                        $this,
                        $class->name,
                        null,
                        true
                    ),
                    $className
                ),
                array_merge($storage->suppressedIssues, $this->source->getSuppressedIssues())
            )) {
                // fall through
            }

            return null;
        }

        $projectChecker = $this->fileChecker->projectChecker;
        $codebase = $projectChecker->codebase;

        $classlikeStorageProvider = $projectChecker->classlikeStorageProvider;

        $parentFqClassName = $this->parentFqClassName;

        if ($class->extends) {
            if (!$parentFqClassName) {
                throw new \UnexpectedValueException('Parent class should be filled in for ' . $fqClassName);
            }

            $parentReferenceLocation = new CodeLocation($this, $class->extends);

            if (self::checkFullyQualifiedClassLikeName(
                $this,
                $parentFqClassName,
                $parentReferenceLocation,
                array_merge($storage->suppressedIssues, $this->getSuppressedIssues()),
                false
            ) === false) {
                return false;
            }

            try {
                $parentClassStorage = $classlikeStorageProvider->get($parentFqClassName);

                if ($parentClassStorage->deprecated) {
                    $codeLocation = new CodeLocation(
                        $this,
                        $class->extends,
                        $classContext ? $classContext->includeLocation : null,
                        true
                    );

                    if (IssueBuffer::accepts(
                        new DeprecatedClass(
                            $parentFqClassName . ' is marked deprecated',
                            $codeLocation
                        ),
                        array_merge($storage->suppressedIssues, $this->getSuppressedIssues())
                    )) {
                        // fall through
                    }
                }
            } catch (\InvalidArgumentException $e) {
                // do nothing
            }
        }

        foreach ($class->implements as $interfaceName) {
            $fqInterfaceName = self::getFQCLNFromNameObject(
                $interfaceName,
                $this->source->getAliases()
            );

            $interfaceLocation = new CodeLocation($this, $interfaceName);

            if (self::checkFullyQualifiedClassLikeName(
                $this,
                $fqInterfaceName,
                $interfaceLocation,
                $this->getSuppressedIssues(),
                false
            ) === false) {
                return false;
            }
        }

        $classInterfaces = $storage->classImplements;

        if (!$class->isAbstract()) {
            foreach ($classInterfaces as $interfaceName) {
                try {
                    $interfaceStorage = $classlikeStorageProvider->get($interfaceName);
                } catch (\InvalidArgumentException $e) {
                    continue;
                }

                $storage->publicClassConstants += $interfaceStorage->publicClassConstants;

                $codeLocation = new CodeLocation(
                    $this,
                    $class->name ? $class->name : $class,
                    $classContext ? $classContext->includeLocation : null,
                    true
                );

                if ($interfaceStorage->deprecated) {
                    if (IssueBuffer::accepts(
                        new DeprecatedInterface(
                            $interfaceName . ' is marked deprecated',
                            $codeLocation
                        ),
                        array_merge($storage->suppressedIssues, $this->getSuppressedIssues())
                    )) {
                        // fall through
                    }
                }

                foreach ($interfaceStorage->methods as $methodName => $interfaceMethodStorage) {
                    if ($interfaceMethodStorage->visibility === self::VISIBILITY_PUBLIC) {
                        $implementerDeclaringMethodId = $codebase->methods->getDeclaringMethodId(
                            $this->fqClassName . '::' . $methodName
                        );

                        $implementerFqClassName = null;

                        if ($implementerDeclaringMethodId) {
                            list($implementerFqClassName) = explode('::', $implementerDeclaringMethodId);
                        }

                        $implementerClasslikeStorage = $implementerFqClassName
                            ? $classlikeStorageProvider->get($implementerFqClassName)
                            : null;

                        $implementerMethodStorage = $implementerDeclaringMethodId
                            ? $codebase->methods->getStorage($implementerDeclaringMethodId)
                            : null;

                        if (!$implementerMethodStorage) {
                            if (IssueBuffer::accepts(
                                new UnimplementedInterfaceMethod(
                                    'Method ' . $methodName . ' is not defined on class ' .
                                    $storage->name,
                                    $codeLocation
                                ),
                                array_merge($storage->suppressedIssues, $this->getSuppressedIssues())
                            )) {
                                return false;
                            }

                            return null;
                        }

                        if ($implementerMethodStorage->visibility !== self::VISIBILITY_PUBLIC) {
                            if (IssueBuffer::accepts(
                                new InaccessibleMethod(
                                    'Interface-defined method ' . $implementerMethodStorage->casedName
                                        . ' must be public in ' . $storage->name,
                                    $codeLocation
                                ),
                                array_merge($storage->suppressedIssues, $this->getSuppressedIssues())
                            )) {
                                return false;
                            }

                            return null;
                        }

                        MethodChecker::compareMethods(
                            $projectChecker,
                            $implementerClasslikeStorage ?: $storage,
                            $interfaceStorage,
                            $implementerMethodStorage,
                            $interfaceMethodStorage,
                            $codeLocation,
                            $implementerMethodStorage->suppressedIssues,
                            false
                        );
                    }
                }
            }
        }

        if (!$classContext) {
            $classContext = new Context($this->fqClassName);
            $classContext->collectReferences = $codebase->collectReferences;
            $classContext->parent = $parentFqClassName;
        }

        if ($this->leftoverStmts) {
            (new StatementsChecker($this))->analyze($this->leftoverStmts, $classContext);
        }

        if (!$storage->abstract) {
            foreach ($storage->declaringMethodIds as $declaringMethodId) {
                $methodStorage = $codebase->methods->getStorage($declaringMethodId);

                list($declaringClassName, $methodName) = explode('::', $declaringMethodId);

                if ($methodStorage->abstract) {
                    if (IssueBuffer::accepts(
                        new UnimplementedAbstractMethod(
                            'Method ' . $methodName . ' is not defined on class ' .
                            $this->fqClassName . ', defined abstract in ' . $declaringClassName,
                            new CodeLocation(
                                $this,
                                $class->name ? $class->name : $class,
                                $classContext->includeLocation,
                                true
                            )
                        ),
                        array_merge($storage->suppressedIssues, $this->getSuppressedIssues())
                    )) {
                        return false;
                    }
                }
            }
        }

        foreach ($storage->appearingPropertyIds as $propertyName => $appearingPropertyId) {
            $propertyClassName = $codebase->properties->getDeclaringClassForProperty($appearingPropertyId);
            $propertyClassStorage = $classlikeStorageProvider->get((string)$propertyClassName);

            $propertyStorage = $propertyClassStorage->properties[$propertyName];

            if (isset($storage->overriddenPropertyIds[$propertyName])) {
                foreach ($storage->overriddenPropertyIds[$propertyName] as $overriddenPropertyId) {
                    list($guideClassName) = explode('::$', $overriddenPropertyId);
                    $guideClassStorage = $classlikeStorageProvider->get($guideClassName);
                    $guidePropertyStorage = $guideClassStorage->properties[$propertyName];

                    if ($propertyStorage->visibility > $guidePropertyStorage->visibility
                        && $propertyStorage->location
                    ) {
                        if (IssueBuffer::accepts(
                            new OverriddenPropertyAccess(
                                'Property ' . $guideClassStorage->name . '::$' . $propertyName
                                    . ' has different access level than '
                                    . $storage->name . '::$' . $propertyName,
                                $propertyStorage->location
                            )
                        )) {
                            return false;
                        }

                        return null;
                    }
                }
            }

            if ($propertyStorage->type) {
                $propertyType = clone $propertyStorage->type;

                if (!$propertyType->isMixed() &&
                    !$propertyStorage->hasDefault &&
                    !$propertyType->isNullable()
                ) {
                    $propertyType->initialized = false;
                }
            } else {
                $propertyType = Type::getMixed();
            }

            $propertyTypeLocation = $propertyStorage->typeLocation;

            $fleshedOutType = !$propertyType->isMixed()
                ? ExpressionChecker::fleshOutType(
                    $projectChecker,
                    $propertyType,
                    $this->fqClassName,
                    $this->fqClassName
                )
                : $propertyType;

            if ($propertyTypeLocation && !$fleshedOutType->isMixed()) {
                $fleshedOutType->check(
                    $this,
                    $propertyTypeLocation,
                    $this->getSuppressedIssues(),
                    [],
                    false
                );
            }

            if ($propertyStorage->isStatic) {
                $propertyId = $this->fqClassName . '::$' . $propertyName;

                $classContext->varsInScope[$propertyId] = $fleshedOutType;
            } else {
                $classContext->varsInScope['$this->' . $propertyName] = $fleshedOutType;
            }
        }

        $constructorChecker = null;
        $constructorAppearingFqcln = $fqClassName;
        $memberStmts = [];

        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\ClassMethod) {
                $methodChecker = $this->analyzeClassMethod(
                    $stmt,
                    $storage,
                    $this,
                    $classContext,
                    $globalContext
                );

                if ($stmt->name->name === '__construct') {
                    $constructorChecker = $methodChecker;
                }
            } elseif ($stmt instanceof PhpParser\Node\Stmt\TraitUse) {
                if ($this->analyzeTraitUse(
                    $this->source->getAliases(),
                    $stmt,
                    $projectChecker,
                    $storage,
                    $classContext,
                    $globalContext,
                    $constructorChecker
                ) === false) {
                    return false;
                }
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    if ($prop->default) {
                        $memberStmts[] = $stmt;
                        break;
                    }
                }
            } elseif ($stmt instanceof PhpParser\Node\Stmt\ClassConst) {
                $memberStmts[] = $stmt;
            }
        }

        $statementsChecker = new StatementsChecker($this);
        $statementsChecker->analyze($memberStmts, $classContext, $globalContext, true);

        $config = Config::getInstance();

        if ($config->reportIssueInFile('PropertyNotSetInConstructor', $this->getFilePath())) {
            $uninitializedVariables = [];
            $uninitializedProperties = [];

            foreach ($storage->appearingPropertyIds as $propertyName => $appearingPropertyId) {
                $propertyClassName = $codebase->properties->getDeclaringClassForProperty($appearingPropertyId);
                $propertyClassStorage = $classlikeStorageProvider->get((string)$propertyClassName);

                $property = $propertyClassStorage->properties[$propertyName];

                $propertyIsInitialized = isset($propertyClassStorage->initializedProperties[$propertyName]);

                if ($property->hasDefault || $property->isStatic || !$property->type || $propertyIsInitialized) {
                    continue;
                }

                if ($property->type->isMixed() || $property->type->isNullable()) {
                    continue;
                }

                $uninitializedVariables[] = '$this->' . $propertyName;
                $uninitializedProperties[$propertyName] = $property;
            }

            if ($uninitializedProperties) {
                if (!$storage->abstract
                    && !$constructorChecker
                    && isset($storage->declaringMethodIds['__construct'])
                    && $class->extends
                ) {
                    list($constructorDeclaringFqcln) = explode('::', $storage->declaringMethodIds['__construct']);
                    list($constructorAppearingFqcln) = explode('::', $storage->appearingMethodIds['__construct']);

                    $constructorClassStorage = $classlikeStorageProvider->get($constructorDeclaringFqcln);

                    // ignore oldstyle constructors and classes without any declared properties
                    if ($constructorClassStorage->userDefined
                        && isset($constructorClassStorage->methods['__construct'])
                    ) {
                        $constructorStorage = $constructorClassStorage->methods['__construct'];

                        $fakeConstructorParams = array_map(
                            /** @return PhpParser\Node\Param */
                            function (FunctionLikeParameter $param) {
                                $fakeParam = (new PhpParser\Builder\Param($param->name));
                                if ($param->signatureType) {
                                    $fakeParam->setTypehint((string)$param->signatureType);
                                }

                                return $fakeParam->getNode();
                            },
                            $constructorStorage->params
                        );

                        $fakeConstructorStmtArgs = array_map(
                            /** @return PhpParser\Node\Arg */
                            function (FunctionLikeParameter $param) {
                                return new PhpParser\Node\Arg(new PhpParser\Node\Expr\Variable($param->name));
                            },
                            $constructorStorage->params
                        );

                        $fakeConstructorStmts = [
                            new PhpParser\Node\Stmt\Expression(
                                new PhpParser\Node\Expr\StaticCall(
                                    new PhpParser\Node\Name(['parent']),
                                    new PhpParser\Node\Identifier('__construct'),
                                    $fakeConstructorStmtArgs,
                                    [
                                        'line' => $class->extends->getLine(),
                                        'startFilePos' => $class->extends->getAttribute('startFilePos'),
                                        'endFilePos' => $class->extends->getAttribute('endFilePos'),
                                    ]
                                )
                            ),
                        ];

                        $fakeStmt = new PhpParser\Node\Stmt\ClassMethod(
                            new PhpParser\Node\Identifier('__construct'),
                            [
                                'type' => PhpParser\Node\Stmt\Class_::MODIFIER_PUBLIC,
                                'params' => $fakeConstructorParams,
                                'stmts' => $fakeConstructorStmts,
                            ]
                        );

                        $codebase->analyzer->disableMixedCounts();

                        $constructorChecker = $this->analyzeClassMethod(
                            $fakeStmt,
                            $storage,
                            $this,
                            $classContext,
                            $globalContext
                        );

                        $codebase->analyzer->enableMixedCounts();
                    }
                }

                if ($constructorChecker) {
                    $methodContext = clone $classContext;
                    $methodContext->collectInitializations = true;
                    $methodContext->self = $fqClassName;
                    $methodContext->varsInScope['$this'] = Type::parseString($fqClassName);
                    $methodContext->varsPossiblyInScope['$this'] = true;

                    $constructorChecker->analyze($methodContext, $globalContext, true);

                    foreach ($uninitializedProperties as $propertyName => $propertyStorage) {
                        if (!isset($methodContext->varsInScope['$this->' . $propertyName])) {
                            throw new \UnexpectedValueException('$this->' . $propertyName . ' should be in scope');
                        }

                        $endType = $methodContext->varsInScope['$this->' . $propertyName];

                        $propertyId = $constructorAppearingFqcln . '::$' . $propertyName;

                        $constructorClassPropertyStorage = $propertyStorage;

                        if ($fqClassName !== $constructorAppearingFqcln) {
                            $aClassStorage = $classlikeStorageProvider->get($constructorAppearingFqcln);

                            if (!isset($aClassStorage->declaringPropertyIds[$propertyName])) {
                                $constructorClassPropertyStorage = null;
                            } else {
                                $declaringPropertyClass = $aClassStorage->declaringPropertyIds[$propertyName];
                                $constructorClassPropertyStorage = $classlikeStorageProvider
                                    ->get($declaringPropertyClass)
                                    ->properties[$propertyName];
                            }
                        }

                        if ($propertyStorage->location
                            && (!$endType->initialized || $propertyStorage !== $constructorClassPropertyStorage)
                        ) {
                            if (!$config->reportIssueInFile(
                                'PropertyNotSetInConstructor',
                                $propertyStorage->location->filePath
                            ) && $class->extends
                            ) {
                                $errorLocation = new CodeLocation($this, $class->extends);
                            } else {
                                $errorLocation = $propertyStorage->location;
                            }

                            if (IssueBuffer::accepts(
                                new PropertyNotSetInConstructor(
                                    'Property ' . $propertyId . ' is not defined in constructor of ' .
                                        $this->fqClassName . ' or in any private methods called in the constructor',
                                    $errorLocation
                                ),
                                array_merge($this->source->getSuppressedIssues(), $storage->suppressedIssues)
                            )) {
                                continue;
                            }
                        }
                    }
                } elseif (!$storage->abstract) {
                    $firstUninitializedProperty = array_shift($uninitializedProperties);

                    if ($firstUninitializedProperty->location) {
                        if (IssueBuffer::accepts(
                            new MissingConstructor(
                                $fqClassName . ' has an uninitialized variable ' . $uninitializedVariables[0] .
                                    ', but no constructor',
                                $firstUninitializedProperty->location
                            ),
                            array_merge($storage->suppressedIssues, $this->getSuppressedIssues())
                        )) {
                            // fall through
                        }
                    }
                }
            }
        }

        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\Property) {
                $this->checkForMissingPropertyType($projectChecker, $this, $stmt);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\TraitUse) {
                foreach ($stmt->traits as $trait) {
                    $fqTraitName = self::getFQCLNFromNameObject(
                        $trait,
                        $this->source->getAliases()
                    );

                    $traitFileChecker = $projectChecker->getFileCheckerForClassLike($fqTraitName);
                    $traitNode = $codebase->classlikes->getTraitNode($fqTraitName);
                    $traitAliases = $codebase->classlikes->getTraitAliases($fqTraitName);
                    $traitChecker = new TraitChecker(
                        $traitNode,
                        $traitFileChecker,
                        $fqTraitName,
                        $traitAliases
                    );

                    foreach ($traitNode->stmts as $traitStmt) {
                        if ($traitStmt instanceof PhpParser\Node\Stmt\Property) {
                            $this->checkForMissingPropertyType($projectChecker, $traitChecker, $traitStmt);
                        }
                    }
                }
            }
        }
    }

    /**
     * @return false|null
     */
    private function analyzeTraitUse(
        Aliases $aliases,
        PhpParser\Node\Stmt\TraitUse $stmt,
        ProjectChecker $projectChecker,
        ClassLikeStorage $storage,
        Context $classContext,
        Context $globalContext = null,
        MethodChecker &$constructorChecker = null
    ) {
        $codebase = $projectChecker->codebase;

        $previousContextIncludeLocation = $classContext->includeLocation;

        foreach ($stmt->traits as $traitName) {
            $classContext->includeLocation = new CodeLocation($this, $traitName, null, true);

            $fqTraitName = self::getFQCLNFromNameObject(
                $traitName,
                $aliases
            );

            if (!$codebase->classlikes->hasFullyQualifiedTraitName($fqTraitName)) {
                if (IssueBuffer::accepts(
                    new UndefinedTrait(
                        'Trait ' . $fqTraitName . ' does not exist',
                        new CodeLocation($this, $traitName)
                    ),
                    array_merge($storage->suppressedIssues, $this->getSuppressedIssues())
                )) {
                    return false;
                }
            } else {
                if (!$codebase->traitHasCorrectCase($fqTraitName)) {
                    if (IssueBuffer::accepts(
                        new UndefinedTrait(
                            'Trait ' . $fqTraitName . ' has wrong casing',
                            new CodeLocation($this, $traitName)
                        ),
                        array_merge($storage->suppressedIssues, $this->getSuppressedIssues())
                    )) {
                        return false;
                    }

                    continue;
                }

                $traitStorage = $codebase->classlikeStorageProvider->get($fqTraitName);

                if ($traitStorage->deprecated) {
                    if (IssueBuffer::accepts(
                        new DeprecatedTrait(
                            'Trait ' . $fqTraitName . ' is deprecated',
                            new CodeLocation($this, $traitName)
                        ),
                        array_merge($storage->suppressedIssues, $this->getSuppressedIssues())
                    )) {
                        return false;
                    }
                }

                $traitFileChecker = $projectChecker->getFileCheckerForClassLike($fqTraitName);
                $traitNode = $codebase->classlikes->getTraitNode($fqTraitName);
                $traitAliases = $codebase->classlikes->getTraitAliases($fqTraitName);
                $traitChecker = new TraitChecker(
                    $traitNode,
                    $traitFileChecker,
                    $fqTraitName,
                    $traitAliases
                );

                foreach ($traitNode->stmts as $traitStmt) {
                    if ($traitStmt instanceof PhpParser\Node\Stmt\ClassMethod) {
                        if ($traitStmt->stmts) {
                            $traverser = new PhpParser\NodeTraverser;

                            $traverser->addVisitor(new \Psalm\Visitor\NodeCleanerVisitor());
                            $traverser->traverse($traitStmt->stmts);
                        }

                        $traitMethodChecker = $this->analyzeClassMethod(
                            $traitStmt,
                            $storage,
                            $traitChecker,
                            $classContext,
                            $globalContext
                        );

                        if ($traitStmt->name->name === '__construct') {
                            $constructorChecker = $traitMethodChecker;
                        }
                    } elseif ($traitStmt instanceof PhpParser\Node\Stmt\TraitUse) {
                        if ($this->analyzeTraitUse(
                            $traitAliases,
                            $traitStmt,
                            $projectChecker,
                            $storage,
                            $classContext,
                            $globalContext,
                            $constructorChecker
                        ) === false) {
                            return false;
                        }
                    }
                }
            }
        }

        $classContext->includeLocation = $previousContextIncludeLocation;
    }

    /**
     * @param   PhpParser\Node\Stmt\Property    $stmt
     *
     * @return  void
     */
    private function checkForMissingPropertyType(
        ProjectChecker $projectChecker,
        StatementsSource $source,
        PhpParser\Node\Stmt\Property $stmt
    ) {
        $comment = $stmt->getDocComment();

        if (!$comment || !$comment->getText()) {
            $fqClassName = $source->getFQCLN();
            $propertyName = $stmt->props[0]->name->name;

            $codebase = $projectChecker->codebase;

            $declaringPropertyClass = $codebase->properties->getDeclaringClassForProperty(
                $fqClassName . '::$' . $propertyName
            );

            if (!$declaringPropertyClass) {
                throw new \UnexpectedValueException(
                    'Cannot get declaring class for ' . $fqClassName . '::$' . $propertyName
                );
            }

            $fqClassName = $declaringPropertyClass;

            $message = 'Property ' . $fqClassName . '::$' . $propertyName . ' does not have a declared type';

            $classStorage = $projectChecker->classlikeStorageProvider->get($fqClassName);

            $propertyStorage = $classStorage->properties[$propertyName];

            if ($propertyStorage->suggestedType && !$propertyStorage->suggestedType->isNull()) {
                $message .= ' - consider ' . str_replace(
                    ['<mixed, mixed>', '<empty, empty>'],
                    '',
                    (string)$propertyStorage->suggestedType
                );
            }

            if (IssueBuffer::accepts(
                new MissingPropertyType(
                    $message,
                    new CodeLocation($source, $stmt)
                ),
                $this->source->getSuppressedIssues()
            )) {
                // fall through
            }
        }
    }

    /**
     * @param  PhpParser\Node\Stmt\ClassMethod $stmt
     * @param  StatementsSource                $source
     * @param  Context                         $classContext
     * @param  Context|null                    $globalContext
     *
     * @return MethodChecker|null
     */
    private function analyzeClassMethod(
        PhpParser\Node\Stmt\ClassMethod $stmt,
        ClassLikeStorage $classStorage,
        StatementsSource $source,
        Context $classContext,
        Context $globalContext = null
    ) {
        $config = Config::getInstance();

        $methodChecker = new MethodChecker($stmt, $source);

        $actualMethodId = (string)$methodChecker->getMethodId();

        $projectChecker = $source->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;

        $analyzedMethodId = $actualMethodId;

        if ($classContext->self && $classContext->self !== $source->getFQCLN()) {
            $analyzedMethodId = (string)$methodChecker->getMethodId($classContext->self);

            $declaringMethodId = $codebase->methods->getDeclaringMethodId($analyzedMethodId);

            if ($actualMethodId !== $declaringMethodId) {
                // the method is an abstract trait method

                $implementerMethodStorage = $methodChecker->getFunctionLikeStorage();

                if (!$implementerMethodStorage instanceof \Psalm\Storage\MethodStorage) {
                    throw new \LogicException('This should never happen');
                }

                if ($declaringMethodId && $implementerMethodStorage->abstract) {
                    $classlikeStorageProvider = $projectChecker->classlikeStorageProvider;
                    $appearingStorage = $classlikeStorageProvider->get($classContext->self);
                    $declaringMethodStorage = $codebase->methods->getStorage($declaringMethodId);

                    MethodChecker::compareMethods(
                        $projectChecker,
                        $classStorage,
                        $appearingStorage,
                        $implementerMethodStorage,
                        $declaringMethodStorage,
                        new CodeLocation($source, $stmt),
                        $implementerMethodStorage->suppressedIssues,
                        false
                    );
                }


                return;
            }
        }

        $methodContext = clone $classContext;
        $methodContext->collectExceptions = $config->checkForThrowsDocblock;

        $methodChecker->analyze(
            $methodContext,
            $globalContext ? clone $globalContext : null
        );

        if ($stmt->name->name !== '__construct'
            && $config->reportIssueInFile('InvalidReturnType', $source->getFilePath())
        ) {
            $returnTypeLocation = null;
            $secondaryReturnTypeLocation = null;

            $actualMethodStorage = $codebase->methods->getStorage($actualMethodId);

            if (!$actualMethodStorage->hasTemplateReturnType) {
                if ($actualMethodId) {
                    $returnTypeLocation = $codebase->methods->getMethodReturnTypeLocation(
                        $actualMethodId,
                        $secondaryReturnTypeLocation
                    );
                }

                $selfClass = $classContext->self;

                $returnType = $codebase->methods->getMethodReturnType($analyzedMethodId, $selfClass);

                $overriddenMethodIds = isset($classStorage->overriddenMethodIds[strtolower($stmt->name->name)])
                    ? $classStorage->overriddenMethodIds[strtolower($stmt->name->name)]
                    : [];

                if ($actualMethodStorage->overriddenDownstream) {
                    $overriddenMethodIds[] = 'overridden::downstream';
                }

                if (!$returnType && isset($classStorage->interfaceMethodIds[strtolower($stmt->name->name)])) {
                    $interfaceMethodIds = $classStorage->interfaceMethodIds[strtolower($stmt->name->name)];

                    foreach ($interfaceMethodIds as $interfaceMethodId) {
                        list($interfaceClass) = explode('::', $interfaceMethodId);

                        $interfaceReturnType = $codebase->methods->getMethodReturnType(
                            $interfaceMethodId,
                            $interfaceClass
                        );

                        $interfaceReturnTypeLocation = $codebase->methods->getMethodReturnTypeLocation(
                            $interfaceMethodId
                        );

                        ReturnTypeChecker::verifyReturnType(
                            $stmt,
                            $source,
                            $methodChecker,
                            $interfaceReturnType,
                            $interfaceClass,
                            $interfaceReturnTypeLocation,
                            [$analyzedMethodId]
                        );
                    }
                }

                ReturnTypeChecker::verifyReturnType(
                    $stmt,
                    $source,
                    $methodChecker,
                    $returnType,
                    $selfClass,
                    $returnTypeLocation,
                    $overriddenMethodIds
                );
            }
        }

        return $methodChecker;
    }
}
