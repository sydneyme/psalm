<?php
namespace Psalm\Checker;

use PhpParser;
use Psalm\Aliases;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\Context;
use Psalm\FileManipulation\FileManipulationBuffer;
use Psalm\Issue\DuplicateClass;
use Psalm\Issue\InaccessibleProperty;
use Psalm\Issue\InvalidClass;
use Psalm\Issue\MissingDependency;
use Psalm\Issue\ReservedWord;
use Psalm\Issue\UndefinedClass;
use Psalm\IssueBuffer;
use Psalm\Provider\FileReferenceProvider;
use Psalm\StatementsSource;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type;

abstract class ClassLikeChecker extends SourceChecker implements StatementsSource
{
    const VISIBILITY_PUBLIC = 1;
    const VISIBILITY_PROTECTED = 2;
    const VISIBILITY_PRIVATE = 3;

    /**
     * @var array
     */
    public static $SPECIALTYPES = [
        'int' => 'int',
        'string' => 'string',
        'float' => 'float',
        'bool' => 'bool',
        'false' => 'false',
        'object' => 'object',
        'empty' => 'empty',
        'callable' => 'callable',
        'array' => 'array',
        'iterable' => 'iterable',
        'null' => 'null',
        'mixed' => 'mixed',
    ];

    /**
     * @var array
     */
    public static $GETTYPETYPES = [
        'boolean' => true,
        'integer' => true,
        'double' => true,
        'string' => true,
        'array' => true,
        'object' => true,
        'resource' => true,
        'NULL' => true,
        'unknown type' => true,
    ];

    /**
     * @var PhpParser\Node\Stmt\ClassLike
     */
    protected $class;

    /**
     * @var StatementsSource
     */
    protected $source;

    /** @var FileChecker */
    public $fileChecker;

    /**
     * @var string
     */
    protected $fqClassName;

    /**
     * The parent class
     *
     * @var string|null
     */
    protected $parentFqClassName;

    /**
     * @var PhpParser\Node\Stmt[]
     */
    protected $leftoverStmts = [];

    /** @var ClassLikeStorage */
    protected $storage;

    /**
     * @param PhpParser\Node\Stmt\ClassLike $class
     * @param StatementsSource              $source
     * @param string                        $fqClassName
     */
    public function __construct(PhpParser\Node\Stmt\ClassLike $class, StatementsSource $source, $fqClassName)
    {
        $this->class = $class;
        $this->source = $source;
        $this->fileChecker = $source->getFileChecker();
        $this->fqClassName = $fqClassName;

        $this->storage = $this->fileChecker->projectChecker->classlikeStorageProvider->get($fqClassName);
    }

