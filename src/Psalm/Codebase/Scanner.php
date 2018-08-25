<?php
namespace Psalm\Codebase;

use Psalm\Codebase;
use Psalm\Config;
use Psalm\Provider\FileProvider;
use Psalm\Provider\FileReferenceProvider;
use Psalm\Provider\FileStorageProvider;
use Psalm\Scanner\FileScanner;

/**
 * @internal
 *
 * Contains methods that aid in the scanning of Psalm's codebase
 */
class Scanner
{
    /**
     * @var Codebase
     */
    private $codebase;

    /**
     * @var array<string, string>
     */
    private $classlikeFiles = [];

    /**
     * @var array<string, bool>
     */
    private $deepScannedClasslikeFiles = [];

    /**
     * @var array<string, string>
     */
    private $filesToScan = [];

    /**
     * @var array<string, string>
     */
    private $classesToScan = [];

    /**
     * @var array<string, bool>
     */
    private $classesToDeepScan = [];

    /**
     * @var array<string, string>
     */
    private $filesToDeepScan = [];

    /**
     * @var array<string, bool>
     */
    private $scannedFiles = [];

    /**
     * @var array<string, bool>
     */
    private $storeScanFailure = [];

    /**
     * @var array<string, bool>
     */
    private $reflectedClasslikesLc = [];

    /**
     * @var Reflection
     */
    private $reflection;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var bool
     */
    private $debugOutput;

    /**
     * @var FileStorageProvider
     */
    private $fileStorageProvider;

    /**
     * @var FileProvider
     */
    private $fileProvider;

    /**
     * @param bool $debugOutput
     */
    public function __construct(
        Codebase $codebase,
        Config $config,
        FileStorageProvider $fileStorageProvider,
        FileProvider $fileProvider,
        Reflection $reflection,
        $debugOutput
    ) {
        $this->codebase = $codebase;
        $this->reflection = $reflection;
        $this->fileProvider = $fileProvider;
        $this->debugOutput = $debugOutput;
        $this->fileStorageProvider = $fileStorageProvider;
        $this->config = $config;
    }

    /**
     * @param array<string, string> $filesToScan
     *
     * @return void
     */
    public function addFilesToShallowScan(array $filesToScan)
    {
        $this->filesToScan += $filesToScan;
    }

    /**
     * @param array<string, string> $filesToScan
     *
     * @return void
     */
    public function addFilesToDeepScan(array $filesToScan)
    {
        $this->filesToScan += $filesToScan;
        $this->filesToDeepScan += $filesToScan;
    }

    /**
     * @param  string $filePath
     *
     * @return void
     */
    public function addFileToShallowScan($filePath)
    {
        $this->filesToScan[$filePath] = $filePath;
    }

    /**
     * @param  string $filePath
     *
     * @return void
     */
    public function addFileToDeepScan($filePath)
    {
        $this->filesToScan[$filePath] = $filePath;
        $this->filesToDeepScan[$filePath] = $filePath;
    }

    /**
     * @param  string $fqClasslikeNameLc
     * @param  string $filePath
     *
     * @return void
     */
    public function setClassLikeFilePath($fqClasslikeNameLc, $filePath)
    {
        $this->classlikeFiles[$fqClasslikeNameLc] = $filePath;
    }

    /**
     * @param  string $fqClasslikeNameLc
     *
     * @return string
     */
    public function getClassLikeFilePath($fqClasslikeNameLc)
    {
        if (!isset($this->classlikeFiles[$fqClasslikeNameLc])) {
            throw new \UnexpectedValueException('Could not find file for ' . $fqClasslikeNameLc);
        }

        return $this->classlikeFiles[$fqClasslikeNameLc];
    }

    /**
     * @param  string  $fqClasslikeName
     * @param  string|null  $referencingFilePath
     * @param  bool $analyzeToo
     * @param  bool $storeFailure
     *
     * @return void
     */
    public function queueClassLikeForScanning(
        $fqClasslikeName,
        $referencingFilePath = null,
        $analyzeToo = false,
        $storeFailure = true
    ) {
        $fqClasslikeNameLc = strtolower($fqClasslikeName);

        // avoid checking classes that we know will just end in failure
        if ($fqClasslikeNameLc === 'null' || substr($fqClasslikeNameLc, -5) === '\null') {
            return;
        }

        if (!isset($this->classlikeFiles[$fqClasslikeNameLc])
            || ($analyzeToo && !isset($this->deepScannedClasslikeFiles[$fqClasslikeNameLc]))
        ) {
            if (!isset($this->classesToScan[$fqClasslikeNameLc]) || $storeFailure) {
                $this->classesToScan[$fqClasslikeNameLc] = $fqClasslikeName;
            }

            if ($analyzeToo) {
                $this->classesToDeepScan[$fqClasslikeNameLc] = true;
            }

            $this->storeScanFailure[$fqClasslikeName] = $storeFailure;
        }

        if ($referencingFilePath) {
            FileReferenceProvider::addFileReferenceToClass($referencingFilePath, $fqClasslikeNameLc);
        }
    }

