<?php
namespace Psalm\Codebase;

use PhpParser;
use Psalm\Aliases;
use Psalm\Checker\ClassLikeChecker;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\Issue\PossiblyUnusedMethod;
use Psalm\Issue\PossiblyUnusedParam;
use Psalm\Issue\PossiblyUnusedProperty;
use Psalm\Issue\UnusedClass;
use Psalm\Issue\UnusedMethod;
use Psalm\Issue\UnusedProperty;
use Psalm\IssueBuffer;
use Psalm\Provider\ClassLikeStorageProvider;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type;
use ReflectionProperty;

/**
 * @internal
 *
 * Handles information about classes, interfaces and traits
 */
class ClassLikes
{
    /**
     * @var Codebase
     */
    private $codebase;

    /**
     * @var ClassLikeStorageProvider
     */
    private $classlikeStorageProvider;

    /**
     * @var array<string, bool>
     */
    private $existingClasslikesLc = [];

    /**
     * @var array<string, bool>
     */
    private $existingClassesLc = [];

    /**
     * @var array<string, bool>
     */
    private $existingClasses = [];

    /**
     * @var array<string, bool>
     */
    private $existingInterfacesLc = [];

    /**
     * @var array<string, bool>
     */
    private $existingInterfaces = [];

    /**
     * @var array<string, bool>
     */
    private $existingTraitsLc = [];

    /**
     * @var array<string, bool>
     */
    private $existingTraits = [];

    /**
     * @var array<string, PhpParser\Node\Stmt\Trait_>
     */
    private $traitNodes = [];

    /**
     * @var array<string, Aliases>
     */
    private $traitAliases = [];

    /**
     * @var array<string, int>
     */
    private $classlikeReferences = [];

    /**
     * @var bool
     */
    public $collectReferences = false;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Methods
     */
    private $methods;

    /**
     * @var Scanner
     */
    private $scanner;

    /**
     * @var bool
     */
    private $debugOutput;

    /**
     * @param bool $debugOutput
     */
    public function __construct(
        Config $config,
        Codebase $codebase,
        ClassLikeStorageProvider $storageProvider,
        Scanner $scanner,
        Methods $methods,
        $debugOutput
    ) {
        $this->config = $config;
        $this->classlikeStorageProvider = $storageProvider;
        $this->scanner = $scanner;
        $this->debugOutput = $debugOutput;
        $this->methods = $methods;
        $this->codebase = $codebase;

        $this->collectPredefinedClassLikes();
    }

    /**
     * @return void
     */
    private function collectPredefinedClassLikes()
    {
        /** @var array<int, string> */
        $predefinedClasses = get_declared_classes();

        foreach ($predefinedClasses as $predefinedClass) {
            $reflectionClass = new \ReflectionClass($predefinedClass);

            if (!$reflectionClass->isUserDefined()) {
                $predefinedClassLc = strtolower($predefinedClass);
                $this->existingClasslikesLc[$predefinedClassLc] = true;
                $this->existingClassesLc[$predefinedClassLc] = true;
            }
        }

        /** @var array<int, string> */
        $predefinedInterfaces = get_declared_interfaces();

        foreach ($predefinedInterfaces as $predefinedInterface) {
            $reflectionClass = new \ReflectionClass($predefinedInterface);

            if (!$reflectionClass->isUserDefined()) {
                $predefinedInterfaceLc = strtolower($predefinedInterface);
                $this->existingClasslikesLc[$predefinedInterfaceLc] = true;
                $this->existingInterfacesLc[$predefinedInterfaceLc] = true;
            }
        }
    }

    /**
     * @param string        $fqClassName
     * @param string|null   $filePath
     *
     * @return void
     */
    public function addFullyQualifiedClassName($fqClassName, $filePath = null)
    {
        $fqClassNameLc = strtolower($fqClassName);
        $this->existingClasslikesLc[$fqClassNameLc] = true;
        $this->existingClassesLc[$fqClassNameLc] = true;
        $this->existingTraitsLc[$fqClassNameLc] = false;
        $this->existingInterfacesLc[$fqClassNameLc] = false;
        $this->existingClasses[$fqClassName] = true;

        if ($filePath) {
            $this->scanner->setClassLikeFilePath($fqClassNameLc, $filePath);
        }
    }

