<?php
namespace Psalm;

use Composer\Autoload\ClassLoader;
use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\ProjectChecker;
use Psalm\Config\IssueHandler;
use Psalm\Config\ProjectFileFilter;
use Psalm\Exception\ConfigException;
use Psalm\Scanner\FileScanner;
use SimpleXMLElement;

class Config
{
    const DEFAULT_FILE_NAME = 'psalm.xml';
    const REPORT_INFO = 'info';
    const REPORT_ERROR = 'error';
    const REPORT_SUPPRESS = 'suppress';

    /**
     * @var array<string>
     */
    public static $ERRORLEVELS = [
        self::REPORT_INFO,
        self::REPORT_ERROR,
        self::REPORT_SUPPRESS,
    ];

    /**
     * @var array
     */
    protected static $MIXEDISSUES = [
        'MixedArgument',
        'MixedArrayAccess',
        'MixedArrayAssignment',
        'MixedArrayOffset',
        'MixedAssignment',
        'MixedInferredReturnType',
        'MixedMethodCall',
        'MixedOperand',
        'MixedPropertyFetch',
        'MixedPropertyAssignment',
        'MixedReturnStatement',
        'MixedStringOffsetAssignment',
        'MixedTypeCoercion',
    ];

    /**
     * @var self|null
     */
    private static $instance;

    /**
     * Whether or not to use types as defined in docblocks
     *
     * @var bool
     */
    public $useDocblockTypes = true;

    /**
     * Whether or not to use types as defined in property docblocks.
     * This is distinct from the above because you may want to use
     * property docblocks, but not function docblocks.
     *
     * @var bool
     */
    public $useDocblockPropertyTypes = true;

    /**
     * Whether or not to throw an exception on first error
     *
     * @var bool
     */
    public $throwException = false;

    /**
     * The directory to store PHP Parser (and other) caches
     *
     * @var string
     */
    public $cacheDirectory;

    /**
     * Path to the autoader
     *
     * @var string|null
     */
    public $autoloader;

    /**
     * @var ProjectFileFilter|null
     */
    protected $projectFiles;

    /**
     * The base directory of this config file
     *
     * @var string
     */
    protected $baseDir;

    /**
     * @var array<int, string>
     */
    private $fileExtensions = ['php'];

    /**
     * @var array<string, string>
     */
    private $filetypeScanners = [];

    /**
     * @var array<string, string>
     */
    private $filetypeCheckers = [];

    /**
     * @var array<string, string>
     */
    private $filetypeScannerPaths = [];

    /**
     * @var array<string, string>
     */
    private $filetypeCheckerPaths = [];

    /**
     * @var array<string, IssueHandler>
     */
    private $issueHandlers = [];

    /**
     * @var array<int, string>
     */
    private $mockClasses = [];

    /**
     * @var array<int, string>
     */
    private $stubFiles = [];

    /**
     * @var bool
     */
    public $cacheFileHashesDuringRun = true;

    /**
     * @var bool
     */
    public $hideExternalErrors = true;

    /** @var bool */
    public $allowIncludes = true;

    /** @var bool */
    public $totallyTyped = false;

    /** @var bool */
    public $strictBinaryOperands = false;

    /** @var bool */
    public $addVoidDocblocks = true;

    /**
     * If true, assert() calls can be used to check types of variables
     *
     * @var bool
     */
    public $useAssertForType = true;

    /**
     * @var bool
     */
    public $rememberPropertyAssignmentsAfterCall = true;

    /** @var bool */
    public $useIgbinary = false;

    /**
     * @var bool
     */
    public $allowPhpstormGenerics = false;

    /**
     * @var bool
     */
    public $allowCoercionFromStringToClassConst = true;

    /**
     * @var bool
     */
    public $allowStringStandinForClass = true;

    /**
     * @var bool
     */
    public $usePhpdocMethodsWithoutCall = false;

    /**
     * @var bool
     */
    public $memoizeMethodCalls = false;

    /**
     * @var bool
     */
    public $hoistConstants = false;

    /**
     * @var bool
     */
    public $addParamDefaultToDocblockType = false;

    /**
     * @var bool
     */
    public $checkForThrowsDocblock = false;