    /**
     * @return bool
     */
    public function scanFiles(ClassLikes $classlikes)
    {
        $filetypeScanners = $this->config->getFiletypeScanners();

        $hasChanges = false;

        while ($this->filesToScan || $this->classesToScan) {
            if ($this->filesToScan) {
                $filePath = array_shift($this->filesToScan);

                if (!isset($this->scannedFiles[$filePath])
                    || (isset($this->filesToDeepScan[$filePath]) && !$this->scannedFiles[$filePath])
                ) {
                    $this->scanFile(
                        $filePath,
                        $filetypeScanners,
                        isset($this->filesToDeepScan[$filePath])
                    );
                    $hasChanges = true;
                }
            } else {
                $fqClasslikeName = array_shift($this->classesToScan);
                $fqClasslikeNameLc = strtolower($fqClasslikeName);

                if (isset($this->reflectedClasslikesLc[$fqClasslikeNameLc])) {
                    continue;
                }

                if ($classlikes->isMissingClassLike($fqClasslikeNameLc)) {
                    continue;
                }

                if (!isset($this->classlikeFiles[$fqClasslikeNameLc])) {
                    if ($classlikes->doesClassLikeExist($fqClasslikeNameLc)) {
                        if ($this->debugOutput) {
                            echo 'Using reflection to get metadata for ' . $fqClasslikeName . "\n";
                        }

                        $reflectedClass = new \ReflectionClass($fqClasslikeName);
                        $this->reflection->registerClass($reflectedClass);
                        $this->reflectedClasslikesLc[$fqClasslikeNameLc] = true;
                    } elseif ($this->fileExistsForClassLike($classlikes, $fqClasslikeName)) {
                        // even though we've checked this above, calling the method invalidates it
                        if (isset($this->classlikeFiles[$fqClasslikeNameLc])) {
                            /** @var string */
                            $filePath = $this->classlikeFiles[$fqClasslikeNameLc];
                            $this->filesToScan[$filePath] = $filePath;
                            if (isset($this->classesToDeepScan[$fqClasslikeNameLc])) {
                                unset($this->classesToDeepScan[$fqClasslikeNameLc]);
                                $this->filesToDeepScan[$filePath] = $filePath;
                            }
                        }
                    } elseif ($this->storeScanFailure[$fqClasslikeName]) {
                        $classlikes->registerMissingClassLike($fqClasslikeNameLc);
                    }
                } elseif (isset($this->classesToDeepScan[$fqClasslikeNameLc])
                    && !isset($this->deepScannedClasslikeFiles[$fqClasslikeNameLc])
                ) {
                    $filePath = $this->classlikeFiles[$fqClasslikeNameLc];
                    $this->filesToScan[$filePath] = $filePath;
                    unset($this->classesToDeepScan[$fqClasslikeNameLc]);
                    $this->filesToDeepScan[$filePath] = $filePath;
                    $this->deepScannedClasslikeFiles[$fqClasslikeNameLc] = true;
                }
            }
        }

        return $hasChanges;
    }