    /**
     * @param string        $fqClassName
     * @param string|null   $filePath
     *
     * @return void
     */
    public function addFullyQualifiedInterfaceName($fqClassName, $filePath = null)
    {
        $fqClassNameLc = strtolower($fqClassName);
        $this->existingClasslikesLc[$fqClassNameLc] = true;
        $this->existingInterfacesLc[$fqClassNameLc] = true;
        $this->existingClassesLc[$fqClassNameLc] = false;
        $this->existingTraitsLc[$fqClassNameLc] = false;
        $this->existingInterfaces[$fqClassName] = true;

        if ($filePath) {
            $this->scanner->setClassLikeFilePath($fqClassNameLc, $filePath);
        }
    }

    /**
     * @param string        $fqClassName
     * @param string|null   $filePath
     *
     * @return void
     */
    public function addFullyQualifiedTraitName($fqClassName, $filePath = null)
    {
        $fqClassNameLc = strtolower($fqClassName);
        $this->existingClasslikesLc[$fqClassNameLc] = true;
        $this->existingTraitsLc[$fqClassNameLc] = true;
        $this->existingClassesLc[$fqClassNameLc] = false;
        $this->existingInterfacesLc[$fqClassNameLc] = false;
        $this->existingTraits[$fqClassName] = true;

        if ($filePath) {
            $this->scanner->setClassLikeFilePath($fqClassNameLc, $filePath);
        }
    }

    /**
     * @param string        $fqClassNameLc
     * @param string|null   $filePath
     *
     * @return void
     */
    public function addFullyQualifiedClassLikeName($fqClassNameLc, $filePath = null)
    {
        $this->existingClasslikesLc[$fqClassNameLc] = true;

        if ($filePath) {
            $this->scanner->setClassLikeFilePath($fqClassNameLc, $filePath);
        }
    }

    /**
     * @param string $fqClassName
     *
     * @return bool
     */
    public function hasFullyQualifiedClassName($fqClassName)
    {
        $fqClassNameLc = strtolower($fqClassName);

        if (!isset($this->existingClassesLc[$fqClassNameLc])
            || !$this->existingClassesLc[$fqClassNameLc]
            || !$this->classlikeStorageProvider->has($fqClassNameLc)
        ) {
            if ((
                !isset($this->existingClassesLc[$fqClassNameLc])
                    || $this->existingClassesLc[$fqClassNameLc] === true
                )
                && !$this->classlikeStorageProvider->has($fqClassNameLc)
            ) {
                if ($this->debugOutput) {
                    echo 'Last-chance attempt to hydrate ' . $fqClassName . "\n";
                }
                // attempt to load in the class
                $this->scanner->queueClassLikeForScanning($fqClassName);
                $this->codebase->scanFiles();

                if (!isset($this->existingClassesLc[$fqClassNameLc])) {
                    $this->existingClassesLc[$fqClassNameLc] = false;

                    return false;
                }

                return $this->existingClassesLc[$fqClassNameLc];
            }

            return false;
        }

        if ($this->collectReferences) {
            if (!isset($this->classlikeReferences[$fqClassNameLc])) {
                $this->classlikeReferences[$fqClassNameLc] = 0;
            }

            ++$this->classlikeReferences[$fqClassNameLc];
        }

        return true;
    }