    /**
     * @var array<string, bool>
     */
    public $ignoredExceptions = [];

    /**
     * @var string[]
     */
    private $pluginPaths = [];

    /**
     * Static methods to be called after method checks have completed
     *
     * @var string[]
     */
    public $afterMethodChecks = [];

    /**
     * Static methods to be called after function checks have completed
     *
     * @var string[]
     */
    public $afterFunctionChecks = [];

    /**
     * Static methods to be called after expression checks have completed
     *
     * @var string[]
     */
    public $afterExpressionChecks = [];

    /**
     * Static methods to be called after statement checks have completed
     *
     * @var string[]
     */
    public $afterStatementChecks = [];

    /**
     * Static methods to be called after classlike exists checks have completed
     *
     * @var string[]
     */
    public $afterClasslikeExistsChecks = [];

    /**
     * Static methods to be called after classlikes have been scanned
     *
     * @var string[]
     */
    public $afterVisitClasslikes = [];

    /** @var array<string, mixed> */
    private $predefinedConstants;

    /** @var array<string, bool> */
    private $predefinedFunctions = [];

    /** @var ClassLoader|null */
    private $composerClassLoader;

    /**
     * Custom functions that always exit
     *
     * @var array<string, bool>
     */
    public $exitFunctions = [];

    protected function __construct()
    {
        self::$instance = $this;
    }

    /**
     * Gets a Config object from an XML file.
     *
     * Searches up a folder hierarchy for the most immediate config.
     *
     * @param  string $path
     * @param  string $baseDir
     * @param  string $outputFormat
     *
     * @throws ConfigException if a config path is not found
     *
     * @return Config
     * @psalm-suppress MixedArgument
     */
    public static function getConfigForPath($path, $baseDir, $outputFormat)
    {
        $dirPath = realpath($path);

        if ($dirPath === false) {
            throw new ConfigException('Config not found for path ' . $path);
        }

        if (!is_dir($dirPath)) {
            $dirPath = dirname($dirPath);
        }

        $config = null;

        do {
            $maybePath = $dirPath . DIRECTORY_SEPARATOR . Config::DEFAULT_FILE_NAME;

            if (file_exists($maybePath) || file_exists($maybePath .= '.dist')) {
                $config = self::loadFromXMLFile($maybePath, $baseDir);

                break;
            }

            $dirPath = dirname($dirPath);
        } while (dirname($dirPath) !== $dirPath);

        if (!$config) {
            if ($outputFormat === ProjectChecker::TYPE_CONSOLE) {
                exit(
                    'Could not locate a config XML file in path ' . $path . '. Have you run \'psalm --init\' ?' .
                    PHP_EOL
                );
            }

            throw new ConfigException('Config not found for path ' . $path);
        }

        return $config;
    }

    /**
     * Creates a new config object from the file
     *
     * @param  string           $filePath
     * @param  string           $baseDir
     *
     * @return self
     */
    public static function loadFromXMLFile($filePath, $baseDir)
    {
        $fileContents = file_get_contents($filePath);

        if ($fileContents === false) {
            throw new \InvalidArgumentException('Cannot open ' . $filePath);
        }

        try {
            $config = self::loadFromXML($baseDir, $fileContents);
        } catch (ConfigException $e) {
            throw new ConfigException(
                'Problem parsing ' . $filePath . ":\n" . '  ' . $e->getMessage()
            );
        }

        return $config;
    }