    /**
     * @param  string $filePath
     * @param  array<string, string>  $filetypeScanners
     * @param  bool   $willAnalyze
     *
     * @return FileScanner
     *
     * @psalm-suppress MixedOffset
     */
    private function scanFile(
        $filePath,
        array $filetypeScanners,
        $willAnalyze = false
    ) {
        $fileScanner = $this->getScannerForPath($filePath, $filetypeScanners, $willAnalyze);

        if (isset($this->scannedFiles[$filePath])
            && (!$willAnalyze || $this->scannedFiles[$filePath])
        ) {
            throw new \UnexpectedValueException('Should not be rescanning ' . $filePath);
        }

        $fileContents = $this->fileProvider->getContents($filePath);

        $fromCache = $this->fileStorageProvider->has($filePath, $fileContents);

        if (!$fromCache) {
            $this->fileStorageProvider->create($filePath);
        }

        $this->scannedFiles[$filePath] = $willAnalyze;

        $fileStorage = $this->fileStorageProvider->get($filePath);

        $fileScanner->scan(
            $this->codebase,
            $fileStorage,
            $fromCache,
            $this->debugOutput
        );

        if (!$fromCache) {
            if (!$fileStorage->hasVisitorIssues) {
                $this->fileStorageProvider->cache->writeToCache($fileStorage, $fileContents);
            }
        } else {
            foreach ($fileStorage->requiredFilePaths as $requiredFilePath) {
                if ($willAnalyze) {
                    $this->addFileToDeepScan($requiredFilePath);
                } else {
                    $this->addFileToShallowScan($requiredFilePath);
                }
            }

            foreach ($fileStorage->classlikesInFile as $fqClasslikeName) {
                $this->codebase->exhumeClassLikeStorage($fqClasslikeName, $filePath);
            }

            foreach ($fileStorage->requiredClasses as $fqClasslikeName) {
                $this->queueClassLikeForScanning($fqClasslikeName, $filePath, $willAnalyze, false);
            }

            foreach ($fileStorage->requiredInterfaces as $fqClasslikeName) {
                $this->queueClassLikeForScanning($fqClasslikeName, $filePath, false, false);
            }

            foreach ($fileStorage->referencedClasslikes as $fqClasslikeName) {
                $this->queueClassLikeForScanning($fqClasslikeName, $filePath, false, false);
            }

            if ($this->codebase->registerAutoloadFiles) {
                foreach ($fileStorage->functions as $functionStorage) {
                    $this->codebase->functions->addGlobalFunction($functionStorage->casedName, $functionStorage);
                }

                foreach ($fileStorage->constants as $name => $type) {
                    $this->codebase->addGlobalConstantType($name, $type);
                }
            }
        }

        return $fileScanner;
    }

    /**
     * @param  string $filePath
     * @param  array<string, string>  $filetypeScanners
     * @param  bool   $willAnalyze
     *
     * @return FileScanner
     */
    private function getScannerForPath(
        $filePath,
        array $filetypeScanners,
        $willAnalyze = false
    ) {
        $pathParts = explode(DIRECTORY_SEPARATOR, $filePath);
        $fileNameParts = explode('.', array_pop($pathParts));
        $extension = count($fileNameParts) > 1 ? array_pop($fileNameParts) : null;

        $fileName = $this->config->shortenFileName($filePath);

        if (isset($filetypeScanners[$extension])) {
            /** @var FileScanner */
            return new $filetypeScanners[$extension]($filePath, $fileName, $willAnalyze);
        }

        return new FileScanner($filePath, $fileName, $willAnalyze);
    }

    /**
     * @return array<string, bool>
     */
    public function getScannedFiles()
    {
        return $this->scannedFiles;
    }

    /**
     * Checks whether a class exists, and if it does then records what file it's in
     * for later checking
     *
     * @param  string $fqClassName
     *
     * @return bool
     */
    private function fileExistsForClassLike(ClassLikes $classlikes, $fqClassName)
    {
        $fqClassNameLc = strtolower($fqClassName);

        if (isset($this->classlikeFiles[$fqClassNameLc])) {
            return true;
        }

        if (isset($this->existingClasslikesLc[$fqClassNameLc])) {
            throw new \InvalidArgumentException('Why are you asking about a builtin class?');
        }

        $composerFilePath = $this->config->getComposerFilePathForClassLike($fqClassName);

        if ($composerFilePath && file_exists($composerFilePath)) {
            if ($this->debugOutput) {
                echo 'Using composer to locate file for ' . $fqClassName . "\n";
            }

            $classlikes->addFullyQualifiedClassLikeName(
                $fqClassNameLc,
                realpath($composerFilePath)
            );

            return true;
        }

        $oldLevel = error_reporting();

        if (!$this->debugOutput) {
            error_reporting(E_ERROR);
        }

        try {
            if ($this->debugOutput) {
                echo 'Using reflection to locate file for ' . $fqClassName . "\n";
            }

            $reflectedClass = new \ReflectionClass($fqClassName);
        } catch (\ReflectionException $e) {
            error_reporting($oldLevel);

            // do not cache any results here (as case-sensitive filenames can screw things up)

            return false;
        }

        error_reporting($oldLevel);

        /** @psalm-suppress MixedMethodCall due to Reflection class weirdness */
        $filePath = (string)$reflectedClass->getFileName();

        // if the file was autoloaded but exists in evaled code only, return false
        if (!file_exists($filePath)) {
            return false;
        }

        $fqClassName = $reflectedClass->getName();
        $classlikes->addFullyQualifiedClassLikeName($fqClassNameLc);

        if ($reflectedClass->isInterface()) {
            $classlikes->addFullyQualifiedInterfaceName($fqClassName, $filePath);
        } elseif ($reflectedClass->isTrait()) {
            $classlikes->addFullyQualifiedTraitName($fqClassName, $filePath);
        } else {
            $classlikes->addFullyQualifiedClassName($fqClassName, $filePath);
        }

        return true;
    }
}