    /**
     * @param string $fqClassName
     *
     * @return bool
     */
    public function hasFullyQualifiedInterfaceName($fqClassName)
    {
        $fqClassNameLc = strtolower($fqClassName);

        if (!isset($this->existingInterfacesLc[$fqClassNameLc])
            || !$this->existingInterfacesLc[$fqClassNameLc]
            || !$this->classlikeStorageProvider->has($fqClassNameLc)
        ) {
            if ((
                !isset($this->existingClassesLc[$fqClassNameLc])
                    || $this->existingClassesLc[$fqClassNameLc] === true
                )
                && !$this->classlikeStorageProvider->has($fqClassNameLc)
            ) {
                if ($this->debugOutput) {
                    echo 'Last-chance attempt to hydrate ' . $fqClassName . "\n";
                }

                // attempt to load in the class
                $this->scanner->queueClassLikeForScanning($fqClassName);
                $this->scanner->scanFiles($this);

                if (!isset($this->existingInterfacesLc[$fqClassNameLc])) {
                    $this->existingInterfacesLc[$fqClassNameLc] = false;

                    return false;
                }

                return $this->existingInterfacesLc[$fqClassNameLc];
            }

            return false;
        }

        if ($this->collectReferences) {
            if (!isset($this->classlikeReferences[$fqClassNameLc])) {
                $this->classlikeReferences[$fqClassNameLc] = 0;
            }

            ++$this->classlikeReferences[$fqClassNameLc];
        }

        return true;
    }

    /**
     * @param string $fqClassName
     *
     * @return bool
     */
    public function hasFullyQualifiedTraitName($fqClassName)
    {
        $fqClassNameLc = strtolower($fqClassName);

        if (!isset($this->existingTraitsLc[$fqClassNameLc]) ||
            !$this->existingTraitsLc[$fqClassNameLc]
        ) {
            return false;
        }

        if ($this->collectReferences) {
            if (!isset($this->classlikeReferences[$fqClassNameLc])) {
                $this->classlikeReferences[$fqClassNameLc] = 0;
            }

            ++$this->classlikeReferences[$fqClassNameLc];
        }

        return true;
    }

    /**
     * Check whether a class/interface exists
     *
     * @param  string          $fqClassName
     * @param  CodeLocation $codeLocation
     *
     * @return bool
     */
    public function classOrInterfaceExists(
        $fqClassName,
        CodeLocation $codeLocation = null
    ) {
        if (!$this->classExists($fqClassName) && !$this->interfaceExists($fqClassName)) {
            return false;
        }

        if ($this->collectReferences && $codeLocation) {
            $classStorage = $this->classlikeStorageProvider->get($fqClassName);
            if ($classStorage->referencingLocations === null) {
                $classStorage->referencingLocations = [];
            }
            $classStorage->referencingLocations[$codeLocation->filePath][] = $codeLocation;
        }

        return true;
    }

    /**
     * Determine whether or not a given class exists
     *
     * @param  string       $fqClassName
     *
     * @return bool
     */
    public function classExists($fqClassName)
    {
        if (isset(ClassLikeChecker::$SPECIALTYPES[$fqClassName])) {
            return false;
        }

        if ($fqClassName === 'Generator') {
            return true;
        }

        return $this->hasFullyQualifiedClassName($fqClassName);
    }

    /**
     * Determine whether or not a class extends a parent
     *
     * @param  string       $fqClassName
     * @param  string       $possibleParent
     *
     * @return bool
     */
    public function classExtends($fqClassName, $possibleParent)
    {
        $fqClassName = strtolower($fqClassName);

        if ($fqClassName === 'generator') {
            return false;
        }

        $classStorage = $this->classlikeStorageProvider->get($fqClassName);

        return isset($classStorage->parentClasses[strtolower($possibleParent)]);
    }

    /**
     * Check whether a class implements an interface
     *
     * @param  string       $fqClassName
     * @param  string       $interface
     *
     * @return bool
     */
    public function classImplements($fqClassName, $interface)
    {
        $interfaceId = strtolower($interface);

        $fqClassName = strtolower($fqClassName);

        if ($interfaceId === 'callable' && $fqClassName === 'closure') {
            return true;
        }

        if ($interfaceId === 'traversable' && $fqClassName === 'generator') {
            return true;
        }

        if ($interfaceId === 'arrayaccess' && $fqClassName === 'domnodelist') {
            return true;
        }

        if (isset(ClassLikeChecker::$SPECIALTYPES[$interfaceId])
            || isset(ClassLikeChecker::$SPECIALTYPES[$fqClassName])
        ) {
            return false;
        }

        $classStorage = $this->classlikeStorageProvider->get($fqClassName);

        return isset($classStorage->classImplements[$interfaceId]);
    }