    /**
     * Creates a new config object from an XML string
     *
     * @param  string           $baseDir
     * @param  string           $fileContents
     *
     * @return self
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedPropertyFetch
     * @psalm-suppress MixedMethodCall
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedOperand
     * @psalm-suppress MixedPropertyAssignment
     */
    public static function loadFromXML($baseDir, $fileContents)
    {
        $config = new static();

        $config->baseDir = $baseDir;

        $schemaPath = dirname(dirname(__DIR__)) . '/config.xsd';

        if (!file_exists($schemaPath)) {
            throw new ConfigException('Cannot locate config schema');
        }

        $domDocument = new \DOMDocument();
        $domDocument->loadXML($fileContents);

        $psalmNodes = $domDocument->getElementsByTagName('psalm');

        /** @var \DomElement|null */
        $psalmNode = $psalmNodes->item(0);

        if (!$psalmNode) {
            throw new ConfigException(
                'Missing psalm node'
            );
        }

        if (!$psalmNode->hasAttribute('xmlns')) {
            $psalmNode->setAttribute('xmlns', 'https://getpsalm.org/schema/config');

            $oldDomDocument = $domDocument;
            $domDocument = new \DOMDocument();
            $domDocument->loadXML($oldDomDocument->saveXml());
        }

        // Enable user error handling
        libxml_use_internal_errors(true);

        if (!$domDocument->schemaValidate($schemaPath)) {
            $errors = libxml_get_errors();
            foreach ($errors as $error) {
                if ($error->level === LIBXML_ERR_FATAL || $error->level === LIBXML_ERR_ERROR) {
                    throw new ConfigException(
                        'Error on line ' . $error->line . ":\n" . '    ' . $error->message
                    );
                }
            }
            libxml_clear_errors();
        }

        $configXml = new SimpleXMLElement($fileContents);

        if (isset($configXml['useDocblockTypes'])) {
            $attributeText = (string) $configXml['useDocblockTypes'];
            $config->useDocblockTypes = $attributeText === 'true' || $attributeText === '1';
        }

        if (isset($configXml['useDocblockPropertyTypes'])) {
            $attributeText = (string) $configXml['useDocblockPropertyTypes'];
            $config->useDocblockPropertyTypes = $attributeText === 'true' || $attributeText === '1';
        }

        if (isset($configXml['throwExceptionOnError'])) {
            $attributeText = (string) $configXml['throwExceptionOnError'];
            $config->throwException = $attributeText === 'true' || $attributeText === '1';
        }

        if (isset($configXml['hideExternalErrors'])) {
            $attributeText = (string) $configXml['hideExternalErrors'];
            $config->hideExternalErrors = $attributeText === 'true' || $attributeText === '1';
        }

        if (isset($configXml['autoloader'])) {
            $config->autoloader = (string) $configXml['autoloader'];
        }

        if (isset($configXml['cacheDirectory'])) {
            $config->cacheDirectory = (string)$configXml['cacheDirectory'];
        } else {
            $config->cacheDirectory = sys_get_temp_dir() . '/psalm';
        }

        if (@mkdir($config->cacheDirectory, 0777, true) === false && is_dir($config->cacheDirectory) === false) {
            trigger_error('Could not create cache directory: ' . $config->cacheDirectory, E_USER_ERROR);
        }

        if (isset($configXml['allowFileIncludes'])) {
            $attributeText = (string) $configXml['allowFileIncludes'];
            $config->allowIncludes = $attributeText === 'true' || $attributeText === '1';
        }

        if (isset($configXml['totallyTyped'])) {
            $attributeText = (string) $configXml['totallyTyped'];
            $config->totallyTyped = $attributeText === 'true' || $attributeText === '1';
        }

        if (isset($configXml['strictBinaryOperands'])) {
            $attributeText = (string) $configXml['strictBinaryOperands'];
            $config->strictBinaryOperands = $attributeText === 'true' || $attributeText === '1';
        }

        if (isset($configXml['requireVoidReturnType'])) {
            $attributeText = (string) $configXml['requireVoidReturnType'];
            $config->addVoidDocblocks = $attributeText === 'true' || $attributeText === '1';
        }

        if (isset($configXml['useAssertForType'])) {
            $attributeText = (string) $configXml['useAssertForType'];
            $config->useAssertForType = $attributeText === 'true' || $attributeText === '1';
        }

        if (isset($configXml['cacheFileContentHashes'])) {
            $attributeText = (string) $configXml['cacheFileContentHashes'];
            $config->cacheFileHashesDuringRun = $attributeText === 'true' || $attributeText === '1';
        }

        if (isset($configXml['rememberPropertyAssignmentsAfterCall'])) {
            $attributeText = (string) $configXml['rememberPropertyAssignmentsAfterCall'];
            $config->rememberPropertyAssignmentsAfterCall = $attributeText === 'true' || $attributeText === '1';
        }

        if (isset($configXml['serializer'])) {
            $attributeText = (string) $configXml['serializer'];
            $config->useIgbinary = $attributeText === 'igbinary';
        } elseif ($igbinaryVersion = phpversion('igbinary')) {
            $config->useIgbinary = version_compare($igbinaryVersion, '2.0.5') >= 0;
        }

        if (isset($configXml['allowPhpStormGenerics'])) {
            $attributeText = (string) $configXml['allowPhpStormGenerics'];
            $config->allowPhpstormGenerics = $attributeText === 'true' || $attributeText === '1';
        }

        if (isset($configXml['allowCoercionFromStringToClassConst'])) {
            $attributeText = (string) $configXml['allowCoercionFromStringToClassConst'];
            $config->allowCoercionFromStringToClassConst = $attributeText === 'true' || $attributeText === '1';
        }

        if (isset($configXml['allowStringToStandInForClass'])) {
            $attributeText = (string) $configXml['allowCoercionFromStringToClassConst'];
            $config->allowStringStandinForClass = $attributeText === 'true' || $attributeText === '1';
        }

        if (isset($configXml['usePhpDocMethodsWithoutMagicCall'])) {
            $attributeText = (string) $configXml['usePhpDocMethodsWithoutMagicCall'];
            $config->usePhpdocMethodsWithoutCall = $attributeText === 'true' || $attributeText === '1';
        }

        if (isset($configXml['memoizeMethodCallResults'])) {
            $attributeText = (string) $configXml['memoizeMethodCallResults'];
            $config->memoizeMethodCalls = $attributeText === 'true' || $attributeText === '1';
        }

        if (isset($configXml['hoistConstants'])) {
            $attributeText = (string) $configXml['hoistConstants'];
            $config->hoistConstants = $attributeText === 'true' || $attributeText === '1';
        }

        if (isset($configXml['addParamDefaultToDocblockType'])) {
            $attributeText = (string) $configXml['addParamDefaultToDocblockType'];
            $config->addParamDefaultToDocblockType = $attributeText === 'true' || $attributeText === '1';
        }

        if (isset($configXml['checkForThrowsDocblock'])) {
            $attributeText = (string) $configXml['checkForThrowsDocblock'];
            $config->checkForThrowsDocblock = $attributeText === 'true' || $attributeText === '1';
        }

        if (isset($configXml->projectFiles)) {
            $config->projectFiles = ProjectFileFilter::loadFromXMLElement($configXml->projectFiles, $baseDir, true);
        }

        if (isset($configXml->fileExtensions)) {
            $config->fileExtensions = [];

            $config->loadFileExtensions($configXml->fileExtensions->extension);
        }

        if (isset($configXml->mockClasses) && isset($configXml->mockClasses->class)) {
            /** @var \SimpleXMLElement $mockClass */
            foreach ($configXml->mockClasses->class as $mockClass) {
                $config->mockClasses[] = (string)$mockClass['name'];
            }
        }

        if (isset($configXml->ignoreExceptions) && isset($configXml->ignoreExceptions->class)) {
            /** @var \SimpleXMLElement $exceptionClass */
            foreach ($configXml->ignoreExceptions->class as $exceptionClass) {
                $config->ignoredExceptions[(string) $exceptionClass['name']] = true;
            }
        }

        if (isset($configXml->exitFunctions) && isset($configXml->exitFunctions->function)) {
            /** @var \SimpleXMLElement $exitFunction */
            foreach ($configXml->exitFunctions->function as $exitFunction) {
                $config->exitFunctions[strtolower((string) $exitFunction['name'])] = true;
            }
        }

        if (isset($configXml->stubs) && isset($configXml->stubs->file)) {
            /** @var \SimpleXMLElement $stubFile */
            foreach ($configXml->stubs->file as $stubFile) {
                $filePath = realpath($config->baseDir . DIRECTORY_SEPARATOR . $stubFile['name']);

                if (!$filePath) {
                    throw new Exception\ConfigException(
                        'Cannot resolve stubfile path ' . $config->baseDir . DIRECTORY_SEPARATOR . $stubFile['name']
                    );
                }

                $config->stubFiles[] = $filePath;
            }
        }

        // this plugin loading system borrows heavily from etsy/phan
        if (isset($configXml->plugins) && isset($configXml->plugins->plugin)) {
            /** @var \SimpleXMLElement $plugin */
            foreach ($configXml->plugins->plugin as $plugin) {
                $pluginFileName = $plugin['filename'];

                $path = $config->baseDir . $pluginFileName;

                $config->addPluginPath($path);
            }
        }

        if (isset($configXml->issueHandlers)) {
            /** @var \SimpleXMLElement $issueHandler */
            foreach ($configXml->issueHandlers->children() as $key => $issueHandler) {
                /** @var string $key */
                $config->issueHandlers[$key] = IssueHandler::loadFromXMLElement($issueHandler, $baseDir);
            }
        }

        return $config;
    }

