<?php
namespace Psalm;

use PhpParser;
use Psalm\Provider\ClassLikeStorageProvider;
use Psalm\Provider\FileProvider;
use Psalm\Provider\FileStorageProvider;
use Psalm\Provider\StatementsProvider;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FileStorage;
use Psalm\Storage\FunctionLikeStorage;

class Codebase
{
    /**
     * @var Config
     */
    public $config;

    /**
     * A map of fully-qualified use declarations to the files
     * that reference them (keyed by filename)
     *
     * @var array<string, array<string, array<int, \Psalm\CodeLocation>>>
     */
    public $useReferencingLocations = [];

    /**
     * A map of file names to the classes that they contain explicit references to
     * used in collaboration with use_referencing_locations
     *
     * @var array<string, array<string, bool>>
     */
    public $useReferencingFiles = [];

    /**
     * @var FileStorageProvider
     */
    public $fileStorageProvider;

    /**
     * @var ClassLikeStorageProvider
     */
    public $classlikeStorageProvider;

    /**
     * @var bool
     */
    public $collectReferences = false;

    /**
     * @var FileProvider
     */
    private $fileProvider;

    /**
     * @var StatementsProvider
     */
    public $statementsProvider;

    /**
     * @var bool
     */
    private $debugOutput = false;

    /**
     * @var array<string, Type\Union>
     */
    private static $stubbedConstants = [];

    /**
     * Whether to register autoloaded information
     *
     * @var bool
     */
    public $registerAutoloadFiles = false;

    /**
     * Whether to log functions just at the file level or globally (for stubs)
     *
     * @var bool
     */
    public $registerStubFiles = false;

    /**
     * @var bool
     */
    public $findUnusedCode = false;

    /**
     * @var Codebase\Reflection
     */
    private $reflection;

    /**
     * @var Codebase\Scanner
     */
    public $scanner;

    /**
     * @var Codebase\Analyzer
     */
    public $analyzer;

    /**
     * @var Codebase\Functions
     */
    public $functions;

    /**
     * @var Codebase\ClassLikes
     */
    public $classlikes;

    /**
     * @var Codebase\Methods
     */
    public $methods;

    /**
     * @var Codebase\Properties
     */
    public $properties;

    /**
     * @var Codebase\Populator
     */
    public $populator;

    /**
     * @param bool $debugOutput
     */
    public function __construct(
        Config $config,
        FileStorageProvider $fileStorageProvider,
        ClassLikeStorageProvider $classlikeStorageProvider,
        FileProvider $fileProvider,
        StatementsProvider $statementsProvider,
        $debugOutput = false
    ) {
        $this->config = $config;
        $this->fileStorageProvider = $fileStorageProvider;
        $this->classlikeStorageProvider = $classlikeStorageProvider;
        $this->debugOutput = $debugOutput;
        $this->fileProvider = $fileProvider;
        $this->statementsProvider = $statementsProvider;
        $this->debugOutput = $debugOutput;

        self::$stubbedConstants = [];

        $this->reflection = new Codebase\Reflection($classlikeStorageProvider, $this);

        $this->scanner = new Codebase\Scanner(
            $this,
            $config,
            $fileStorageProvider,
            $fileProvider,
            $this->reflection,
            $debugOutput
        );

        $this->analyzer = new Codebase\Analyzer($config, $fileProvider, $fileStorageProvider, $debugOutput);

        $this->functions = new Codebase\Functions($fileStorageProvider, $this->reflection);
        $this->methods = new Codebase\Methods($config, $classlikeStorageProvider);
        $this->properties = new Codebase\Properties($classlikeStorageProvider);
        $this->classlikes = new Codebase\ClassLikes(
            $config,
            $this,
            $classlikeStorageProvider,
            $this->scanner,
            $this->methods,
            $debugOutput
        );
        $this->populator = new Codebase\Populator(
            $config,
            $classlikeStorageProvider,
            $fileStorageProvider,
            $this->classlikes,
            $debugOutput
        );
    }

    /**
     * @return void
     */
    public function collectReferences()
    {
        $this->collectReferences = true;
        $this->classlikes->collectReferences = true;
        $this->methods->collectReferences = true;
        $this->properties->collectReferences = true;
    }

    /**
     * @return void
     */
    public function reportUnusedCode()
    {
        $this->collectReferences();
        $this->findUnusedCode = true;
    }

    /**
     * @param array<string, string> $filesToAnalyze
     *
     * @return void
     */
    public function addFilesToAnalyze(array $filesToAnalyze)
    {
        $this->scanner->addFilesToDeepScan($filesToAnalyze);
        $this->analyzer->addFiles($filesToAnalyze);
    }

    /**
     * Scans all files their related files
     *
     * @return void
     */
    public function scanFiles()
    {
        $hasChanges = $this->scanner->scanFiles($this->classlikes);

        if ($hasChanges) {
            $this->populator->populateCodebase($this);
        }
    }

    /**
     * @param  string $filePath
     *
     * @return string
     */
    public function getFileContents($filePath)
    {
        return $this->fileProvider->getContents($filePath);
    }