    /**
     * @param  string         $fqInterfaceName
     *
     * @return bool
     */
    public function interfaceExists($fqInterfaceName)
    {
        if (isset(ClassLikeChecker::$SPECIALTYPES[strtolower($fqInterfaceName)])) {
            return false;
        }

        return $this->hasFullyQualifiedInterfaceName($fqInterfaceName);
    }

    /**
     * @param  string         $interfaceName
     * @param  string         $possibleParent
     *
     * @return bool
     */
    public function interfaceExtends($interfaceName, $possibleParent)
    {
        if (strtolower($interfaceName) === 'iterable' && strtolower($possibleParent) === 'traversable') {
            return true;
        }

        return isset($this->getParentInterfaces($interfaceName)[strtolower($possibleParent)]);
    }

    /**
     * @param  string         $fqInterfaceName
     *
     * @return array<string, string>   all interfaces extended by $interfaceName
     */
    public function getParentInterfaces($fqInterfaceName)
    {
        $fqInterfaceName = strtolower($fqInterfaceName);

        $storage = $this->classlikeStorageProvider->get($fqInterfaceName);

        return $storage->parentInterfaces;
    }

    /**
     * @param  string         $fqTraitName
     *
     * @return bool
     */
    public function traitExists($fqTraitName)
    {
        return $this->hasFullyQualifiedTraitName($fqTraitName);
    }

    /**
     * Determine whether or not a class has the correct casing
     *
     * @param  string $fqClassName
     *
     * @return bool
     */
    public function classHasCorrectCasing($fqClassName)
    {
        if ($fqClassName === 'Generator') {
            return true;
        }

        return isset($this->existingClasses[$fqClassName]);
    }

    /**
     * @param  string $fqInterfaceName
     *
     * @return bool
     */
    public function interfaceHasCorrectCasing($fqInterfaceName)
    {
        return isset($this->existingInterfaces[$fqInterfaceName]);
    }

    /**
     * @param  string $fqTraitName
     *
     * @return bool
     */
    public function traitHasCorrectCase($fqTraitName)
    {
        return isset($this->existingTraits[$fqTraitName]);
    }

    /**
     * @param  string  $fqClassName
     *
     * @return bool
     */
    public function isUserDefined($fqClassName)
    {
        return $this->classlikeStorageProvider->get($fqClassName)->userDefined;
    }

    /**
     * @param  string $fqTraitName
     *
     * @return void
     */
    public function addTraitNode($fqTraitName, PhpParser\Node\Stmt\Trait_ $node, Aliases $aliases)
    {
        $fqTraitNameLc = strtolower($fqTraitName);
        $this->traitNodes[$fqTraitNameLc] = $node;
        $this->traitAliases[$fqTraitNameLc] = $aliases;
    }

    /**
     * @param  string $fqTraitName
     *
     * @return PhpParser\Node\Stmt\Trait_
     */
    public function getTraitNode($fqTraitName)
    {
        $fqTraitNameLc = strtolower($fqTraitName);

        if (isset($this->traitNodes[$fqTraitNameLc])) {
            return $this->traitNodes[$fqTraitNameLc];
        }

        throw new \UnexpectedValueException(
            'Expecting trait statements to exist for ' . $fqTraitName
        );
    }

    /**
     * @param  string $fqTraitName
     *
     * @return Aliases
     */
    public function getTraitAliases($fqTraitName)
    {
        $fqTraitNameLc = strtolower($fqTraitName);

        if (isset($this->traitAliases[$fqTraitNameLc])) {
            return $this->traitAliases[$fqTraitNameLc];
        }

        throw new \UnexpectedValueException(
            'Expecting trait aliases to exist for ' . $fqTraitName
        );
    }

    /**
     * @return void
     */
    public function checkClassReferences()
    {
        foreach ($this->existingClasslikesLc as $fqClassNameLc => $_) {
            try {
                $classlikeStorage = $this->classlikeStorageProvider->get($fqClassNameLc);
            } catch (\InvalidArgumentException $e) {
                continue;
            }

            if ($classlikeStorage->location
                && $this->config->isInProjectDirs($classlikeStorage->location->filePath)
                && !$classlikeStorage->isTrait
            ) {
                if (!isset($this->classlikeReferences[$fqClassNameLc])) {
                    if (IssueBuffer::accepts(
                        new UnusedClass(
                            'Class ' . $classlikeStorage->name . ' is never used',
                            $classlikeStorage->location
                        )
                    )) {
                        // fall through
                    }
                } else {
                    $this->checkMethodReferences($classlikeStorage);
                }
            }
        }
    }