    /**
     * @param  string $autoloaderPath
     *
     * @return void
     *
     * @psalm-suppress UnresolvableInclude
     */
    private function requireAutoloader($autoloaderPath)
    {
        require_once($autoloaderPath);
    }

    /**
     * @return $this
     */
    public static function getInstance()
    {
        if (self::$instance) {
            return self::$instance;
        }

        throw new \UnexpectedValueException('No config initialized');
    }

    /**
     * @return void
     */
    public function setComposerClassLoader(ClassLoader $loader)
    {
        $this->composerClassLoader = $loader;
    }

    /**
     * @param string $issueKey
     * @param string $errorLevel
     *
     * @return void
     */
    public function setCustomErrorLevel($issueKey, $errorLevel)
    {
        $this->issueHandlers[$issueKey] = new IssueHandler();
        $this->issueHandlers[$issueKey]->setErrorLevel($errorLevel);
    }

    /**
     * @param  array<SimpleXMLElement> $extensions
     *
     * @throws ConfigException if a Config file could not be found
     *
     * @return void
     */
    private function loadFileExtensions($extensions)
    {
        foreach ($extensions as $extension) {
            $extensionName = preg_replace('/^\.?/', '', (string)$extension['name']);
            $this->fileExtensions[] = $extensionName;

            if (isset($extension['scanner'])) {
                $path = $this->baseDir . (string)$extension['scanner'];

                if (!file_exists($path)) {
                    throw new Exception\ConfigException('Error parsing config: cannot find file ' . $path);
                }

                $this->filetypeScannerPaths[$extensionName] = $path;
            }

            if (isset($extension['checker'])) {
                $path = $this->baseDir . (string)$extension['checker'];

                if (!file_exists($path)) {
                    throw new Exception\ConfigException('Error parsing config: cannot find file ' . $path);
                }

                $this->filetypeCheckerPaths[$extensionName] = $path;
            }
        }
    }