    /**
     * @param  string $filePath
     *
     * @return array<int, PhpParser\Node\Stmt>
     */
    public function getStatementsForFile($filePath)
    {
        return $this->statementsProvider->getStatementsForFile(
            $filePath,
            $this->debugOutput
        );
    }

    /**
     * @param  string $fqClasslikeName
     *
     * @return ClassLikeStorage
     */
    public function createClassLikeStorage($fqClasslikeName)
    {
        return $this->classlikeStorageProvider->create($fqClasslikeName);
    }

    /**
     * @param  string $filePath
     *
     * @return void
     */
    public function cacheClassLikeStorage(ClassLikeStorage $classlikeStorage, $filePath)
    {
        $fileContents = $this->fileProvider->getContents($filePath);
        $this->classlikeStorageProvider->cache->writeToCache($classlikeStorage, $filePath, $fileContents);
    }

    /**
     * @param  string $fqClasslikeName
     * @param  string $filePath
     *
     * @return void
     */
    public function exhumeClassLikeStorage($fqClasslikeName, $filePath)
    {
        $fileContents = $this->fileProvider->getContents($filePath);
        $storage = $this->classlikeStorageProvider->exhume($fqClasslikeName, $filePath, $fileContents);

        if ($storage->isTrait) {
            $this->classlikes->addFullyQualifiedTraitName($fqClasslikeName, $filePath);
        } elseif ($storage->isInterface) {
            $this->classlikes->addFullyQualifiedInterfaceName($fqClasslikeName, $filePath);
        } else {
            $this->classlikes->addFullyQualifiedClassName($fqClasslikeName, $filePath);
        }
    }

    /**
     * @param  string $filePath
     *
     * @return FileStorage
     */
    public function createFileStorageForPath($filePath)
    {
        return $this->fileStorageProvider->create($filePath);
    }

    /**
     * @param  string $symbol
     *
     * @return array<string, \Psalm\CodeLocation[]>
     */
    public function findReferencesToSymbol($symbol)
    {
        if (!$this->collectReferences) {
            throw new \UnexpectedValueException('Should not be checking references');
        }

        if (strpos($symbol, '::') !== false) {
            return $this->findReferencesToMethod($symbol);
        }

        return $this->findReferencesToClassLike($symbol);
    }

    /**
     * @param  string $methodId
     *
     * @return array<string, \Psalm\CodeLocation[]>
     */
    public function findReferencesToMethod($methodId)
    {
        list($fqClassName, $methodName) = explode('::', $methodId);

        try {
            $classStorage = $this->classlikeStorageProvider->get($fqClassName);
        } catch (\InvalidArgumentException $e) {
            die('Class ' . $fqClassName . ' cannot be found' . PHP_EOL);
        }

        $methodNameLc = strtolower($methodName);

        if (!isset($classStorage->methods[$methodNameLc])) {
            die('Method ' . $methodId . ' cannot be found' . PHP_EOL);
        }

        $methodStorage = $classStorage->methods[$methodNameLc];

        if ($methodStorage->referencingLocations === null) {
            die('No references found for ' . $methodId . PHP_EOL);
        }

        return $methodStorage->referencingLocations;
    }

    /**
     * @param  string $fqClassName
     *
     * @return array<string, \Psalm\CodeLocation[]>
     */
    public function findReferencesToClassLike($fqClassName)
    {
        try {
            $classStorage = $this->classlikeStorageProvider->get($fqClassName);
        } catch (\InvalidArgumentException $e) {
            die('Class ' . $fqClassName . ' cannot be found' . PHP_EOL);
        }

        if ($classStorage->referencingLocations === null) {
            die('No references found for ' . $fqClassName . PHP_EOL);
        }

        $classlikeReferencesByFile = $classStorage->referencingLocations;

        $fqClassNameLc = strtolower($fqClassName);

        if (isset($this->useReferencingLocations[$fqClassNameLc])) {
            foreach ($this->useReferencingLocations[$fqClassNameLc] as $filePath => $locations) {
                if (!isset($classlikeReferencesByFile[$filePath])) {
                    $classlikeReferencesByFile[$filePath] = $locations;
                } else {
                    $classlikeReferencesByFile[$filePath] = array_merge(
                        $locations,
                        $classlikeReferencesByFile[$filePath]
                    );
                }
            }
        }

        return $classlikeReferencesByFile;
    }

    /**
     * @param  string $filePath
     * @param  string $closureId
     *
     * @return FunctionLikeStorage
     */
    public function getClosureStorage($filePath, $closureId)
    {
        $fileStorage = $this->fileStorageProvider->get($filePath);

        // closures can be returned here
        if (isset($fileStorage->functions[$closureId])) {
            return $fileStorage->functions[$closureId];
        }

        throw new \UnexpectedValueException(
            'Expecting ' . $closureId . ' to have storage in ' . $filePath
        );
    }

    /**
     * @param  string $constId
     * @param  Type\Union $type
     *
     * @return  void
     */
    public function addGlobalConstantType($constId, Type\Union $type)
    {
        self::$stubbedConstants[$constId] = $type;
    }