    /**
     * @param  string $className
     * @param  mixed  $visibility
     *
     * @return array<string,Type\Union>
     */
    public function getConstantsForClass($className, $visibility)
    {
        $className = strtolower($className);

        $storage = $this->classlikeStorageProvider->get($className);

        if ($visibility === ReflectionProperty::IS_PUBLIC) {
            return $storage->publicClassConstants;
        }

        if ($visibility === ReflectionProperty::IS_PROTECTED) {
            return array_merge(
                $storage->publicClassConstants,
                $storage->protectedClassConstants
            );
        }

        if ($visibility === ReflectionProperty::IS_PRIVATE) {
            return array_merge(
                $storage->publicClassConstants,
                $storage->protectedClassConstants,
                $storage->privateClassConstants
            );
        }

        throw new \InvalidArgumentException('Must specify $visibility');
    }

    /**
     * @param   string      $className
     * @param   string      $constName
     * @param   Type\Union  $type
     * @param   int         $visibility
     *
     * @return  void
     */
    public function setConstantType(
        $className,
        $constName,
        Type\Union $type,
        $visibility
    ) {
        $storage = $this->classlikeStorageProvider->get($className);

        if ($visibility === ReflectionProperty::IS_PUBLIC) {
            $storage->publicClassConstants[$constName] = $type;
        } elseif ($visibility === ReflectionProperty::IS_PROTECTED) {
            $storage->protectedClassConstants[$constName] = $type;
        } elseif ($visibility === ReflectionProperty::IS_PRIVATE) {
            $storage->privateClassConstants[$constName] = $type;
        }
    }

