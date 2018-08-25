<?php
namespace Psalm\Checker;

use Psalm\Codebase;
use Psalm\Config;
use Psalm\Context;
use Psalm\Provider\ClassLikeStorageCacheProvider;
use Psalm\Provider\ClassLikeStorageProvider;
use Psalm\Provider\FileProvider;
use Psalm\Provider\FileReferenceProvider;
use Psalm\Provider\FileStorageCacheProvider;
use Psalm\Provider\FileStorageProvider;
use Psalm\Provider\ParserCacheProvider;
use Psalm\Provider\StatementsProvider;
use Psalm\Type;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ProjectChecker
{
    /**
     * Cached config
     *
     * @var Config
     */
    public $config;

    /**
     * @var self
     */
    public static $instance;

    /**
     * An object representing everything we know about the code
     *
     * @var Codebase
     */
    public $codebase;

    /** @var FileProvider */
    private $fileProvider;

    /** @var FileStorageProvider */
    public $fileStorageProvider;

    /** @var ClassLikeStorageProvider */
    public $classlikeStorageProvider;

    /** @var ParserCacheProvider */
    public $cacheProvider;

    /**
     * Whether or not to use colors in error output
     *
     * @var bool
     */
    public $useColor;

    /**
     * Whether or not to show snippets in error output
     *
     * @var bool
     */
    public $showSnippet;

    /**
     * Whether or not to show informational messages
     *
     * @var bool
     */
    public $showInfo;

    /**
     * @var string
     */
    public $outputFormat;

    /**
     * @var bool
     */
    public $debugOutput = false;

    /**
     * @var bool
     */
    public $debugLines = false;

    /**
     * @var bool
     */
    public $alterCode = false;

    /**
     * @var bool
     */
    public $showIssues = true;

    /** @var int */
    public $threads;

    /**
     * Whether or not to infer types from usage. Computationally expensive, so turned off by default
     *
     * @var bool
     */
    public $inferTypesFromUsage = false;

    /**
     * @var array<string,string>
     */
    public $reports = [];

    /**
     * @var array<string, bool>
     */
    private $issuesToFix = [];

    /**
     * @var int
     */
    public $phpMajorVersion = PHP_MAJOR_VERSION;

    /**
     * @var int
     */
    public $phpMinorVersion = PHP_MINOR_VERSION;

    /**
     * @var bool
     */
    public $dryRun = false;

    /**
     * @var bool
     */
    public $onlyReplacePhpTypesWithNonDocblockTypes = false;

    const TYPE_CONSOLE = 'console';
    const TYPE_PYLINT = 'pylint';
    const TYPE_JSON = 'json';
    const TYPE_EMACS = 'emacs';
    const TYPE_XML = 'xml';

    const SUPPORTED_OUTPUT_TYPES = [
        self::TYPE_CONSOLE,
        self::TYPE_PYLINT,
        self::TYPE_JSON,
        self::TYPE_EMACS,
        self::TYPE_XML,
    ];

    /**
     * @param FileProvider  $fileProvider
     * @param ParserCacheProvider $cacheProvider
     * @param bool          $useColor
     * @param bool          $showInfo
     * @param string        $outputFormat
     * @param int           $threads
     * @param bool          $debugOutput
     * @param string        $reports
     * @param bool          $showSnippet
     */
    public function __construct(
        Config $config,
        FileProvider $fileProvider,
        ParserCacheProvider $cacheProvider,
        FileStorageCacheProvider $fileStorageCacheProvider,
        ClassLikeStorageCacheProvider $classlikeStorageCacheProvider,
        $useColor = true,
        $showInfo = true,
        $outputFormat = self::TYPE_CONSOLE,
        $threads = 1,
        $debugOutput = false,
        $reports = null,
        $showSnippet = true
    ) {
        $this->fileProvider = $fileProvider;
        $this->cacheProvider = $cacheProvider;
        $this->useColor = $useColor;
        $this->showInfo = $showInfo;
        $this->debugOutput = $debugOutput;
        $this->threads = $threads;
        $this->config = $config;
        $this->showSnippet = $showSnippet;

        $this->fileStorageProvider = new FileStorageProvider($fileStorageCacheProvider);
        $this->classlikeStorageProvider = new ClassLikeStorageProvider($classlikeStorageCacheProvider);

        $statementsProvider = new StatementsProvider(
            $fileProvider,
            $cacheProvider,
            $fileStorageCacheProvider
        );

        $this->codebase = new Codebase(
            $config,
            $this->fileStorageProvider,
            $this->classlikeStorageProvider,
            $fileProvider,
            $statementsProvider,
            $debugOutput
        );

        if (!in_array($outputFormat, self::SUPPORTED_OUTPUT_TYPES, true)) {
            throw new \UnexpectedValueException('Unrecognised output format ' . $outputFormat);
        }

        if ($reports) {
            /**
             * @var array<string,string>
             */
            $mapping = [
                '.xml' => self::TYPE_XML,
                '.json' => self::TYPE_JSON,
                '.txt' => self::TYPE_EMACS,
                '.emacs' => self::TYPE_EMACS,
                '.pylint' => self::TYPE_PYLINT,
            ];
            foreach ($mapping as $extension => $type) {
                if (substr($reports, -strlen($extension)) === $extension) {
                    $this->reports[$type] = $reports;
                    break;
                }
            }
            if (empty($this->reports)) {
                throw new \UnexpectedValueException('Unrecognised report format ' . $reports);
            }
        }

        $this->outputFormat = $outputFormat;
        self::$instance = $this;

        $this->cacheProvider->useIgbinary = $config->useIgbinary;
    }

    /**
     * @return ProjectChecker
     */
    public static function getInstance()
    {
        return self::$instance;
    }

    /**
     * @param  string  $baseDir
     * @param  bool $isDiff
     *
     * @return void
     */
    public function check($baseDir, $isDiff = false)
    {
        $startChecks = (int)microtime(true);

        if (!$baseDir) {
            throw new \InvalidArgumentException('Cannot work with empty base_dir');
        }

        $diffFiles = null;
        $deletedFiles = null;

        if ($isDiff && FileReferenceProvider::loadReferenceCache() && $this->cacheProvider->canDiffFiles()) {
            $deletedFiles = FileReferenceProvider::getDeletedReferencedFiles();
            $diffFiles = $deletedFiles;

            foreach ($this->config->getProjectDirectories() as $dirName) {
                $diffFiles = array_merge($diffFiles, $this->getDiffFilesInDir($dirName, $this->config));
            }
        }

        if ($this->outputFormat === self::TYPE_CONSOLE) {
            echo 'Scanning files...' . "\n";
        }

        if ($diffFiles === null || $deletedFiles === null || count($diffFiles) > 200) {
            foreach ($this->config->getProjectDirectories() as $dirName) {
                $this->checkDirWithConfig($dirName, $this->config);
            }

            foreach ($this->config->getProjectFiles() as $filePath) {
                $this->codebase->addFilesToAnalyze([$filePath => $filePath]);
            }

            $this->config->initializePlugins($this);

            $this->codebase->scanFiles();
        } else {
            if ($this->debugOutput) {
                echo count($diffFiles) . ' changed files' . "\n";
            }

            if ($diffFiles) {
                $fileList = self::getReferencedFilesFromDiff($diffFiles);

                // strip out deleted files
                $fileList = array_diff($fileList, $deletedFiles);

                $this->checkDiffFilesWithConfig($this->config, $fileList);

                $this->config->initializePlugins($this);

                $this->codebase->scanFiles();
            }
        }

        if ($this->outputFormat === self::TYPE_CONSOLE) {
            echo 'Analyzing files...' . "\n";
        }

        $this->config->visitStubFiles($this->codebase, $this->debugOutput);

        $this->codebase->analyzer->analyzeFiles($this, $this->threads, $this->alterCode);

        $removedParserFiles = $this->cacheProvider->deleteOldParserCaches(
            $isDiff ? $this->cacheProvider->getLastGoodRun() : $startChecks
        );

        if ($this->debugOutput && $removedParserFiles) {
            echo 'Removed ' . $removedParserFiles . ' old parser caches' . "\n";
        }

        if ($isDiff) {
            $this->cacheProvider->touchParserCaches($this->getAllFiles($this->config), $startChecks);
        }
    }

    /**
     * @return void
     */
    public function checkClassReferences()
    {
        if (!$this->codebase->collectReferences) {
            throw new \UnexpectedValueException('Should not be checking references');
        }

        $this->codebase->classlikes->checkClassReferences();
    }

    /**
     * @param  string $symbol
     *
     * @return void
     */
    public function findReferencesTo($symbol)
    {
        $locationsByFiles = $this->codebase->findReferencesToSymbol($symbol);

        foreach ($locationsByFiles as $locations) {
            $boundsStarts = [];

            foreach ($locations as $location) {
                $snippet = $location->getSnippet();

                $snippetBounds = $location->getSnippetBounds();
                $selectionBounds = $location->getSelectionBounds();

                if (isset($boundsStarts[$selectionBounds[0]])) {
                    continue;
                }

                $boundsStarts[$selectionBounds[0]] = true;

                $selectionStart = $selectionBounds[0] - $snippetBounds[0];
                $selectionLength = $selectionBounds[1] - $selectionBounds[0];

                echo $location->fileName . ':' . $location->getLineNumber() . "\n" .
                    (
                        $this->useColor
                        ? substr($snippet, 0, $selectionStart) .
                        "\e[97;42m" . substr($snippet, $selectionStart, $selectionLength) .
                        "\e[0m" . substr($snippet, $selectionLength + $selectionStart)
                        : $snippet
                    ) . "\n" . "\n";
            }
        }
    }

    /**
     * @param  string  $dirName
     *
     * @return void
     */
    public function checkDir($dirName)
    {
        FileReferenceProvider::loadReferenceCache();

        $this->checkDirWithConfig($dirName, $this->config, true);

        if ($this->outputFormat === self::TYPE_CONSOLE) {
            echo 'Scanning files...' . "\n";
        }

        $this->config->initializePlugins($this);

        $this->codebase->scanFiles();

        $this->config->visitStubFiles($this->codebase, $this->debugOutput);

        if ($this->outputFormat === self::TYPE_CONSOLE) {
            echo 'Analyzing files...' . "\n";
        }

        $this->codebase->analyzer->analyzeFiles($this, $this->threads, $this->alterCode);
    }

    /**
     * @param  string $dirName
     * @param  Config $config
     * @param  bool   $allowNonProjectFiles
     *
     * @return void
     */
    private function checkDirWithConfig($dirName, Config $config, $allowNonProjectFiles = false)
    {
        $fileExtensions = $config->getFileExtensions();

        /** @var RecursiveDirectoryIterator */
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dirName));
        $iterator->rewind();

        $filesToScan = [];

        while ($iterator->valid()) {
            if (!$iterator->isDot()) {
                $extension = $iterator->getExtension();
                if (in_array($extension, $fileExtensions, true)) {
                    $filePath = (string)$iterator->getRealPath();

                    if ($allowNonProjectFiles || $config->isInProjectDirs($filePath)) {
                        $filesToScan[$filePath] = $filePath;
                    }
                }
            }

            $iterator->next();
        }

        $this->codebase->addFilesToAnalyze($filesToScan);
    }

    /**
     * @param  Config $config
     *
     * @return array<int, string>
     */
    private function getAllFiles(Config $config)
    {
        $fileExtensions = $config->getFileExtensions();
        $fileNames = [];

        foreach ($config->getProjectDirectories() as $dirName) {
            /** @var RecursiveDirectoryIterator */
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dirName));
            $iterator->rewind();

            while ($iterator->valid()) {
                if (!$iterator->isDot()) {
                    $extension = $iterator->getExtension();
                    if (in_array($extension, $fileExtensions, true)) {
                        $fileNames[] = (string)$iterator->getRealPath();
                    }
                }

                $iterator->next();
            }
        }

        return $fileNames;
    }

    /**
     * @param  string $dirName
     * @param  Config $config
     *
     * @return array<string>
     */
    protected function getDiffFilesInDir($dirName, Config $config)
    {
        $fileExtensions = $config->getFileExtensions();

        /** @var RecursiveDirectoryIterator */
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dirName));
        $iterator->rewind();

        $diffFiles = [];

        while ($iterator->valid()) {
            if (!$iterator->isDot()) {
                $extension = $iterator->getExtension();
                if (in_array($extension, $fileExtensions, true)) {
                    $filePath = (string)$iterator->getRealPath();

                    if ($config->isInProjectDirs($filePath)) {
                        if ($this->fileProvider->getModifiedTime($filePath) > $this->cacheProvider->getLastGoodRun()
                        ) {
                            $diffFiles[] = $filePath;
                        }
                    }
                }
            }

            $iterator->next();
        }

        return $diffFiles;
    }

    /**
     * @param  Config           $config
     * @param  array<string>    $fileList
     *
     * @return void
     */
    private function checkDiffFilesWithConfig(Config $config, array $fileList = [])
    {
        $filesToScan = [];

        foreach ($fileList as $filePath) {
            if (!file_exists($filePath)) {
                continue;
            }

            if (!$config->isInProjectDirs($filePath)) {
                if ($this->debugOutput) {
                    echo 'skipping ' . $filePath . "\n";
                }

                continue;
            }

            $filesToScan[$filePath] = $filePath;
        }

        $this->codebase->addFilesToAnalyze($filesToScan);
    }

    /**
     * @param  string  $filePath
     *
     * @return void
     */
    public function checkFile($filePath)
    {
        if ($this->debugOutput) {
            echo 'Checking ' . $filePath . "\n";
        }

        $this->config->hideExternalErrors = $this->config->isInProjectDirs($filePath);

        $this->codebase->addFilesToAnalyze([$filePath => $filePath]);

        FileReferenceProvider::loadReferenceCache();

        if ($this->outputFormat === self::TYPE_CONSOLE) {
            echo 'Scanning files...' . "\n";
        }

        $this->config->initializePlugins($this);

        $this->codebase->scanFiles();

        $this->config->visitStubFiles($this->codebase, $this->debugOutput);

        if ($this->outputFormat === self::TYPE_CONSOLE) {
            echo 'Analyzing files...' . "\n";
        }

        $this->codebase->analyzer->analyzeFiles($this, $this->threads, $this->alterCode);
    }

    /**
     * @param string[] $pathsToCheck
     * @return void
     */
    public function checkPaths(array $pathsToCheck)
    {
        foreach ($pathsToCheck as $path) {
            if ($this->debugOutput) {
                echo 'Checking ' . $path . "\n";
            }

            if (is_dir($path)) {
                $this->checkDirWithConfig($path, $this->config, true);
            } elseif (is_file($path)) {
                $this->codebase->addFilesToAnalyze([$path => $path]);
                $this->config->hideExternalErrors = $this->config->isInProjectDirs($path);
            }
        }

        FileReferenceProvider::loadReferenceCache();

        if ($this->outputFormat === self::TYPE_CONSOLE) {
            echo 'Scanning files...' . "\n";
        }

        $this->config->initializePlugins($this);

        $this->codebase->scanFiles();

        $this->config->visitStubFiles($this->codebase, $this->debugOutput);

        if ($this->outputFormat === self::TYPE_CONSOLE) {
            echo 'Analyzing files...' . "\n";
        }

        $this->codebase->analyzer->analyzeFiles($this, $this->threads, $this->alterCode);
    }

    /**
     * @return Config
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @param  array<string>  $diffFiles
     *
     * @return array<string>
     */
    public static function getReferencedFilesFromDiff(array $diffFiles)
    {
        $allInheritedFilesToCheck = $diffFiles;

        while ($diffFiles) {
            $diffFile = array_shift($diffFiles);

            $dependentFiles = FileReferenceProvider::getFilesInheritingFromFile($diffFile);
            $newDependentFiles = array_diff($dependentFiles, $allInheritedFilesToCheck);

            $allInheritedFilesToCheck += $newDependentFiles;
            $diffFiles += $newDependentFiles;
        }

        $allFilesToCheck = $allInheritedFilesToCheck;

        foreach ($allInheritedFilesToCheck as $fileName) {
            $dependentFiles = FileReferenceProvider::getFilesReferencingFile($fileName);
            $allFilesToCheck = array_merge($dependentFiles, $allFilesToCheck);
        }

        return array_unique($allFilesToCheck);
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
     * @param int $phpMajorVersion
     * @param int $phpMinorVersion
     * @param bool $dryRun
     * @param bool $safeTypes
     *
     * @return void
     */
    public function alterCodeAfterCompletion(
        $phpMajorVersion,
        $phpMinorVersion,
        $dryRun = false,
        $safeTypes = false
    ) {
        $this->alterCode = true;
        $this->showIssues = false;
        $this->phpMajorVersion = $phpMajorVersion;
        $this->phpMinorVersion = $phpMinorVersion;
        $this->dryRun = $dryRun;
        $this->onlyReplacePhpTypesWithNonDocblockTypes = $safeTypes;
    }

    /**
     * @param array<string, bool> $issues
     *
     * @return void
     */
    public function setIssuesToFix(array $issues)
    {
        $this->issuesToFix = $issues;
    }

    /**
     * @return array<string, bool>
     *
     * @psalm-suppress PossiblyUnusedMethod - need to fix #422
     */
    public function getIssuesToFix()
    {
        return $this->issuesToFix;
    }

    /**
     * @return Codebase
     */
    public function getCodebase()
    {
        return $this->codebase;
    }

    /**
     * @param  string $fqClassName
     *
     * @return FileChecker
     */
    public function getFileCheckerForClassLike($fqClassName)
    {
        $fqClassNameLc = strtolower($fqClassName);

        $filePath = $this->codebase->scanner->getClassLikeFilePath($fqClassNameLc);

        $fileChecker = new FileChecker(
            $this,
            $filePath,
            $this->config->shortenFileName($filePath)
        );

        return $fileChecker;
    }

    /**
     * @param  string   $originalMethodId
     * @param  Context  $thisContext
     *
     * @return void
     */
    public function getMethodMutations($originalMethodId, Context $thisContext)
    {
        list($fqClassName) = explode('::', $originalMethodId);

        $fileChecker = $this->getFileCheckerForClassLike($fqClassName);

        $appearingMethodId = $this->codebase->methods->getAppearingMethodId($originalMethodId);

        if (!$appearingMethodId) {
            // this can happen for some abstract classes implementing (but not fully) interfaces
            return;
        }

        list($appearingFqClassName) = explode('::', $appearingMethodId);

        $appearingClassStorage = $this->classlikeStorageProvider->get($appearingFqClassName);

        if (!$appearingClassStorage->userDefined) {
            return;
        }

        if (strtolower($appearingFqClassName) !== strtolower($fqClassName)) {
            $fileChecker = $this->getFileCheckerForClassLike($appearingFqClassName);
        }

        $stmts = $this->codebase->getStatementsForFile($fileChecker->getFilePath());

        $fileChecker->populateCheckers($stmts);

        if (!$thisContext->self) {
            $thisContext->self = $fqClassName;
            $thisContext->varsInScope['$this'] = Type::parseString($fqClassName);
        }

        $fileChecker->getMethodMutations($appearingMethodId, $thisContext);
    }
}