    /**
     * @param string $path
     *
     * @return void
     */
    public function addPluginPath($path)
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException('Cannot find plugin file ' . $path);
        }

        $this->pluginPaths[] = $path;
    }

    /**
     * Initialises all the plugins (done once the config is fully loaded)
     *
     * @return void
     * @psalm-suppress MixedArrayAccess
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedOperand
     * @psalm-suppress MixedArrayOffset
     * @psalm-suppress MixedTypeCoercion
     */
    public function initializePlugins(ProjectChecker $projectChecker)
    {
        $codebase = $projectChecker->codebase;

        foreach ($this->filetypeScannerPaths as $extension => $path) {
            $fqClassName = $this->getPluginClassForPath($projectChecker, $path, 'Psalm\\Scanner\\FileScanner');

            $this->filetypeScanners[$extension] = $fqClassName;

            /** @psalm-suppress UnresolvableInclude */
            require_once($path);
        }

        foreach ($this->filetypeCheckerPaths as $extension => $path) {
            $fqClassName = $this->getPluginClassForPath($projectChecker, $path, 'Psalm\\Checker\\FileChecker');

            $this->filetypeCheckers[$extension] = $fqClassName;

            /** @psalm-suppress UnresolvableInclude */
            require_once($path);
        }

        foreach ($this->pluginPaths as $path) {
            $fqClassName = $this->getPluginClassForPath($projectChecker, $path, 'Psalm\\Plugin');

            /** @psalm-suppress UnresolvableInclude */
            require_once($path);

            if ($codebase->methods->methodExists($fqClassName . '::afterMethodCallCheck')) {
                $this->afterMethodChecks[$fqClassName] = $fqClassName;
            }

            if ($codebase->methods->methodExists($fqClassName . '::afterFunctionCallCheck')) {
                $this->afterFunctionChecks[$fqClassName] = $fqClassName;
            }

            if ($codebase->methods->methodExists($fqClassName . '::afterExpressionCheck')) {
                $this->afterExpressionChecks[$fqClassName] = $fqClassName;
            }

            if ($codebase->methods->methodExists($fqClassName . '::afterStatementCheck')) {
                $this->afterStatementChecks[$fqClassName] = $fqClassName;
            }

            if ($codebase->methods->methodExists($fqClassName . '::afterClassLikeExistsCheck')) {
                $this->afterClasslikeExistsChecks[$fqClassName] = $fqClassName;
            }

            if ($codebase->methods->methodExists($fqClassName . '::afterVisitClassLike')) {
                $this->afterVisitClasslikes[$fqClassName] = $fqClassName;
            }
        }
    }

    /**
     * @param  string $path
     * @param  string $mustExtend
     *
     * @return string
     */
    private function getPluginClassForPath(ProjectChecker $projectChecker, $path, $mustExtend)
    {
        $codebase = $projectChecker->codebase;

        $fileStorage = $codebase->createFileStorageForPath($path);
        $fileToScan = new FileScanner($path, $this->shortenFileName($path), true);
        $fileToScan->scan(
            $codebase,
            $fileStorage
        );

        $declaredClasses = ClassLikeChecker::getClassesForFile($projectChecker, $path);

        if (count($declaredClasses) !== 1) {
            throw new \InvalidArgumentException(
                'Plugins must have exactly one class in the file - ' . $path . ' has ' .
                    count($declaredClasses)
            );
        }

        $fqClassName = reset($declaredClasses);

        if (!$codebase->classExtends(
            $fqClassName,
            $mustExtend
        )
        ) {
            throw new \InvalidArgumentException(
                'This plugin must extend ' . $mustExtend . ' - ' . $path . ' does not'
            );
        }

        return $fqClassName;
    }

    /**
     * @param  string $fileName
     *
     * @return string
     */
    public function shortenFileName($fileName)
    {
        return preg_replace('/^' . preg_quote($this->baseDir, '/') . '/', '', $fileName);
    }

    /**
     * @param   string $issueType
     * @param   string $filePath
     *
     * @return  bool
     */
    public function reportIssueInFile($issueType, $filePath)
    {
        if (!$this->totallyTyped && in_array($issueType, self::$MIXEDISSUES, true)) {
            return false;
        }

        if ($this->hideExternalErrors) {
            if ($this->mustBeIgnored($filePath)) {
                return false;
            }

            $codebase = ProjectChecker::getInstance()->codebase;

            $dependentFiles = [strtolower($filePath) => $filePath];

            try {
                $fileStorage = $codebase->fileStorageProvider->get($filePath);
                $dependentFiles += $fileStorage->requiredByFilePaths;
            } catch (\InvalidArgumentException $e) {
                // do nothing
            }

            $anyFilePathMatched = false;

            foreach ($dependentFiles as $dependentFilePath) {
                if ($codebase->analyzer->canReportIssues($dependentFilePath)
                    && !$this->mustBeIgnored($dependentFilePath)
                ) {
                    $anyFilePathMatched = true;
                    break;
                }
            }

            if (!$anyFilePathMatched) {
                return false;
            }
        }

        if ($this->getReportingLevelForFile($issueType, $filePath) === self::REPORT_SUPPRESS) {
            return false;
        }

        return true;
    }

    /**
     * @param   string $filePath
     *
     * @return  bool
     */
    public function isInProjectDirs($filePath)
    {
        return $this->projectFiles && $this->projectFiles->allows($filePath);
    }

    /**
     * @param   string $filePath
     *
     * @return  bool
     */
    public function mustBeIgnored($filePath)
    {
        return $this->projectFiles && $this->projectFiles->forbids($filePath);
    }

    /**
     * @param   string $issueType
     * @param   string $filePath
     *
     * @return  string
     */
    public function getReportingLevelForFile($issueType, $filePath)
    {
        if (isset($this->issueHandlers[$issueType])) {
            return $this->issueHandlers[$issueType]->getReportingLevelForFile($filePath);
        }

        return self::REPORT_ERROR;
    }

    /**
     * @param   string $issueType
     * @param   string $fqClasslikeName
     *
     * @return  string
     */
    public function getReportingLevelForClass($issueType, $fqClasslikeName)
    {
        if (isset($this->issueHandlers[$issueType])) {
            return $this->issueHandlers[$issueType]->getReportingLevelForClass($fqClasslikeName);
        }

        return self::REPORT_ERROR;
    }

    /**
     * @param   string $issueType
     * @param   string $methodId
     *
     * @return  string
     */
    public function getReportingLevelForMethod($issueType, $methodId)
    {
        if (isset($this->issueHandlers[$issueType])) {
            return $this->issueHandlers[$issueType]->getReportingLevelForMethod($methodId);
        }

        return self::REPORT_ERROR;
    }

    /**
     * @param   string $issueType
     * @param   string $propertyId
     *
     * @return  string
     */
    public function getReportingLevelForProperty($issueType, $propertyId)
    {
        if (isset($this->issueHandlers[$issueType])) {
            return $this->issueHandlers[$issueType]->getReportingLevelForProperty($propertyId);
        }

        return self::REPORT_ERROR;
    }

    /**
     * @return array<string>
     */
    public function getProjectDirectories()
    {
        if (!$this->projectFiles) {
            return [];
        }

        return $this->projectFiles->getDirectories();
    }

    /**
     * @return array<string>
     */
    public function getProjectFiles()
    {
        if (!$this->projectFiles) {
            return [];
        }

        return $this->projectFiles->getFiles();
    }

    /**
     * @param   string $filePath
     *
     * @return  bool
     */
    public function reportTypeStatsForFile($filePath)
    {
        return $this->projectFiles && $this->projectFiles->reportTypeStats($filePath);
    }

    /**
     * @return array<string>
     */
    public function getFileExtensions()
    {
        return $this->fileExtensions;
    }

    /**
     * @return array<string, string>
     */
    public function getFiletypeScanners()
    {
        return $this->filetypeScanners;
    }

    /**
     * @return array<string, string>
     */
    public function getFiletypeCheckers()
    {
        return $this->filetypeCheckers;
    }

    /**
     * @return array<int, string>
     */
    public function getMockClasses()
    {
        return $this->mockClasses;
    }

    /**
     * @param bool $debug
     *
     * @return void
     */
    public function visitStubFiles(Codebase $codebase, $debug = false)
    {
        $codebase->registerStubFiles = true;

        // note: don't realpath $genericStubsPath, or phar version will fail
        $genericStubsPath = __DIR__ . '/Stubs/CoreGenericFunctions.php';

        if (!file_exists($genericStubsPath)) {
            throw new \UnexpectedValueException('Cannot locate core generic stubs');
        }

        // note: don't realpath $genericClassesPath, or phar version will fail
        $genericClassesPath = __DIR__ . '/Stubs/CoreGenericClasses.php';

        if (!file_exists($genericClassesPath)) {
            throw new \UnexpectedValueException('Cannot locate core generic classes');
        }

        $stubFiles = array_merge([$genericStubsPath, $genericClassesPath], $this->stubFiles);

        foreach ($stubFiles as $filePath) {
            $codebase->scanner->addFileToShallowScan($filePath);
        }

        if ($debug) {
            echo 'Registering stub files' . "\n";
        }

        $codebase->scanFiles();

        if ($debug) {
            echo 'Finished registering stub files' . "\n";
        }

        $codebase->registerStubFiles = false;
    }

    /**
     * @return string
     */
    public function getCacheDirectory()
    {
        return $this->cacheDirectory;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPredefinedConstants()
    {
        return $this->predefinedConstants;
    }

    /**
     * @return void
     * @psalm-suppress MixedTypeCoercion
     */
    public function collectPredefinedConstants()
    {
        $this->predefinedConstants = get_defined_constants();
    }

    /**
     * @return array<string, bool>
     */
    public function getPredefinedFunctions()
    {
        return $this->predefinedFunctions;
    }

    /**
     * @return void
     * @psalm-suppress InvalidPropertyAssignment
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArrayOffset
     */
    public function collectPredefinedFunctions()
    {
        $definedFunctions = get_defined_functions();

        if (isset($definedFunctions['user'])) {
            foreach ($definedFunctions['user'] as $functionName) {
                $this->predefinedFunctions[$functionName] = true;
            }
        }

        if (isset($definedFunctions['internal'])) {
            foreach ($definedFunctions['internal'] as $functionName) {
                $this->predefinedFunctions[$functionName] = true;
            }
        }
    }

    /**
     * @param bool $debug
     *
     * @return void
     *
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArrayAccess
     */
    public function visitComposerAutoloadFiles(ProjectChecker $projectChecker, $debug = false)
    {
        $this->collectPredefinedConstants();
        $this->collectPredefinedFunctions();

        $composerJsonPath = $this->baseDir . 'composer.json'; // this should ideally not be hardcoded

        $autoloadFilesFiles = [];

        if ($this->autoloader) {
            $autoloadFilesFiles[] = $this->autoloader;
        }

        if (file_exists($composerJsonPath)) {
            /** @psalm-suppress PossiblyFalseArgument */
            if (!$composerJson = json_decode(file_get_contents($composerJsonPath), true)) {
                throw new \UnexpectedValueException('Invalid composer.json at ' . $composerJsonPath);
            }

            if (isset($composerJson['autoload']['files'])) {
                /** @var string[] */
                $composerAutoloadFiles = $composerJson['autoload']['files'];

                foreach ($composerAutoloadFiles as $file) {
                    $filePath = realpath($this->baseDir . $file);

                    if ($filePath && file_exists($filePath)) {
                        $autoloadFilesFiles[] = $filePath;
                    }
                }
            }

            $vendorAutoloadFilesPath
                = $this->baseDir . DIRECTORY_SEPARATOR . 'vendor'
                    . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'autoload_files.php';

            if (file_exists($vendorAutoloadFilesPath)) {
                /**
                 * @var string[]
                 * @psalm-suppress UnresolvableInclude
                 */
                $vendorAutoloadFiles = require $vendorAutoloadFilesPath;

                $autoloadFilesFiles = array_merge($autoloadFilesFiles, $vendorAutoloadFiles);
            }
        }

        $autoloadFilesFiles = array_unique($autoloadFilesFiles);

        if ($autoloadFilesFiles) {
            $codebase = $projectChecker->codebase;
            $codebase->registerAutoloadFiles = true;

            foreach ($autoloadFilesFiles as $filePath) {
                $codebase->scanner->addFileToDeepScan($filePath);
            }

            if ($debug) {
                echo 'Registering autoloaded files' . "\n";
            }

            $codebase->scanner->scanFiles($codebase->classlikes);

            if ($debug) {
                echo 'Finished registering autoloaded files' . "\n";
            }

            $projectChecker->codebase->registerAutoloadFiles = false;
        }

        if ($this->autoloader) {
            // do this in a separate method so scope does not leak
            $this->requireAutoloader($this->baseDir . DIRECTORY_SEPARATOR . $this->autoloader);

            $this->collectPredefinedConstants();
            $this->collectPredefinedFunctions();
        }
    }

    /**
     * @param  string $fqClasslikeName
     *
     * @return string|false
     */
    public function getComposerFilePathForClassLike($fqClasslikeName)
    {
        if (!$this->composerClassLoader) {
            return false;
        }

        return $this->composerClassLoader->findFile($fqClasslikeName);
    }

    /**
     * @param string $dir
     *
     * @return void
     */
    public static function removeCacheDirectory($dir)
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);

            if ($objects === false) {
                throw new \UnexpectedValueException('Not expecting false here');
            }

            foreach ($objects as $object) {
                if ($object != '.' && $object != '..') {
                    if (filetype($dir . '/' . $object) == 'dir') {
                        self::removeCacheDirectory($dir . '/' . $object);
                    } else {
                        unlink($dir . '/' . $object);
                    }
                }
            }

            reset($objects);
            rmdir($dir);
        }
    }
}