    /**
     * @param  string $constId
     *
     * @return Type\Union|null
     */
    public function getStubbedConstantType($constId)
    {
        return isset(self::$stubbedConstants[$constId]) ? self::$stubbedConstants[$constId] : null;
    }

    /**
     * @param  string $filePath
     *
     * @return bool
     */
    public function fileExists($filePath)
    {
        return $this->fileProvider->fileExists($filePath);
    }

    /**
     * Check whether a class/interface exists
     *
     * @param  string          $fqClassName
     * @param  CodeLocation $codeLocation
     *
     * @return bool
     */
    public function classOrInterfaceExists($fqClassName, CodeLocation $codeLocation = null)
    {
        return $this->classlikes->classOrInterfaceExists($fqClassName, $codeLocation);
    }

    /**
     * @param  string       $fqClassName
     * @param  string       $possibleParent
     *
     * @return bool
     */
    public function classExtendsOrImplements($fqClassName, $possibleParent)
    {
        return $this->classlikes->classExtends($fqClassName, $possibleParent)
            || $this->classlikes->classImplements($fqClassName, $possibleParent);
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
        return $this->classlikes->classExists($fqClassName);
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
        return $this->classlikes->classExtends($fqClassName, $possibleParent);
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
        return $this->classlikes->classImplements($fqClassName, $interface);
    }

    /**
     * @param  string         $fqInterfaceName
     *
     * @return bool
     */
    public function interfaceExists($fqInterfaceName)
    {
        return $this->classlikes->interfaceExists($fqInterfaceName);
    }

    /**
     * @param  string         $interfaceName
     * @param  string         $possibleParent
     *
     * @return bool
     */
    public function interfaceExtends($interfaceName, $possibleParent)
    {
        return $this->classlikes->interfaceExtends($interfaceName, $possibleParent);
    }

    /**
     * @param  string         $fqInterfaceName
     *
     * @return array<string>   all interfaces extended by $interfaceName
     */
    public function getParentInterfaces($fqInterfaceName)
    {
        return $this->classlikes->getParentInterfaces($fqInterfaceName);
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
        return $this->classlikes->classHasCorrectCasing($fqClassName);
    }

    /**
     * @param  string $fqInterfaceName
     *
     * @return bool
     */
    public function interfaceHasCorrectCasing($fqInterfaceName)
    {
        return $this->classlikes->interfaceHasCorrectCasing($fqInterfaceName);
    }

    /**
     * @param  string $fqTraitName
     *
     * @return bool
     */
    public function traitHasCorrectCase($fqTraitName)
    {
        return $this->classlikes->traitHasCorrectCase($fqTraitName);
    }

    /**
     * Whether or not a given method exists
     *
     * @param  string       $methodId
     * @param  CodeLocation|null $codeLocation
     *
     * @return bool
     */
    public function methodExists($methodId, CodeLocation $codeLocation = null)
    {
        return $this->methods->methodExists($methodId, $codeLocation);
    }

    /**
     * @param  string $methodId
     *
     * @return array<int, \Psalm\Storage\FunctionLikeParameter>
     */
    public function getMethodParams($methodId)
    {
        return $this->methods->getMethodParams($methodId);
    }

    /**
     * @param  string $methodId
     *
     * @return bool
     */
    public function isVariadic($methodId)
    {
        return $this->methods->isVariadic($methodId);
    }

    /**
     * @param  string $methodId
     * @param  string $selfClass
     *
     * @return Type\Union|null
     */
    public function getMethodReturnType($methodId, &$selfClass)
    {
        return $this->methods->getMethodReturnType($methodId, $selfClass);
    }

    /**
     * @param  string $methodId
     *
     * @return bool
     */
    public function getMethodReturnsByRef($methodId)
    {
        return $this->methods->getMethodReturnsByRef($methodId);
    }

    /**
     * @param  string               $methodId
     * @param  CodeLocation|null    $definedLocation
     *
     * @return CodeLocation|null
     */
    public function getMethodReturnTypeLocation(
        $methodId,
        CodeLocation &$definedLocation = null
    ) {
        return $this->methods->getMethodReturnTypeLocation($methodId, $definedLocation);
    }

    /**
     * @param  string $methodId
     *
     * @return string|null
     */
    public function getDeclaringMethodId($methodId)
    {
        return $this->methods->getDeclaringMethodId($methodId);
    }

    /**
     * Get the class this method appears in (vs is declared in, which could give a trait)
     *
     * @param  string $methodId
     *
     * @return string|null
     */
    public function getAppearingMethodId($methodId)
    {
        return $this->methods->getAppearingMethodId($methodId);
    }

    /**
     * @param  string $methodId
     *
     * @return array<string>
     */
    public function getOverriddenMethodIds($methodId)
    {
        return $this->methods->getOverriddenMethodIds($methodId);
    }

    /**
     * @param  string $methodId
     *
     * @return string
     */
    public function getCasedMethodId($methodId)
    {
        return $this->methods->getCasedMethodId($methodId);
    }
}