    /**
     * @param  string       $methodName
     * @param  Context      $context
     *
     * @return void
     */
    public function getMethodMutations(
        $methodName,
        Context $context
    ) {
        $projectChecker = $this->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;

        foreach ($this->class->stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\ClassMethod &&
                strtolower($stmt->name->name) === strtolower($methodName)
            ) {
                $methodChecker = new MethodChecker($stmt, $this);

                $methodChecker->analyze($context, null, true);
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
                        if ($traitStmt instanceof PhpParser\Node\Stmt\ClassMethod &&
                            strtolower($traitStmt->name->name) === strtolower($methodName)
                        ) {
                            $methodChecker = new MethodChecker($traitStmt, $traitChecker);

                            $actualMethodId = (string)$methodChecker->getMethodId();

                            if ($context->self && $context->self !== $this->fqClassName) {
                                $analyzedMethodId = (string)$methodChecker->getMethodId($context->self);
                                $declaringMethodId = $codebase->methods->getDeclaringMethodId($analyzedMethodId);

                                if ($actualMethodId !== $declaringMethodId) {
                                    break;
                                }
                            }

                            $methodChecker->analyze($context, null, true);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param  string           $fqClassName
     * @param  array<string>    $suppressedIssues
     * @param  bool             $inferred - whether or not the type was inferred
     *
     * @return bool|null
     */
    public static function checkFullyQualifiedClassLikeName(
        StatementsSource $statementsSource,
        $fqClassName,
        CodeLocation $codeLocation,
        array $suppressedIssues,
        $inferred = true
    ) {
        if (empty($fqClassName)) {
            if (IssueBuffer::accepts(
                new UndefinedClass(
                    'Class or interface <empty string> does not exist',
                    $codeLocation,
                    'empty string'
                ),
                $suppressedIssues
            )) {
                return false;
            }

            return;
        }

        $fqClassName = preg_replace('/^\\\/', '', $fqClassName);

        if (in_array($fqClassName, ['callable', 'iterable', 'self', 'static', 'parent'], true)) {
            return true;
        }

        $projectChecker = $statementsSource->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;

        if (preg_match(
            '/(^|\\\)(int|float|bool|string|void|null|false|true|resource|object|numeric|mixed)$/i',
            $fqClassName
        )
        ) {
            $classNameParts = explode('\\', $fqClassName);
            $className = array_pop($classNameParts);

            if (IssueBuffer::accepts(
                new ReservedWord(
                    $className . ' is a reserved word',
                    $codeLocation,
                    $className
                ),
                $suppressedIssues
            )) {
                // fall through
            }

            return null;
        }

        $classExists = $codebase->classExists($fqClassName);
        $interfaceExists = $codebase->interfaceExists($fqClassName);

        if (!$classExists && !$interfaceExists) {
            if (!$codebase->classlikes->traitExists($fqClassName)) {
                if (IssueBuffer::accepts(
                    new UndefinedClass(
                        'Class or interface ' . $fqClassName . ' does not exist',
                        $codeLocation,
                        $fqClassName
                    ),
                    $suppressedIssues
                )) {
                    return false;
                }
            }

            return null;
        }

        $classStorage = $projectChecker->classlikeStorageProvider->get($fqClassName);

        foreach ($classStorage->invalidDependencies as $dependencyClassName) {
            if (IssueBuffer::accepts(
                new MissingDependency(
                    $fqClassName . ' depends on class or interface '
                        . $dependencyClassName . ' that does not exist',
                    $codeLocation,
                    $fqClassName
                ),
                $suppressedIssues
            )) {
                return false;
            }
        }

        if ($projectChecker->getCodeBase()->collectReferences && !$inferred) {
            if ($classStorage->referencingLocations === null) {
                $classStorage->referencingLocations = [];
            }
            $classStorage->referencingLocations[$codeLocation->filePath][] = $codeLocation;
        }

        if (($classExists && !$codebase->classHasCorrectCasing($fqClassName)) ||
            ($interfaceExists && !$codebase->interfaceHasCorrectCasing($fqClassName))
        ) {
            if ($codebase->classlikes->isUserDefined($fqClassName)) {
                if (IssueBuffer::accepts(
                    new InvalidClass(
                        'Class or interface ' . $fqClassName . ' has wrong casing',
                        $codeLocation,
                        $fqClassName
                    ),
                    $suppressedIssues
                )) {
                    // fall through here
                }
            }
        }

        FileReferenceProvider::addFileReferenceToClass(
            $codeLocation->filePath,
            strtolower($fqClassName)
        );

        if (!$inferred) {
            $pluginClasses = $codebase->config->afterClasslikeExistsChecks;

            if ($pluginClasses) {
                $fileManipulations = [];

                foreach ($pluginClasses as $pluginFqClassName) {
                    $pluginFqClassName::afterClassLikeExistsCheck(
                        $statementsSource,
                        $fqClassName,
                        $codeLocation,
                        $fileManipulations
                    );
                }

                if ($fileManipulations) {
                    /** @psalm-suppress MixedTypeCoercion */
                    FileManipulationBuffer::add($codeLocation->filePath, $fileManipulations);
                }
            }
        }

        return true;
    }

    /**
     * Gets the fully-qualified class name from a Name object
     *
     * @param  PhpParser\Node\Name      $className
     * @param  Aliases                  $aliases
     *
     * @return string
     */
    public static function getFQCLNFromNameObject(PhpParser\Node\Name $className, Aliases $aliases)
    {
        /** @var string|null */
        $resolvedName = $className->getAttribute('resolvedName');

        if ($resolvedName) {
            return $resolvedName;
        }

        if ($className instanceof PhpParser\Node\Name\FullyQualified) {
            return implode('\\', $className->parts);
        }

        if (in_array($className->parts[0], ['self', 'static', 'parent'], true)) {
            return $className->parts[0];
        }

        return Type::getFQCLNFromString(
            implode('\\', $className->parts),
            $aliases
        );
    }

    /**
     * @return null|string
     */
    public function getNamespace()
    {
        return $this->source->getNamespace();
    }

    /**
     * @return array<string, string>
     */
    public function getAliasedClassesFlipped()
    {
        if ($this->source instanceof NamespaceChecker || $this->source instanceof FileChecker) {
            return $this->source->getAliasedClassesFlipped();
        }

        return [];
    }

    /**
     * @return string
     */
    public function getFQCLN()
    {
        return $this->fqClassName;
    }

    /**
     * @return string|null
     */
    public function getClassName()
    {
        return $this->class->name ? $this->class->name->name : null;
    }

    /**
     * @return string|null
     */
    public function getParentFQCLN()
    {
        return $this->parentFqClassName;
    }

    /**
     * @return bool
     */
    public function isStatic()
    {
        return false;
    }

    /**
     * Gets the Psalm type from a particular value
     *
     * @param  mixed $value
     *
     * @return Type\Union
     */
    public static function getTypeFromValue($value)
    {
        switch (gettype($value)) {
            case 'boolean':
                if ($value) {
                    return Type::getTrue();
                }

                return Type::getFalse();

            case 'integer':
                return Type::getInt(false, $value);

            case 'double':
                return Type::getFloat($value);

            case 'string':
                return Type::getString($value);

            case 'array':
                return Type::getArray();

            case 'NULL':
                return Type::getNull();

            default:
                return Type::getMixed();
        }
    }

    /**
     * @param  string           $propertyId
     * @param  string|null      $callingContext
     * @param  StatementsSource $source
     * @param  CodeLocation     $codeLocation
     * @param  array            $suppressedIssues
     * @param  bool             $emitIssues
     *
     * @return bool|null
     */
    public static function checkPropertyVisibility(
        $propertyId,
        $callingContext,
        StatementsSource $source,
        CodeLocation $codeLocation,
        array $suppressedIssues,
        $emitIssues = true
    ) {
        $projectChecker = $source->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;

        $declaringPropertyClass = $codebase->properties->getDeclaringClassForProperty($propertyId);
        $appearingPropertyClass = $codebase->properties->getAppearingClassForProperty($propertyId);

        if (!$declaringPropertyClass || !$appearingPropertyClass) {
            throw new \UnexpectedValueException(
                'Appearing/Declaring classes are not defined for ' . $propertyId
            );
        }

        list(, $propertyName) = explode('::$', (string)$propertyId);

        // if the calling class is the same, we know the property exists, so it must be visible
        if ($appearingPropertyClass === $callingContext) {
            return $emitIssues ? null : true;
        }

        if ($source->getSource() instanceof TraitChecker && $declaringPropertyClass === $source->getFQCLN()) {
            return $emitIssues ? null : true;
        }

        $classStorage = $projectChecker->classlikeStorageProvider->get($declaringPropertyClass);

        if (!isset($classStorage->properties[$propertyName])) {
            throw new \UnexpectedValueException('$storage should not be null for ' . $propertyId);
        }

        $storage = $classStorage->properties[$propertyName];

        switch ($storage->visibility) {
            case self::VISIBILITY_PUBLIC:
                return $emitIssues ? null : true;

            case self::VISIBILITY_PRIVATE:
                if (!$callingContext || $appearingPropertyClass !== $callingContext) {
                    if ($emitIssues && IssueBuffer::accepts(
                        new InaccessibleProperty(
                            'Cannot access private property ' . $propertyId . ' from context ' . $callingContext,
                            $codeLocation
                        ),
                        $suppressedIssues
                    )) {
                        return false;
                    }

                    return null;
                }

                return $emitIssues ? null : true;

            case self::VISIBILITY_PROTECTED:
                if ($appearingPropertyClass === $callingContext) {
                    return null;
                }

                if (!$callingContext) {
                    if ($emitIssues && IssueBuffer::accepts(
                        new InaccessibleProperty(
                            'Cannot access protected property ' . $propertyId,
                            $codeLocation
                        ),
                        $suppressedIssues
                    )) {
                        return false;
                    }

                    return null;
                }

                if ($codebase->classExtends($appearingPropertyClass, $callingContext)) {
                    return $emitIssues ? null : true;
                }

                if (!$codebase->classExtends($callingContext, $appearingPropertyClass)) {
                    if ($emitIssues && IssueBuffer::accepts(
                        new InaccessibleProperty(
                            'Cannot access protected property ' . $propertyId . ' from context ' . $callingContext,
                            $codeLocation
                        ),
                        $suppressedIssues
                    )) {
                        return false;
                    }

                    return null;
                }
        }

        return $emitIssues ? null : true;
    }

    /**
     * @param   string $filePath
     *
     * @return  array<string, string>
     */
    public static function getClassesForFile(ProjectChecker $projectChecker, $filePath)
    {
        try {
            return $projectChecker->fileStorageProvider->get($filePath)->classlikesInFile;
        } catch (\InvalidArgumentException $e) {
            return [];
        }
    }

    /**
     * @return FileChecker
     */
    public function getFileChecker()
    {
        return $this->fileChecker;
    }
}