    /**
     * @return void
     */
    private function checkMethodReferences(ClassLikeStorage $classlikeStorage)
    {
        foreach ($classlikeStorage->appearingMethodIds as $methodName => $appearingMethodId) {
            list($appearingFqClasslikeName) = explode('::', $appearingMethodId);

            if ($appearingFqClasslikeName !== $classlikeStorage->name) {
                continue;
            }

            if (isset($classlikeStorage->methods[$methodName])) {
                $methodStorage = $classlikeStorage->methods[$methodName];
            } else {
                $declaringMethodId = $classlikeStorage->declaringMethodIds[$methodName];

                list($declaringFqClasslikeName) = explode('::', $declaringMethodId);

                try {
                    $declaringClasslikeStorage = $this->classlikeStorageProvider->get($declaringFqClasslikeName);
                } catch (\InvalidArgumentException $e) {
                    continue;
                }

                $methodStorage = $declaringClasslikeStorage->methods[$methodName];
            }

            if (($methodStorage->referencingLocations === null
                    || count($methodStorage->referencingLocations) === 0)
                && (substr($methodName, 0, 2) !== '__' || $methodName === '__construct')
                && $methodStorage->location
            ) {
                $methodLocation = $methodStorage->location;

                $methodId = $classlikeStorage->name . '::' . $methodStorage->casedName;

                if ($methodStorage->visibility === ClassLikeChecker::VISIBILITY_PUBLIC) {
                    $methodNameLc = strtolower($methodName);

                    $hasParentReferences = false;

                    if (isset($classlikeStorage->overriddenMethodIds[$methodNameLc])) {
                        foreach ($classlikeStorage->overriddenMethodIds[$methodNameLc] as $parentMethodId) {
                            $parentMethodStorage = $this->methods->getStorage($parentMethodId);

                            if (!$parentMethodStorage->abstract || $parentMethodStorage->referencingLocations) {
                                $hasParentReferences = true;
                                break;
                            }
                        }
                    }

                    foreach ($classlikeStorage->classImplements as $fqInterfaceName) {
                        $interfaceStorage = $this->classlikeStorageProvider->get($fqInterfaceName);
                        if (isset($interfaceStorage->methods[$methodName])) {
                            $interfaceMethodStorage = $interfaceStorage->methods[$methodName];

                            if ($interfaceMethodStorage->referencingLocations) {
                                $hasParentReferences = true;
                                break;
                            }
                        }
                    }

                    if (!$hasParentReferences) {
                        if (IssueBuffer::accepts(
                            new PossiblyUnusedMethod(
                                'Cannot find public calls to method ' . $methodId,
                                $methodStorage->location,
                                $methodId
                            ),
                            $methodStorage->suppressedIssues
                        )) {
                            // fall through
                        }
                    }
                } elseif (!isset($classlikeStorage->declaringMethodIds['__call'])) {
                    if (IssueBuffer::accepts(
                        new UnusedMethod(
                            'Method ' . $methodId . ' is never used',
                            $methodLocation,
                            $methodId
                        ),
                        $methodStorage->suppressedIssues
                    )) {
                        // fall through
                    }
                }
            } else {
                foreach ($methodStorage->unusedParams as $offset => $codeLocation) {
                    $hasParentReferences = false;

                    $methodNameLc = strtolower($methodName);

                    if (isset($classlikeStorage->overriddenMethodIds[$methodNameLc])) {
                        foreach ($classlikeStorage->overriddenMethodIds[$methodNameLc] as $parentMethodId) {
                            $parentMethodStorage = $this->methods->getStorage($parentMethodId);

                            if (!$parentMethodStorage->abstract
                                && isset($parentMethodStorage->usedParams[$offset])
                            ) {
                                $hasParentReferences = true;
                                break;
                            }
                        }
                    }

                    if (!$hasParentReferences && !isset($methodStorage->usedParams[$offset])) {
                        if (IssueBuffer::accepts(
                            new PossiblyUnusedParam(
                                'Param #' . $offset . ' is never referenced in this method',
                                $codeLocation
                            ),
                            $methodStorage->suppressedIssues
                        )) {
                            // fall through
                        }
                    }
                }
            }
        }

        foreach ($classlikeStorage->properties as $propertyName => $propertyStorage) {
            if (($propertyStorage->referencingLocations === null
                    || count($propertyStorage->referencingLocations) === 0)
                && (substr($propertyName, 0, 2) !== '__' || $propertyName === '__construct')
                && $propertyStorage->location
            ) {
                $propertyId = $classlikeStorage->name . '::$' . $propertyName;

                if ($propertyStorage->visibility === ClassLikeChecker::VISIBILITY_PUBLIC) {
                    if (IssueBuffer::accepts(
                        new PossiblyUnusedProperty(
                            'Cannot find uses of public property ' . $propertyId,
                            $propertyStorage->location
                        ),
                        $classlikeStorage->suppressedIssues
                    )) {
                        // fall through
                    }
                } elseif (!isset($classlikeStorage->declaringMethodIds['__get'])) {
                    if (IssueBuffer::accepts(
                        new UnusedProperty(
                            'Property ' . $propertyId . ' is never used',
                            $propertyStorage->location
                        )
                    )) {
                        // fall through
                    }
                }
            }
        }
    }

    /**
     * @param  string $fqClasslikeNameLc
     *
     * @return void
     */
    public function registerMissingClassLike($fqClasslikeNameLc)
    {
        $this->existingClasslikesLc[$fqClasslikeNameLc] = false;
    }

    /**
     * @param  string $fqClasslikeNameLc
     *
     * @return bool
     */
    public function isMissingClassLike($fqClasslikeNameLc)
    {
        return isset($this->existingClasslikesLc[$fqClasslikeNameLc])
            && $this->existingClasslikesLc[$fqClasslikeNameLc] === false;
    }

    /**
     * @param  string $fqClasslikeNameLc
     *
     * @return bool
     */
    public function doesClassLikeExist($fqClasslikeNameLc)
    {
        return isset($this->existingClasslikesLc[$fqClasslikeNameLc])
            && $this->existingClasslikesLc[$fqClasslikeNameLc];
    }
}
