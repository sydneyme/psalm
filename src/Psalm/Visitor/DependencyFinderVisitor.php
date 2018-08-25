<?php
namespace Psalm\Visitor;

use PhpParser;
use Psalm\Aliases;
use Psalm\Checker\ClassChecker;
use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\CommentChecker;
use Psalm\Checker\Statements\Expression\CallChecker;
use Psalm\Checker\Statements\Expression\IncludeChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Codebase;
use Psalm\Codebase\CallMap;
use Psalm\Codebase\PropertyMap;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\Exception\DocblockParseException;
use Psalm\Exception\FileIncludeException;
use Psalm\Exception\IncorrectDocblockException;
use Psalm\Exception\TypeParseTreeException;
use Psalm\FileSource;
use Psalm\Issue\DuplicateClass;
use Psalm\Issue\DuplicateParam;
use Psalm\Issue\InvalidDocblock;
use Psalm\Issue\MisplacedRequiredParam;
use Psalm\Issue\MissingDocblockType;
use Psalm\IssueBuffer;
use Psalm\Scanner\FileScanner;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FileStorage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Storage\PropertyStorage;
use Psalm\Type;

class DependencyFinderVisitor extends PhpParser\NodeVisitorAbstract implements PhpParser\NodeVisitor, FileSource
{
    /** @var Aliases */
    private $aliases;

    /** @var Aliases */
    private $fileAliases;

    /**
     * @var string[]
     */
    private $fqClasslikeNames = [];

    /** @var FileScanner */
    private $fileScanner;

    /** @var Codebase */
    private $codebase;

    /** @var string */
    private $filePath;

    /** @var bool */
    private $scanDeep;

    /** @var Config */
    private $config;

    /** @var bool */
    private $queueStringsAsPossibleType = false;

    /** @var array<string, string> */
    private $classTemplateTypes = [];

    /** @var array<string, string> */
    private $functionTemplateTypes = [];

    /** @var FunctionLikeStorage[] */
    private $functionlikeStorages = [];

    /** @var FileStorage */
    private $fileStorage;

    /** @var ClassLikeStorage[] */
    private $classlikeStorages = [];

    /** @var string[] */
    private $afterClasslikeCheckPlugins;

    /**
     * @var array<string, array<int, string>>
     */
    private $typeAliases = [];

    public function __construct(
        Codebase $codebase,
        FileStorage $fileStorage,
        FileScanner $fileScanner
    ) {
        $this->codebase = $codebase;
        $this->fileScanner = $fileScanner;
        $this->filePath = $fileScanner->filePath;
        $this->scanDeep = $fileScanner->willAnalyze;
        $this->config = $codebase->config;
        $this->aliases = $this->fileAliases = new Aliases();
        $this->fileStorage = $fileStorage;
        $this->afterClasslikeCheckPlugins = $this->config->afterVisitClasslikes;
    }

    /**
     * @param  PhpParser\Node $node
     *
     * @return null|int
     */
    public function enterNode(PhpParser\Node $node)
    {
        foreach ($node->getComments() as $comment) {
            if ($comment instanceof PhpParser\Comment\Doc) {
                try {
                    $typeAliasTokens = CommentChecker::getTypeAliasesFromComment(
                        (string) $comment,
                        $this->aliases,
                        $this->typeAliases
                    );

                    foreach ($typeAliasTokens as $typeTokens) {
                        // finds issues, if there are any
                        Type::parseTokens($typeTokens);
                    }

                    $this->typeAliases += $typeAliasTokens;
                } catch (DocblockParseException $e) {
                    if (IssueBuffer::accepts(
                        new InvalidDocblock(
                            (string)$e->getMessage(),
                            new CodeLocation($this->fileScanner, $node, null, true)
                        )
                    )) {
                        // fall through
                    }
                } catch (TypeParseTreeException $e) {
                    if (IssueBuffer::accepts(
                        new InvalidDocblock(
                            (string)$e->getMessage(),
                            new CodeLocation($this->fileScanner, $node, null, true)
                        )
                    )) {
                        // fall through
                    }
                }
            }
        }

        if ($node instanceof PhpParser\Node\Stmt\Namespace_) {
            $this->fileAliases = $this->aliases;
            $this->aliases = new Aliases(
                $node->name ? implode('\\', $node->name->parts) : '',
                $this->aliases->uses,
                $this->aliases->functions,
                $this->aliases->constants
            );
        } elseif ($node instanceof PhpParser\Node\Stmt\Use_) {
            foreach ($node->uses as $use) {
                $usePath = implode('\\', $use->name->parts);

                $useAlias = $use->alias ? $use->alias->name : $use->name->getLast();

                switch ($use->type !== PhpParser\Node\Stmt\Use_::TYPE_UNKNOWN ? $use->type : $node->type) {
                    case PhpParser\Node\Stmt\Use_::TYPE_FUNCTION:
                        $this->aliases->functions[strtolower($useAlias)] = $usePath;
                        break;

                    case PhpParser\Node\Stmt\Use_::TYPE_CONSTANT:
                        $this->aliases->constants[$useAlias] = $usePath;
                        break;

                    case PhpParser\Node\Stmt\Use_::TYPE_NORMAL:
                        $this->aliases->uses[strtolower($useAlias)] = $usePath;
                        break;
                }
            }
        } elseif ($node instanceof PhpParser\Node\Stmt\GroupUse) {
            $usePrefix = implode('\\', $node->prefix->parts);

            foreach ($node->uses as $use) {
                $usePath = $usePrefix . '\\' . implode('\\', $use->name->parts);
                $useAlias = $use->alias ? $use->alias->name : $use->name->getLast();

                switch ($use->type !== PhpParser\Node\Stmt\Use_::TYPE_UNKNOWN ? $use->type : $node->type) {
                    case PhpParser\Node\Stmt\Use_::TYPE_FUNCTION:
                        $this->aliases->functions[strtolower($useAlias)] = $usePath;
                        break;

                    case PhpParser\Node\Stmt\Use_::TYPE_CONSTANT:
                        $this->aliases->constants[$useAlias] = $usePath;
                        break;

                    case PhpParser\Node\Stmt\Use_::TYPE_NORMAL:
                        $this->aliases->uses[strtolower($useAlias)] = $usePath;
                        break;
                }
            }
        } elseif ($node instanceof PhpParser\Node\Stmt\ClassLike) {
            if ($this->registerClassLike($node) === false) {
                return PhpParser\NodeTraverser::STOP_TRAVERSAL;
            }
        } elseif (($node instanceof PhpParser\Node\Expr\New_
                || $node instanceof PhpParser\Node\Expr\Instanceof_
                || $node instanceof PhpParser\Node\Expr\StaticPropertyFetch
                || $node instanceof PhpParser\Node\Expr\ClassConstFetch
                || $node instanceof PhpParser\Node\Expr\StaticCall)
            && $node->class instanceof PhpParser\Node\Name
        ) {
            $fqClasslikeName = ClassLikeChecker::getFQCLNFromNameObject($node->class, $this->aliases);

            if (!in_array(strtolower($fqClasslikeName), ['self', 'static', 'parent'], true)) {
                $this->codebase->scanner->queueClassLikeForScanning(
                    $fqClasslikeName,
                    $this->filePath,
                    false,
                    !($node instanceof PhpParser\Node\Expr\ClassConstFetch)
                        || !($node->name instanceof PhpParser\Node\Identifier)
                        || strtolower($node->name->name) !== 'class'
                );
                $this->fileStorage->referencedClasslikes[strtolower($fqClasslikeName)] = $fqClasslikeName;
            }
        } elseif ($node instanceof PhpParser\Node\Stmt\TryCatch) {
            foreach ($node->catches as $catch) {
                foreach ($catch->types as $catchType) {
                    $catchFqcln = ClassLikeChecker::getFQCLNFromNameObject($catchType, $this->aliases);

                    if (!in_array(strtolower($catchFqcln), ['self', 'static', 'parent'], true)) {
                        $this->codebase->scanner->queueClassLikeForScanning($catchFqcln, $this->filePath);
                        $this->fileStorage->referencedClasslikes[strtolower($catchFqcln)] = $catchFqcln;
                    }
                }
            }
        } elseif ($node instanceof PhpParser\Node\FunctionLike) {
            $this->registerFunctionLike($node);

            if (!$this->scanDeep) {
                return PhpParser\NodeTraverser::DONT_TRAVERSE_CHILDREN;
            }
        } elseif ($node instanceof PhpParser\Node\Stmt\Global_) {
            $functionLikeStorage = end($this->functionlikeStorages);

            if ($functionLikeStorage) {
                foreach ($node->vars as $var) {
                    if ($var instanceof PhpParser\Node\Expr\Variable) {
                        if (is_string($var->name) && $var->name !== 'argv' && $var->name !== 'argc') {
                            $varId = '$' . $var->name;

                            $functionLikeStorage->globalVariables[$varId] = true;
                        }
                    }
                }
            }
        } elseif ($node instanceof PhpParser\Node\Expr\FuncCall && $node->name instanceof PhpParser\Node\Name) {
            $functionId = implode('\\', $node->name->parts);
            if (CallMap::inCallMap($functionId)) {
                $functionParams = CallMap::getParamsFromCallMap($functionId);

                if ($functionParams) {
                    foreach ($functionParams as $functionParamGroup) {
                        foreach ($functionParamGroup as $functionParam) {
                            if ($functionParam->type) {
                                $functionParam->type->queueClassLikesForScanning(
                                    $this->codebase,
                                    $this->fileStorage
                                );
                            }
                        }
                    }
                }

                $returnType = CallMap::getReturnTypeFromCallMap($functionId);

                $returnType->queueClassLikesForScanning($this->codebase, $this->fileStorage);

                if ($functionId === 'get_class') {
                    $this->queueStringsAsPossibleType = true;
                }

                if ($functionId === 'define') {
                    $firstArgValue = isset($node->args[0]) ? $node->args[0]->value : null;
                    $secondArgValue = isset($node->args[1]) ? $node->args[1]->value : null;
                    if ($firstArgValue instanceof PhpParser\Node\Scalar\String_ && $secondArgValue) {
                        $constType = StatementsChecker::getSimpleType(
                            $this->codebase,
                            $secondArgValue,
                            $this->aliases
                        ) ?: Type::getMixed();
                        $constName = $firstArgValue->value;

                        if ($this->functionlikeStorages && !$this->config->hoistConstants) {
                            $functionlikeStorage =
                                $this->functionlikeStorages[count($this->functionlikeStorages) - 1];
                            $functionlikeStorage->definedConstants[$constName] = $constType;
                        } else {
                            $this->fileStorage->constants[$constName] = $constType;
                            $this->fileStorage->declaringConstants[$constName] = $this->filePath;
                        }
                    }
                }

                $mappingFunctionIds = [];

                if (($functionId === 'array_map' && isset($node->args[0]))
                    || ($functionId === 'array_filter' && isset($node->args[1]))
                ) {
                    $nodeArgValue = $functionId = 'array_map' ? $node->args[0]->value : $node->args[1]->value;

                    if ($nodeArgValue instanceof PhpParser\Node\Scalar\String_
                        || $nodeArgValue instanceof PhpParser\Node\Expr\Array_
                    ) {
                        $mappingFunctionIds = CallChecker::getFunctionIdsFromCallableArg(
                            $this->fileScanner,
                            $nodeArgValue
                        );
                    }

                    foreach ($mappingFunctionIds as $potentialMethodId) {
                        if (strpos($potentialMethodId, '::') === false) {
                            continue;
                        }

                        list($callableFqcln) = explode('::', $potentialMethodId);

                        if (!in_array(strtolower($callableFqcln), ['self', 'parent', 'static'], true)) {
                            $this->codebase->scanner->queueClassLikeForScanning(
                                $callableFqcln,
                                $this->filePath
                            );
                        }
                    }
                }

                if ($functionId === 'func_get_arg'
                    || $functionId === 'func_get_args'
                    || $functionId === 'func_num_args'
                ) {
                    $functionLikeStorage = end($this->functionlikeStorages);

                    if ($functionLikeStorage) {
                        $functionLikeStorage->variadic = true;
                    }
                }
            }
        } elseif ($node instanceof PhpParser\Node\Stmt\TraitUse) {
            if (!$this->classlikeStorages) {
                throw new \LogicException('$this->classlikeStorages should not be empty');
            }

            $storage = $this->classlikeStorages[count($this->classlikeStorages) - 1];

            $methodMap = $storage->traitAliasMap ?: [];

            foreach ($node->adaptations as $adaptation) {
                if ($adaptation instanceof PhpParser\Node\Stmt\TraitUseAdaptation\Alias) {
                    if ($adaptation->newName) {
                        $methodMap[strtolower($adaptation->method->name)] = strtolower($adaptation->newName->name);
                    }
                }
            }

            $storage->traitAliasMap = $methodMap;

            foreach ($node->traits as $trait) {
                $traitFqcln = ClassLikeChecker::getFQCLNFromNameObject($trait, $this->aliases);
                $this->codebase->scanner->queueClassLikeForScanning($traitFqcln, $this->filePath, $this->scanDeep);
                $storage->usedTraits[strtolower($traitFqcln)] = $traitFqcln;
                $this->fileStorage->requiredClasses[strtolower($traitFqcln)] = $traitFqcln;
            }
        } elseif ($node instanceof PhpParser\Node\Expr\Include_) {
            $this->visitInclude($node);
        } elseif ($node instanceof PhpParser\Node\Scalar\String_ && $this->queueStringsAsPossibleType) {
            if (preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $node->value)) {
                $this->codebase->scanner->queueClassLikeForScanning($node->value, $this->filePath, false, false);
            }
        } elseif ($node instanceof PhpParser\Node\Expr\Assign
            || $node instanceof PhpParser\Node\Expr\AssignOp
            || $node instanceof PhpParser\Node\Expr\AssignRef
        ) {
            if ($docComment = $node->getDocComment()) {
                $varComments = [];

                try {
                    $varComments = CommentChecker::getTypeFromComment(
                        (string)$docComment,
                        $this->fileScanner,
                        $this->aliases,
                        null,
                        null,
                        null,
                        $this->typeAliases
                    );
                } catch (DocblockParseException $e) {
                    // do nothing
                }

                foreach ($varComments as $varComment) {
                    $varType = $varComment->type;
                    $varType->queueClassLikesForScanning($this->codebase, $this->fileStorage);
                }
            }
        } elseif ($node instanceof PhpParser\Node\Stmt\Const_) {
            foreach ($node->consts as $const) {
                $constType = StatementsChecker::getSimpleType($this->codebase, $const->value, $this->aliases)
                    ?: Type::getMixed();

                $fqConstName = Type::getFQCLNFromString($const->name->name, $this->aliases);

                if ($this->codebase->registerStubFiles || $this->codebase->registerAutoloadFiles) {
                    $this->codebase->addGlobalConstantType($fqConstName, $constType);
                }

                $this->fileStorage->constants[$fqConstName] = $constType;
                $this->fileStorage->declaringConstants[$fqConstName] = $this->filePath;
            }
        } elseif ($this->codebase->registerAutoloadFiles && $node instanceof PhpParser\Node\Stmt\If_) {
            if ($node->cond instanceof PhpParser\Node\Expr\BooleanNot) {
                if ($node->cond->expr instanceof PhpParser\Node\Expr\FuncCall
                    && $node->cond->expr->name instanceof PhpParser\Node\Name
                ) {
                    if ($node->cond->expr->name->parts === ['function_exists']
                        && isset($node->cond->expr->args[0])
                        && $node->cond->expr->args[0]->value instanceof PhpParser\Node\Scalar\String_
                        && function_exists($node->cond->expr->args[0]->value->value)
                    ) {
                        return PhpParser\NodeTraverser::DONT_TRAVERSE_CHILDREN;
                    }

                    if ($node->cond->expr->name->parts === ['class_exists']
                        && isset($node->cond->expr->args[0])
                        && $node->cond->expr->args[0]->value instanceof PhpParser\Node\Scalar\String_
                        && class_exists($node->cond->expr->args[0]->value->value, false)
                    ) {
                        $reflectionClass = new \ReflectionClass($node->cond->expr->args[0]->value->value);

                        if ($reflectionClass->getFileName() !== $this->filePath) {
                            $this->codebase->scanner->queueClassLikeForScanning(
                                $node->cond->expr->args[0]->value->value,
                                $this->filePath
                            );

                            return PhpParser\NodeTraverser::DONT_TRAVERSE_CHILDREN;
                        }
                    }
                }
            }
        } elseif ($node instanceof PhpParser\Node\Expr\Yield_) {
            $functionLikeStorage = end($this->functionlikeStorages);

            if ($functionLikeStorage) {
                $functionLikeStorage->hasYield = true;
            }
        }
    }

    /**
     * @return null
     */
    public function leaveNode(PhpParser\Node $node)
    {
        if ($node instanceof PhpParser\Node\Stmt\Namespace_) {
            $this->aliases = $this->fileAliases;
        } elseif ($node instanceof PhpParser\Node\Stmt\ClassLike) {
            if (!$this->fqClasslikeNames) {
                throw new \LogicException('$this->fqClasslikeNames should not be empty');
            }

            $fqClasslikeName = array_pop($this->fqClasslikeNames);

            if (PropertyMap::inPropertyMap($fqClasslikeName)) {
                $publicMappedProperties = PropertyMap::getPropertyMap()[strtolower($fqClasslikeName)];

                if (!$this->classlikeStorages) {
                    throw new \UnexpectedValueException('$this->classlikeStorages cannot be empty');
                }

                $storage = $this->classlikeStorages[count($this->classlikeStorages) - 1];

                foreach ($publicMappedProperties as $propertyName => $publicMappedProperty) {
                    $propertyType = Type::parseString($publicMappedProperty);

                    $propertyType->queueClassLikesForScanning($this->codebase, $this->fileStorage);

                    if (!isset($storage->properties[$propertyName])) {
                        $storage->properties[$propertyName] = new PropertyStorage();
                    }

                    $storage->properties[$propertyName]->type = $propertyType;
                    $storage->properties[$propertyName]->visibility = ClassLikeChecker::VISIBILITY_PUBLIC;

                    $propertyId = $fqClasslikeName . '::$' . $propertyName;

                    $storage->declaringPropertyIds[$propertyName] = $fqClasslikeName;
                    $storage->appearingPropertyIds[$propertyName] = $propertyId;
                }
            }

            $classlikeStorage = array_pop($this->classlikeStorages);

            if ($classlikeStorage->hasVisitorIssues) {
                $this->fileStorage->hasVisitorIssues = true;
            }

            $this->classTemplateTypes = [];

            if ($this->afterClasslikeCheckPlugins) {
                $fileManipulations = [];

                foreach ($this->afterClasslikeCheckPlugins as $pluginFqClassName) {
                    $pluginFqClassName::afterVisitClassLike(
                        $node,
                        $classlikeStorage,
                        $this->fileScanner,
                        $this->aliases,
                        $fileManipulations
                    );
                }
            }

            if (!$this->fileStorage->hasVisitorIssues) {
                $this->codebase->cacheClassLikeStorage($classlikeStorage, $this->filePath);
            }
        } elseif ($node instanceof PhpParser\Node\Stmt\Function_
            || $node instanceof PhpParser\Node\Stmt\ClassMethod
        ) {
            $this->queueStringsAsPossibleType = false;

            $this->functionTemplateTypes = [];
        } elseif ($node instanceof PhpParser\Node\FunctionLike) {
            $functionlikeStorage = array_pop($this->functionlikeStorages);

            if ($functionlikeStorage->hasVisitorIssues) {
                $this->fileStorage->hasVisitorIssues = true;
            }
        }

        return null;
    }

    /**
     * @return false|null
     */
    private function registerClassLike(PhpParser\Node\Stmt\ClassLike $node)
    {
        $classLocation = new CodeLocation($this->fileScanner, $node, null, true);

        $storage = null;

        if ($node->name === null) {
            if (!$node instanceof PhpParser\Node\Stmt\Class_) {
                throw new \LogicException('Anonymous classes are always classes');
            }

            $fqClasslikeName = ClassChecker::getAnonymousClassName($node, $this->filePath);
        } else {
            $fqClasslikeName =
                ($this->aliases->namespace ? $this->aliases->namespace . '\\' : '') . $node->name->name;

            if ($this->codebase->classlikeStorageProvider->has($fqClasslikeName)) {
                $duplicateStorage = $this->codebase->classlikeStorageProvider->get($fqClasslikeName);

                if (!$this->codebase->registerStubFiles) {
                    if (!$duplicateStorage->location
                        || $duplicateStorage->location->filePath !== $this->filePath
                        || $classLocation->getHash() !== $duplicateStorage->location->getHash()
                    ) {
                        if (IssueBuffer::accepts(
                            new DuplicateClass(
                                'Class ' . $fqClasslikeName . ' has already been defined'
                                    . ($duplicateStorage->location
                                        ? ' in ' . $duplicateStorage->location->filePath
                                        : ''),
                                new CodeLocation($this->fileScanner, $node, null, true)
                            )
                        )) {
                            $this->fileStorage->hasVisitorIssues = true;
                        }

                        return false;
                    }
                } elseif (!$duplicateStorage->location
                    || $duplicateStorage->location->filePath !== $this->filePath
                    || $classLocation->getHash() !== $duplicateStorage->location->getHash()
                ) {
                    // we're overwriting some methods
                    $storage = $duplicateStorage;
                }
            }
        }

        $fqClasslikeNameLc = strtolower($fqClasslikeName);

        $this->fileStorage->classlikesInFile[$fqClasslikeNameLc] = $fqClasslikeName;

        $this->fqClasslikeNames[] = $fqClasslikeName;

        if (!$storage) {
            $storage = $this->codebase->createClassLikeStorage($fqClasslikeName);
        }

        $storage->location = $classLocation;
        $storage->userDefined = !$this->codebase->registerStubFiles;
        $storage->stubbed = $this->codebase->registerStubFiles;

        $docComment = $node->getDocComment();

        $this->classlikeStorages[] = $storage;

        if ($docComment) {
            $docblockInfo = null;
            try {
                $docblockInfo = CommentChecker::extractClassLikeDocblockInfo(
                    (string)$docComment,
                    $docComment->getLine()
                );
            } catch (DocblockParseException $e) {
                if (IssueBuffer::accepts(
                    new InvalidDocblock(
                        $e->getMessage() . ' in docblock for ' . implode('.', $this->fqClasslikeNames),
                        new CodeLocation($this->fileScanner, $node, null, true)
                    )
                )) {
                    $storage->hasVisitorIssues = true;
                }
            }

            if ($docblockInfo) {
                if ($docblockInfo->templateTypeNames) {
                    $storage->templateTypes = [];

                    foreach ($docblockInfo->templateTypeNames as $templateType) {
                        if (count($templateType) === 3) {
                            $storage->templateTypes[$templateType[0]] = Type::parseTokens(
                                Type::fixUpLocalType(
                                    $templateType[2],
                                    $this->aliases,
                                    null,
                                    $this->typeAliases
                                )
                            );
                        } else {
                            $storage->templateTypes[$templateType[0]] = Type::getMixed();
                        }
                    }

                    $this->classTemplateTypes = $storage->templateTypes;

                    if ($docblockInfo->templateParents) {
                        $storage->templateParents = [];

                        foreach ($docblockInfo->templateParents as $templateParent) {
                            $storage->templateParents[$templateParent] = $templateParent;
                        }
                    }
                }

                if ($docblockInfo->properties) {
                    foreach ($docblockInfo->properties as $property) {
                        $pseudoPropertyTypeTokens = Type::fixUpLocalType(
                            $property['type'],
                            $this->aliases,
                            null,
                            $this->typeAliases
                        );

                        $pseudoPropertyType = Type::parseTokens($pseudoPropertyTypeTokens);
                        $pseudoPropertyType->setFromDocblock();

                        if ($property['tag'] !== 'property-read') {
                            $storage->pseudoPropertySetTypes[$property['name']] = $pseudoPropertyType;
                        }

                        if ($property['tag'] !== 'property-write') {
                            $storage->pseudoPropertyGetTypes[$property['name']] = $pseudoPropertyType;
                        }
                    }
                }

                $storage->deprecated = $docblockInfo->deprecated;

                $storage->sealedProperties = $docblockInfo->sealedProperties;
                $storage->sealedMethods = $docblockInfo->sealedMethods;

                $storage->suppressedIssues = $docblockInfo->suppressedIssues;

                foreach ($docblockInfo->methods as $method) {
                    $storage->pseudoMethods[strtolower($method->name->name)]
                        = $this->registerFunctionLike($method, true);
                }
            }
        }

        if ($node instanceof PhpParser\Node\Stmt\Class_) {
            $storage->abstract = (bool)$node->isAbstract();
            $storage->final = (bool)$node->isFinal();

            $this->codebase->classlikes->addFullyQualifiedClassName($fqClasslikeName, $this->filePath);

            if ($node->extends) {
                $parentFqcln = ClassLikeChecker::getFQCLNFromNameObject($node->extends, $this->aliases);
                $this->codebase->scanner->queueClassLikeForScanning(
                    $parentFqcln,
                    $this->filePath,
                    $this->scanDeep
                );
                $parentFqclnLc = strtolower($parentFqcln);
                $storage->parentClasses[$parentFqclnLc] = $parentFqclnLc;
                $this->fileStorage->requiredClasses[strtolower($parentFqcln)] = $parentFqcln;
            }

            foreach ($node->implements as $interface) {
                $interfaceFqcln = ClassLikeChecker::getFQCLNFromNameObject($interface, $this->aliases);
                $this->codebase->scanner->queueClassLikeForScanning($interfaceFqcln, $this->filePath);
                $storage->classImplements[strtolower($interfaceFqcln)] = $interfaceFqcln;
                $this->fileStorage->requiredInterfaces[strtolower($interfaceFqcln)] = $interfaceFqcln;
            }
        } elseif ($node instanceof PhpParser\Node\Stmt\Interface_) {
            $storage->isInterface = true;
            $this->codebase->classlikes->addFullyQualifiedInterfaceName($fqClasslikeName, $this->filePath);

            foreach ($node->extends as $interface) {
                $interfaceFqcln = ClassLikeChecker::getFQCLNFromNameObject($interface, $this->aliases);
                $this->codebase->scanner->queueClassLikeForScanning($interfaceFqcln, $this->filePath);
                $storage->parentInterfaces[strtolower($interfaceFqcln)] = $interfaceFqcln;
                $this->fileStorage->requiredInterfaces[strtolower($interfaceFqcln)] = $interfaceFqcln;
            }
        } elseif ($node instanceof PhpParser\Node\Stmt\Trait_) {
            $storage->isTrait = true;
            $this->fileStorage->hasTrait = true;
            $this->codebase->classlikes->addFullyQualifiedTraitName($fqClasslikeName, $this->filePath);
            $this->codebase->classlikes->addTraitNode(
                $fqClasslikeName,
                $node,
                $this->aliases
            );
        }

        foreach ($node->stmts as $nodeStmt) {
            if ($nodeStmt instanceof PhpParser\Node\Stmt\ClassConst) {
                $this->visitClassConstDeclaration($nodeStmt, $storage, $fqClasslikeName);
            }
        }

        foreach ($node->stmts as $nodeStmt) {
            if ($nodeStmt instanceof PhpParser\Node\Stmt\Property) {
                $this->visitPropertyDeclaration($nodeStmt, $this->config, $storage, $fqClasslikeName);
            }
        }
    }

    /**
     * @param  PhpParser\Node\FunctionLike $stmt
     * @param  bool $fakeMethod in the case of @method annotations we do something a little strange
     *
     * @return FunctionLikeStorage
     */
    private function registerFunctionLike(PhpParser\Node\FunctionLike $stmt, $fakeMethod = false)
    {
        $classStorage = null;

        if ($fakeMethod && $stmt instanceof PhpParser\Node\Stmt\ClassMethod) {
            $casedFunctionId = '@method ' . $stmt->name->name;

            $storage = new FunctionLikeStorage();
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Function_) {
            $casedFunctionId =
                ($this->aliases->namespace ? $this->aliases->namespace . '\\' : '') . $stmt->name->name;
            $functionId = strtolower($casedFunctionId);

            if (isset($this->fileStorage->functions[$functionId])) {
                if ($this->codebase->registerStubFiles || $this->codebase->registerAutoloadFiles) {
                    $this->codebase->functions->addGlobalFunction(
                        $functionId,
                        $this->fileStorage->functions[$functionId]
                    );
                }

                return $this->fileStorage->functions[$functionId];
            }

            $storage = new FunctionLikeStorage();

            if ($this->codebase->registerStubFiles || $this->codebase->registerAutoloadFiles) {
                $this->codebase->functions->addGlobalFunction($functionId, $storage);
            }

            $this->fileStorage->functions[$functionId] = $storage;
            $this->fileStorage->declaringFunctionIds[$functionId] = strtolower($this->filePath);
        } elseif ($stmt instanceof PhpParser\Node\Stmt\ClassMethod) {
            if (!$this->fqClasslikeNames) {
                throw new \LogicException('$this->fqClasslikeNames should not be null');
            }

            $fqClasslikeName = $this->fqClasslikeNames[count($this->fqClasslikeNames) - 1];

            $functionId = $fqClasslikeName . '::' . strtolower($stmt->name->name);
            $casedFunctionId = $fqClasslikeName . '::' . $stmt->name->name;

            if (!$this->classlikeStorages) {
                throw new \UnexpectedValueException('$classStorages cannot be empty for ' . $functionId);
            }

            $classStorage = $this->classlikeStorages[count($this->classlikeStorages) - 1];

            $storage = null;

            if (isset($classStorage->methods[strtolower($stmt->name->name)])) {
                if (!$this->codebase->registerStubFiles) {
                    throw new \InvalidArgumentException('Cannot re-register ' . $functionId);
                }

                $storage = $classStorage->methods[strtolower($stmt->name->name)];
            }

            if (!$storage) {
                $storage = $classStorage->methods[strtolower($stmt->name->name)] = new MethodStorage();
            }

            $classNameParts = explode('\\', $fqClasslikeName);
            $className = array_pop($classNameParts);

            if (strtolower($stmt->name->name) === strtolower($className) &&
                !isset($classStorage->methods['__construct']) &&
                strpos($fqClasslikeName, '\\') === false
            ) {
                $this->codebase->methods->setDeclaringMethodId(
                    $fqClasslikeName . '::__construct',
                    $functionId
                );
                $this->codebase->methods->setAppearingMethodId(
                    $fqClasslikeName . '::__construct',
                    $functionId
                );
            }

            $classStorage->declaringMethodIds[strtolower($stmt->name->name)] = $functionId;
            $classStorage->appearingMethodIds[strtolower($stmt->name->name)] = $functionId;

            if (!$stmt->isPrivate() || $stmt->name->name === '__construct' || $classStorage->isTrait) {
                $classStorage->inheritableMethodIds[strtolower($stmt->name->name)] = $functionId;
            }

            if (!isset($classStorage->overriddenMethodIds[strtolower($stmt->name->name)])) {
                $classStorage->overriddenMethodIds[strtolower($stmt->name->name)] = [];
            }

            $storage->isStatic = (bool) $stmt->isStatic();
            $storage->abstract = (bool) $stmt->isAbstract();

            $storage->final = $classStorage->final || $stmt->isFinal();

            if ($stmt->isPrivate()) {
                $storage->visibility = ClassLikeChecker::VISIBILITY_PRIVATE;
            } elseif ($stmt->isProtected()) {
                $storage->visibility = ClassLikeChecker::VISIBILITY_PROTECTED;
            } else {
                $storage->visibility = ClassLikeChecker::VISIBILITY_PUBLIC;
            }
        } else {
            $functionId = $casedFunctionId = $this->filePath
                . ':' . $stmt->getLine()
                . ':' . (int) $stmt->getAttribute('startFilePos') . ':-:closure';

            $storage = $this->fileStorage->functions[$functionId] = new FunctionLikeStorage();
        }

        $this->functionlikeStorages[] = $storage;

        if ($stmt instanceof PhpParser\Node\Stmt\ClassMethod) {
            $storage->casedName = $stmt->name->name;
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Function_) {
            $storage->casedName =
                ($this->aliases->namespace ? $this->aliases->namespace . '\\' : '') . $stmt->name->name;
        }

        $storage->location = new CodeLocation($this->fileScanner, $stmt, null, true);

        $requiredParamCount = 0;
        $i = 0;
        $hasOptionalParam = false;

        $existingParams = [];
        $storage->params = [];

        /** @var PhpParser\Node\Param $param */
        foreach ($stmt->getParams() as $param) {
            if ($param->var instanceof PhpParser\Node\Expr\Error) {
                if (IssueBuffer::accepts(
                    new InvalidDocblock(
                        'Param' . ((int) $i + 1) . ' of ' . $casedFunctionId . ' has invalid syntax',
                        new CodeLocation($this->fileScanner, $param, null, true)
                    )
                )) {
                    $storage->hasVisitorIssues = true;
                }

                ++$i;

                continue;
            }

            $paramArray = $this->getTranslatedFunctionParam($param);

            if (isset($existingParams['$' . $paramArray->name])) {
                if (IssueBuffer::accepts(
                    new DuplicateParam(
                        'Duplicate param $' . $paramArray->name . ' in docblock for ' . $casedFunctionId,
                        new CodeLocation($this->fileScanner, $param, null, true)
                    )
                )) {
                    $storage->hasVisitorIssues = true;

                    ++$i;

                    continue;
                }
            }

            $existingParams['$' . $paramArray->name] = $i;
            $storage->paramTypes[$paramArray->name] = $paramArray->type;
            $storage->params[] = $paramArray;

            if (!$paramArray->isOptional) {
                $requiredParamCount = $i + 1;

                if (!$param->variadic
                    && $hasOptionalParam
                    && is_string($param->var->name)
                ) {
                    if (IssueBuffer::accepts(
                        new MisplacedRequiredParam(
                            'Required param $' . $param->var->name . ' should come before any optional params in ' .
                            $casedFunctionId,
                            new CodeLocation($this->fileScanner, $param, null, true)
                        )
                    )) {
                        $storage->hasVisitorIssues = true;
                    }
                }
            } else {
                $hasOptionalParam = true;
            }

            ++$i;
        }

        $storage->requiredParamCount = $requiredParamCount;

        if (($stmt instanceof PhpParser\Node\Stmt\Function_
                || $stmt instanceof PhpParser\Node\Stmt\ClassMethod)
            && strpos($stmt->name->name, 'assert') === 0
            && $stmt->stmts
        ) {
            $varAssertions = [];

            foreach ($stmt->stmts as $functionStmt) {
                if ($functionStmt instanceof PhpParser\Node\Stmt\If_) {
                    $finalActions = \Psalm\Checker\ScopeChecker::getFinalControlActions(
                        $functionStmt->stmts,
                        $this->config->exitFunctions,
                        false,
                        false
                    );

                    if ($finalActions !== [\Psalm\Checker\ScopeChecker::ACTION_END]) {
                        continue;
                    }

                    $ifClauses = \Psalm\Type\Algebra::getFormula(
                        $functionStmt->cond,
                        $this->fqClasslikeNames
                            ? $this->fqClasslikeNames[count($this->fqClasslikeNames) - 1]
                            : null,
                        $this->fileScanner
                    );

                    $negatedFormula = \Psalm\Type\Algebra::negateFormula($ifClauses);

                    $rules = \Psalm\Type\Algebra::getTruthsFromFormula($negatedFormula);

                    foreach ($rules as $varId => $rule) {
                        foreach ($rule as $rulePart) {
                            if (count($rulePart) > 1) {
                                continue 2;
                            }
                        }

                        if (isset($existingParams[$varId])) {
                            $paramOffset = $existingParams[$varId];

                            $varAssertions[] = new \Psalm\Storage\Assertion(
                                $paramOffset,
                                $rule
                            );
                        } elseif (strpos($varId, '$this->') === 0) {
                            $varAssertions[] = new \Psalm\Storage\Assertion(
                                $varId,
                                $rule
                            );
                        }
                    }
                }
            }

            $storage->assertions = $varAssertions;
        }

        if (!$this->scanDeep
            && ($stmt instanceof PhpParser\Node\Stmt\Function_
                || $stmt instanceof PhpParser\Node\Stmt\ClassMethod
                || $stmt instanceof PhpParser\Node\Expr\Closure)
            && $stmt->stmts
        ) {
            // pick up func_get_args that would otherwise be missed
            foreach ($stmt->stmts as $functionStmt) {
                if ($functionStmt instanceof PhpParser\Node\Stmt\Expression
                    && $functionStmt->expr instanceof PhpParser\Node\Expr\Assign
                    && ($functionStmt->expr->expr instanceof PhpParser\Node\Expr\FuncCall)
                    && ($functionStmt->expr->expr->name instanceof PhpParser\Node\Name)
                ) {
                    $functionId = implode('\\', $functionStmt->expr->expr->name->parts);

                    if ($functionId === 'func_get_arg'
                        || $functionId === 'func_get_args'
                        || $functionId === 'func_num_args'
                    ) {
                        $storage->variadic = true;
                    }
                }
            }
        }

        $parserReturnType = $stmt->getReturnType();

        if ($parserReturnType) {
            $suffix = '';

            if ($parserReturnType instanceof PhpParser\Node\NullableType) {
                $suffix = '|null';
                $parserReturnType = $parserReturnType->type;
            }

            if ($parserReturnType instanceof PhpParser\Node\Identifier) {
                $returnTypeString = $parserReturnType->name . $suffix;
            } else {
                $returnTypeFqClasslikeName = ClassLikeChecker::getFQCLNFromNameObject(
                    $parserReturnType,
                    $this->aliases
                );

                if (!in_array(strtolower($returnTypeFqClasslikeName), ['self', 'parent'], true)) {
                    $this->codebase->scanner->queueClassLikeForScanning(
                        $returnTypeFqClasslikeName,
                        $this->filePath
                    );
                }

                $returnTypeString = $returnTypeFqClasslikeName . $suffix;
            }

            $storage->returnType = Type::parseString($returnTypeString, true);
            $storage->returnTypeLocation = new CodeLocation(
                $this->fileScanner,
                $stmt,
                null,
                false,
                CodeLocation::FUNCTION_RETURN_TYPE
            );

            if ($stmt->returnsByRef()) {
                $storage->returnType->byRef = true;
            }

            $storage->signatureReturnType = $storage->returnType;
            $storage->signatureReturnTypeLocation = $storage->returnTypeLocation;
        }

        if ($stmt->returnsByRef()) {
            $storage->returnsByRef = true;
        }

        $docComment = $stmt->getDocComment();

        if (!$docComment) {
            return $storage;
        }

        try {
            $docblockInfo = CommentChecker::extractFunctionDocblockInfo((string)$docComment, $docComment->getLine());
        } catch (IncorrectDocblockException $e) {
            if (IssueBuffer::accepts(
                new MissingDocblockType(
                    $e->getMessage() . ' in docblock for ' . $casedFunctionId,
                    new CodeLocation($this->fileScanner, $stmt, null, true)
                )
            )) {
                $storage->hasVisitorIssues = true;
            }

            $docblockInfo = null;
        } catch (DocblockParseException $e) {
            if (IssueBuffer::accepts(
                new InvalidDocblock(
                    $e->getMessage() . ' in docblock for ' . $casedFunctionId,
                    new CodeLocation($this->fileScanner, $stmt, null, true)
                )
            )) {
                $storage->hasVisitorIssues = true;
            }

            $docblockInfo = null;
        }

        if (!$docblockInfo) {
            return $storage;
        }

        if ($docblockInfo->deprecated) {
            $storage->deprecated = true;
        }

        if ($docblockInfo->variadic) {
            $storage->variadic = true;
        }

        if ($docblockInfo->ignoreNullableReturn && $storage->returnType) {
            $storage->returnType->ignoreNullableIssues = true;
        }

        if ($docblockInfo->ignoreFalsableReturn && $storage->returnType) {
            $storage->returnType->ignoreFalsableIssues = true;
        }

        $storage->suppressedIssues = $docblockInfo->suppress;

        if ($this->config->checkForThrowsDocblock) {
            foreach ($docblockInfo->throws as $throwClass) {
                $exceptionFqcln = Type::getFQCLNFromString(
                    $throwClass,
                    $this->aliases
                );

                $storage->throws[$exceptionFqcln] = true;
            }
        }

        if (!$this->config->useDocblockTypes) {
            return $storage;
        }

        $templateTypes = $classStorage && $classStorage->templateTypes ? $classStorage->templateTypes : null;

        if ($docblockInfo->templateTypeNames) {
            $storage->templateTypes = [];

            foreach ($docblockInfo->templateTypeNames as $templateType) {
                if (count($templateType) === 3) {
                    $storage->templateTypes[$templateType[0]] = Type::parseTokens(
                        Type::fixUpLocalType(
                            $templateType[2],
                            $this->aliases,
                            null,
                            $this->typeAliases
                        )
                    );
                } else {
                    $storage->templateTypes[$templateType[0]] = Type::getMixed();
                }
            }

            $templateTypes = array_merge($templateTypes ?: [], $storage->templateTypes);

            $this->functionTemplateTypes = $templateTypes;
        }

        if ($docblockInfo->templateTypeofs) {
            $storage->templateTypeofParams = [];

            foreach ($docblockInfo->templateTypeofs as $templateTypeof) {
                foreach ($storage->params as $i => $param) {
                    if ($param->name === $templateTypeof['param_name']) {
                        $storage->templateTypeofParams[$i] = $templateTypeof['template_type'];
                        break;
                    }
                }
            }
        }

        if ($docblockInfo->assertions) {
            $storage->assertions = [];

            foreach ($docblockInfo->assertions as $assertion) {
                foreach ($storage->params as $i => $param) {
                    if ($param->name === $assertion['param_name']) {
                        $storage->assertions[] = new \Psalm\Storage\Assertion(
                            $i,
                            [[$assertion['type']]]
                        );
                        continue 2;
                    }
                }

                $storage->assertions[] = new \Psalm\Storage\Assertion(
                    $assertion['param_name'],
                    [[$assertion['type']]]
                );
            }
        }

        if ($docblockInfo->ifTrueAssertions) {
            $storage->assertions = [];

            foreach ($docblockInfo->ifTrueAssertions as $assertion) {
                foreach ($storage->params as $i => $param) {
                    if ($param->name === $assertion['param_name']) {
                        $storage->ifTrueAssertions[] = new \Psalm\Storage\Assertion(
                            $i,
                            [[$assertion['type']]]
                        );
                        continue 2;
                    }
                }

                $storage->ifTrueAssertions[] = new \Psalm\Storage\Assertion(
                    $assertion['param_name'],
                    [[$assertion['type']]]
                );
            }
        }

        if ($docblockInfo->ifFalseAssertions) {
            $storage->assertions = [];

            foreach ($docblockInfo->ifFalseAssertions as $assertion) {
                foreach ($storage->params as $i => $param) {
                    if ($param->name === $assertion['param_name']) {
                        $storage->ifFalseAssertions[] = new \Psalm\Storage\Assertion(
                            $i,
                            [[$assertion['type']]]
                        );
                        continue 2;
                    }
                }

                $storage->ifFalseAssertions[] = new \Psalm\Storage\Assertion(
                    $assertion['param_name'],
                    [[$assertion['type']]]
                );
            }
        }

        if ($docblockInfo->returnType) {
            if (!$storage->returnType || $docblockInfo->returnType !== $storage->returnType->getId()) {
                $storage->hasTemplateReturnType =
                    $templateTypes !== null &&
                    count(
                        array_intersect(
                            Type::tokenize($docblockInfo->returnType),
                            array_keys($templateTypes)
                        )
                    ) > 0;

                $docblockReturnType = $docblockInfo->returnType;

                if (!$storage->returnTypeLocation) {
                    $storage->returnTypeLocation = new CodeLocation(
                        $this->fileScanner,
                        $stmt,
                        null,
                        false,
                        CodeLocation::FUNCTION_PHPDOC_RETURN_TYPE,
                        $docblockInfo->returnType
                    );
                }

                if ($docblockReturnType) {
                    try {
                        $fixedTypeTokens = Type::fixUpLocalType(
                            $docblockReturnType,
                            $this->aliases,
                            $this->functionTemplateTypes + $this->classTemplateTypes,
                            $this->typeAliases
                        );

                        $storage->returnType = Type::parseTokens(
                            $fixedTypeTokens,
                            false,
                            $this->functionTemplateTypes + $this->classTemplateTypes
                        );
                        $storage->returnType->setFromDocblock();

                        if ($storage->signatureReturnType) {
                            $allTypehintTypesMatch = true;
                            $signatureReturnAtomicTypes = $storage->signatureReturnType->getTypes();

                            foreach ($storage->returnType->getTypes() as $key => $type) {
                                if (isset($signatureReturnAtomicTypes[$key])) {
                                    $type->fromDocblock = false;
                                } else {
                                    $allTypehintTypesMatch = false;
                                }
                            }

                            if ($allTypehintTypesMatch) {
                                $storage->returnType->fromDocblock = false;
                            }

                            if ($storage->signatureReturnType->isNullable()
                                && !$storage->returnType->isNullable()
                            ) {
                                $storage->returnType->addType(new Type\Atomic\TNull());
                            }
                        }

                        $storage->returnType->queueClassLikesForScanning($this->codebase, $this->fileStorage);
                    } catch (TypeParseTreeException $e) {
                        if (IssueBuffer::accepts(
                            new InvalidDocblock(
                                $e->getMessage() . ' in docblock for ' . $casedFunctionId,
                                new CodeLocation($this->fileScanner, $stmt, null, true)
                            )
                        )) {
                            $storage->hasVisitorIssues = true;
                        }
                    }
                }

                if ($storage->returnType && $docblockInfo->ignoreNullableReturn) {
                    $storage->returnType->ignoreNullableIssues = true;
                }

                if ($storage->returnType && $docblockInfo->ignoreFalsableReturn) {
                    $storage->returnType->ignoreFalsableIssues = true;
                }

                if ($stmt->returnsByRef() && $storage->returnType) {
                    $storage->returnType->byRef = true;
                }

                if ($docblockInfo->returnTypeLineNumber) {
                    $storage->returnTypeLocation->setCommentLine($docblockInfo->returnTypeLineNumber);
                }
            }
        }

        foreach ($docblockInfo->globals as $global) {
            try {
                $storage->globalTypes[$global['name']] = Type::parseTokens(
                    Type::fixUpLocalType(
                        $global['type'],
                        $this->aliases,
                        null,
                        $this->typeAliases
                    ),
                    false
                );
            } catch (TypeParseTreeException $e) {
                if (IssueBuffer::accepts(
                    new InvalidDocblock(
                        $e->getMessage() . ' in docblock for ' . $casedFunctionId,
                        new CodeLocation($this->fileScanner, $stmt, null, true)
                    )
                )) {
                    $storage->hasVisitorIssues = true;
                }

                continue;
            }
        }

        if ($docblockInfo->params) {
            $this->improveParamsFromDocblock(
                $storage,
                $docblockInfo->params,
                $stmt
            );
        }

        return $storage;
    }

    /**
     * @param  PhpParser\Node\Param $param
     *
     * @return FunctionLikeParameter
     */
    public function getTranslatedFunctionParam(PhpParser\Node\Param $param)
    {
        $paramType = null;

        $isNullable = $param->default !== null &&
            $param->default instanceof PhpParser\Node\Expr\ConstFetch &&
            $param->default->name instanceof PhpParser\Node\Name &&
            strtolower($param->default->name->parts[0]) === 'null';

        $paramTypehint = $param->type;

        if ($paramTypehint instanceof PhpParser\Node\NullableType) {
            $isNullable = true;
            $paramTypehint = $paramTypehint->type;
        }

        if ($paramTypehint) {
            if ($paramTypehint instanceof PhpParser\Node\Identifier) {
                $paramTypeString = $paramTypehint->name;
            } elseif ($paramTypehint instanceof PhpParser\Node\Name\FullyQualified) {
                $paramTypeString = (string)$paramTypehint;
                $this->codebase->scanner->queueClassLikeForScanning($paramTypeString, $this->filePath);
            } elseif (strtolower($paramTypehint->parts[0]) === 'self') {
                $paramTypeString = $this->fqClasslikeNames[count($this->fqClasslikeNames) - 1];
            } else {
                $paramTypeString = ClassLikeChecker::getFQCLNFromNameObject($paramTypehint, $this->aliases);

                if (!in_array(strtolower($paramTypeString), ['self', 'static', 'parent'], true)) {
                    $this->codebase->scanner->queueClassLikeForScanning($paramTypeString, $this->filePath);
                    $this->fileStorage->referencedClasslikes[strtolower($paramTypeString)] = $paramTypeString;
                }
            }

            if ($paramTypeString) {
                if ($isNullable) {
                    $paramTypeString .= '|null';
                }

                $paramType = Type::parseString($paramTypeString, true);

                if ($param->variadic) {
                    $paramType = new Type\Union([
                        new Type\Atomic\TArray([
                            Type::getInt(),
                            $paramType,
                        ]),
                    ]);
                }
            }
        } elseif ($param->variadic) {
            $paramType = new Type\Union([
                new Type\Atomic\TArray([
                    Type::getInt(),
                    Type::getMixed(),
                ]),
            ]);
        }

        $isOptional = $param->default !== null;

        if ($param->var instanceof PhpParser\Node\Expr\Error || !is_string($param->var->name)) {
            throw new \UnexpectedValueException('Not expecting param name to be non-string');
        }

        return new FunctionLikeParameter(
            $param->var->name,
            $param->byRef,
            $paramType,
            new CodeLocation($this->fileScanner, $param, null, false, CodeLocation::FUNCTION_PARAM_VAR),
            $paramTypehint
                ? new CodeLocation($this->fileScanner, $param, null, false, CodeLocation::FUNCTION_PARAM_TYPE)
                : null,
            $isOptional,
            $isNullable,
            $param->variadic,
            $param->default ? StatementsChecker::getSimpleType($this->codebase, $param->default, $this->aliases) : null
        );
    }

    /**
     * @param  FunctionLikeStorage          $storage
     * @param  array<int, array{type:string,name:string,line_number:int}>  $docblockParams
     * @param  PhpParser\Node\FunctionLike  $function
     *
     * @return void
     */
    private function improveParamsFromDocblock(
        FunctionLikeStorage $storage,
        array $docblockParams,
        PhpParser\Node\FunctionLike $function
    ) {
        $base = $this->fqClasslikeNames
            ? $this->fqClasslikeNames[count($this->fqClasslikeNames) - 1] . '::'
            : '';

        $casedMethodId = $base . $storage->casedName;

        foreach ($docblockParams as $docblockParam) {
            $paramName = $docblockParam['name'];
            $docblockParamVariadic = false;

            if (substr($paramName, 0, 3) === '...') {
                $docblockParamVariadic = true;
                $paramName = substr($paramName, 3);
            }

            $paramName = substr($paramName, 1);

            $storageParam = null;

            foreach ($storage->params as $functionSignatureParam) {
                if ($functionSignatureParam->name === $paramName) {
                    $storageParam = $functionSignatureParam;
                    break;
                }
            }

            if ($storageParam === null) {
                continue;
            }

            $codeLocation = new CodeLocation(
                $this->fileScanner,
                $function,
                null,
                true,
                CodeLocation::FUNCTION_PHPDOC_PARAM_TYPE,
                $docblockParam['type']
            );

            $codeLocation->setCommentLine($docblockParam['line_number']);

            try {
                $newParamType = Type::parseTokens(
                    Type::fixUpLocalType(
                        $docblockParam['type'],
                        $this->aliases,
                        $this->functionTemplateTypes + $this->classTemplateTypes,
                        $this->typeAliases
                    ),
                    false,
                    $this->functionTemplateTypes + $this->classTemplateTypes
                );
            } catch (TypeParseTreeException $e) {
                if (IssueBuffer::accepts(
                    new InvalidDocblock(
                        $e->getMessage() . ' in docblock for ' . $casedMethodId,
                        $codeLocation
                    )
                )) {
                    $storage->hasVisitorIssues = true;
                }

                continue;
            }

            $newParamType->setFromDocblock();

            $newParamType->queueClassLikesForScanning(
                $this->codebase,
                $this->fileStorage,
                $storage->templateTypes ?: []
            );

            if ($docblockParamVariadic) {
                $newParamType = new Type\Union([
                    new Type\Atomic\TArray([
                        Type::getInt(),
                        $newParamType,
                    ]),
                ]);
            }

            $existingParamTypeNullable = $storageParam->isNullable;

            if (!$storageParam->type || $storageParam->type->isMixed() || $storage->templateTypes) {
                if ($existingParamTypeNullable && !$newParamType->isNullable()) {
                    $newParamType->addType(new Type\Atomic\TNull());
                }

                if ($this->config->addParamDefaultToDocblockType
                    && $storageParam->defaultType
                    && !$storageParam->defaultType->isMixed()
                    && (!$storageParam->type || !$storageParam->type->isMixed())
                ) {
                    $newParamType = Type::combineUnionTypes($newParamType, $storageParam->defaultType);
                }

                $storageParam->type = $newParamType;
                $storageParam->typeLocation = $codeLocation;
                continue;
            }

            $storageParamAtomicTypes = $storageParam->type->getTypes();

            $allTypesMatch = true;
            $allTypehintTypesMatch = true;

            foreach ($newParamType->getTypes() as $key => $type) {
                if (isset($storageParamAtomicTypes[$key])) {
                    if ($storageParamAtomicTypes[$key]->getId() !== $type->getId()) {
                        $allTypesMatch = false;
                    }

                    $type->fromDocblock = false;
                } else {
                    $allTypesMatch = false;
                    $allTypehintTypesMatch = false;
                }
            }

            if ($allTypesMatch) {
                continue;
            }

            if ($allTypehintTypesMatch) {
                $newParamType->fromDocblock = false;
            }

            if ($existingParamTypeNullable && !$newParamType->isNullable()) {
                $newParamType->addType(new Type\Atomic\TNull());
            }

            $storageParam->type = $newParamType;
            $storageParam->typeLocation = $codeLocation;
        }
    }

    /**
     * @param   PhpParser\Node\Stmt\Property    $stmt
     * @param   Config                          $config
     * @param   string                          $fqClasslikeName
     *
     * @return  void
     */
    private function visitPropertyDeclaration(
        PhpParser\Node\Stmt\Property $stmt,
        Config $config,
        ClassLikeStorage $storage,
        $fqClasslikeName
    ) {
        if (!$this->fqClasslikeNames) {
            throw new \LogicException('$this->fqClasslikeNames should not be empty');
        }

        $comment = $stmt->getDocComment();
        $varComment = null;

        $propertyIsInitialized = false;

        $existingConstants = $storage->protectedClassConstants
            + $storage->privateClassConstants
            + $storage->publicClassConstants;

        if ($comment && $comment->getText() && ($config->useDocblockTypes || $config->useDocblockPropertyTypes)) {
            if (preg_match('/[ \t\*]+@psalm-suppress[ \t]+PropertyNotSetInConstructor/', (string)$comment)) {
                $propertyIsInitialized = true;
            }

            try {
                $propertyTypeLineNumber = $comment->getLine();
                $varComments = CommentChecker::getTypeFromComment(
                    $comment->getText(),
                    $this->fileScanner,
                    $this->aliases,
                    $this->functionTemplateTypes + $this->classTemplateTypes,
                    $propertyTypeLineNumber,
                    null,
                    $this->typeAliases
                );

                $varComment = array_pop($varComments);
            } catch (IncorrectDocblockException $e) {
                if (IssueBuffer::accepts(
                    new MissingDocblockType(
                        $e->getMessage(),
                        new CodeLocation($this->fileScanner, $stmt, null, true)
                    )
                )) {
                    $storage->hasVisitorIssues = true;
                }
            } catch (DocblockParseException $e) {
                if (IssueBuffer::accepts(
                    new InvalidDocblock(
                        $e->getMessage(),
                        new CodeLocation($this->fileScanner, $stmt, null, true)
                    )
                )) {
                    $storage->hasVisitorIssues = true;
                }
            }
        }

        $propertyGroupType = $varComment ? $varComment->type : null;

        if ($propertyGroupType) {
            $propertyGroupType->queueClassLikesForScanning($this->codebase, $this->fileStorage);
            $propertyGroupType->setFromDocblock();
        }

        foreach ($stmt->props as $property) {
            $propertyTypeLocation = null;
            $defaultType = null;

            if (!$propertyGroupType) {
                if ($property->default) {
                    $defaultType = StatementsChecker::getSimpleType(
                        $this->codebase,
                        $property->default,
                        $this->aliases,
                        null,
                        $existingConstants,
                        $fqClasslikeName
                    );
                }

                $propertyType = false;
            } else {
                if ($varComment && $varComment->lineNumber) {
                    $propertyTypeLocation = new CodeLocation(
                        $this->fileScanner,
                        $stmt,
                        null,
                        false,
                        CodeLocation::VAR_TYPE,
                        $varComment->originalType
                    );
                    $propertyTypeLocation->setCommentLine($varComment->lineNumber);
                }

                $propertyType = count($stmt->props) === 1 ? $propertyGroupType : clone $propertyGroupType;
            }

            $propertyStorage = $storage->properties[$property->name->name] = new PropertyStorage();
            $propertyStorage->isStatic = (bool)$stmt->isStatic();
            $propertyStorage->type = $propertyType;
            $propertyStorage->location = new CodeLocation($this->fileScanner, $property->name);
            $propertyStorage->typeLocation = $propertyTypeLocation;
            $propertyStorage->hasDefault = $property->default ? true : false;
            $propertyStorage->suggestedType = $propertyGroupType ? null : $defaultType;
            $propertyStorage->deprecated = $varComment ? $varComment->deprecated : false;

            if ($stmt->isPublic()) {
                $propertyStorage->visibility = ClassLikeChecker::VISIBILITY_PUBLIC;
            } elseif ($stmt->isProtected()) {
                $propertyStorage->visibility = ClassLikeChecker::VISIBILITY_PROTECTED;
            } elseif ($stmt->isPrivate()) {
                $propertyStorage->visibility = ClassLikeChecker::VISIBILITY_PRIVATE;
            }

            $fqClasslikeName = $this->fqClasslikeNames[count($this->fqClasslikeNames) - 1];

            $propertyId = $fqClasslikeName . '::$' . $property->name->name;

            $storage->declaringPropertyIds[$property->name->name] = $fqClasslikeName;
            $storage->appearingPropertyIds[$property->name->name] = $propertyId;

            if ($propertyIsInitialized) {
                $storage->initializedProperties[$property->name->name] = true;
            }

            if (!$stmt->isPrivate()) {
                $storage->inheritablePropertyIds[$property->name->name] = $propertyId;
            }
        }
    }

    /**
     * @param   PhpParser\Node\Stmt\ClassConst  $stmt
     * @param   string $fqClasslikeName
     *
     * @return  void
     */
    private function visitClassConstDeclaration(
        PhpParser\Node\Stmt\ClassConst $stmt,
        ClassLikeStorage $storage,
        $fqClasslikeName
    ) {
        $existingConstants = $storage->protectedClassConstants
            + $storage->privateClassConstants
            + $storage->publicClassConstants;

        $comment = $stmt->getDocComment();
        $deprecated = false;
        $config = $this->config;

        if ($comment && $comment->getText() && ($config->useDocblockTypes || $config->useDocblockPropertyTypes)) {
            $comments = CommentChecker::parseDocComment($comment->getText(), 0);

            if (isset($comments['specials']['deprecated'])) {
                $deprecated = true;
            }
        }

        foreach ($stmt->consts as $const) {
            $constType = StatementsChecker::getSimpleType(
                $this->codebase,
                $const->value,
                $this->aliases,
                null,
                $existingConstants,
                $fqClasslikeName
            );

            if ($constType) {
                $existingConstants[$const->name->name] = $constType;

                if ($stmt->isProtected()) {
                    $storage->protectedClassConstants[$const->name->name] = $constType;
                } elseif ($stmt->isPrivate()) {
                    $storage->privateClassConstants[$const->name->name] = $constType;
                } else {
                    $storage->publicClassConstants[$const->name->name] = $constType;
                }
            } else {
                if ($stmt->isProtected()) {
                    $storage->protectedClassConstantNodes[$const->name->name] = $const->value;
                } elseif ($stmt->isPrivate()) {
                    $storage->privateClassConstantNodes[$const->name->name] = $const->value;
                } else {
                    $storage->publicClassConstantNodes[$const->name->name] = $const->value;
                }

                $storage->aliases = $this->aliases;
            }

            if ($deprecated) {
                $storage->deprecatedConstants[$const->name->name] = true;
            }
        }
    }

    /**
     * @param  PhpParser\Node\Expr\Include_ $stmt
     *
     * @return void
     */
    public function visitInclude(PhpParser\Node\Expr\Include_ $stmt)
    {
        $config = Config::getInstance();

        if (!$config->allowIncludes) {
            throw new FileIncludeException(
                'File includes are not allowed per your Psalm config - check the allowFileIncludes flag.'
            );
        }

        if ($stmt->expr instanceof PhpParser\Node\Scalar\String_) {
            $pathToFile = $stmt->expr->value;

            // attempts to resolve using get_include_path dirs
            $includePath = IncludeChecker::resolveIncludePath($pathToFile, dirname($this->filePath));
            $pathToFile = $includePath ? $includePath : $pathToFile;

            if ($pathToFile[0] !== DIRECTORY_SEPARATOR) {
                $pathToFile = getcwd() . DIRECTORY_SEPARATOR . $pathToFile;
            }
        } else {
            $pathToFile = IncludeChecker::getPathTo($stmt->expr, $this->filePath);
        }

        if ($pathToFile) {
            $reducePattern = '/\/[^\/]+\/\.\.\//';

            while (preg_match($reducePattern, $pathToFile)) {
                $pathToFile = preg_replace($reducePattern, DIRECTORY_SEPARATOR, $pathToFile);
            }

            if ($this->filePath === $pathToFile) {
                return;
            }

            if ($this->codebase->fileExists($pathToFile)) {
                if ($this->scanDeep) {
                    $this->codebase->scanner->addFileToDeepScan($pathToFile);
                } else {
                    $this->codebase->scanner->addFileToShallowScan($pathToFile);
                }

                $this->fileStorage->requiredFilePaths[strtolower($pathToFile)] = $pathToFile;

                return;
            }
        }

        return;
    }

    /**
     * @return string
     */
    public function getFilePath()
    {
        return $this->filePath;
    }

    /**
     * @return string
     */
    public function getFileName()
    {
        return $this->fileScanner->getFileName();
    }

    /**
     * @return string
     */
    public function getRootFilePath()
    {
        return $this->fileScanner->getRootFilePath();
    }

    /**
     * @return string
     */
    public function getRootFileName()
    {
        return $this->fileScanner->getRootFileName();
    }

    /**
     * @return Aliases
     */
    public function getAliases()
    {
        return $this->aliases;
    }
}
