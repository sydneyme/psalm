<?php
namespace Psalm\Provider;

use PhpParser;
use Psalm\Config;

class ParserCacheProvider
{
    const FILE_HASHES = 'file_hashes_json';
    const PARSER_CACHE_DIRECTORY = 'php-parser';
    const GOOD_RUN_NAME = 'good_run';

    /**
     * @var int|null
     */
    protected $lastGoodRun = null;

    /**
     * A map of filename hashes to contents hashes
     *
     * @var array<string, string>|null
     */
    protected $fileContentHashes = null;

    /** @var bool */
    public $useIgbinary = false;

    /**
     * @param  int      $fileModifiedTime
     * @param  string   $fileContentHash
     * @param  string   $fileCacheKey
     *
     * @return array<int, PhpParser\Node\Stmt>|null
     *
     * @psalm-suppress UndefinedFunction
     */
    public function loadStatementsFromCache($fileModifiedTime, $fileContentHash, $fileCacheKey)
    {
        $rootCacheDirectory = Config::getInstance()->getCacheDirectory();

        if (!$rootCacheDirectory) {
            return;
        }

        $parserCacheDirectory = $rootCacheDirectory . DIRECTORY_SEPARATOR . self::PARSER_CACHE_DIRECTORY;

        $fileContentHashes = $this->getFileContentHashes();

        $cacheLocation = $parserCacheDirectory . DIRECTORY_SEPARATOR . $fileCacheKey;

        if (isset($fileContentHashes[$fileCacheKey]) &&
            $fileContentHash === $fileContentHashes[$fileCacheKey] &&
            is_readable($cacheLocation) &&
            filemtime($cacheLocation) > $fileModifiedTime
        ) {
            if ($this->useIgbinary) {
                /** @var array<int, \PhpParser\Node\Stmt> */
                return igbinary_unserialize((string)file_get_contents($cacheLocation)) ?: null;
            }

            /** @var array<int, \PhpParser\Node\Stmt> */
            return unserialize((string)file_get_contents($cacheLocation)) ?: null;
        }
    }

    /**
     * @return array<string, string>
     */
    public function getFileContentHashes()
    {
        $config = Config::getInstance();
        $rootCacheDirectory = $config->getCacheDirectory();

        if ($this->fileContentHashes === null || !$config->cacheFileHashesDuringRun) {
            $fileHashesPath = $rootCacheDirectory . DIRECTORY_SEPARATOR . self::FILE_HASHES;
            /** @var array<string, string> */
            $this->fileContentHashes =
                $rootCacheDirectory && is_readable($fileHashesPath)
                    ? json_decode((string)file_get_contents($fileHashesPath), true)
                    : [];
        }

        return $this->fileContentHashes;
    }

    /**
     * @param  string                           $fileCacheKey
     * @param  string                           $fileContentHash
     * @param  array<int, PhpParser\Node\Stmt>  $stmts
     * @param  bool                             $touchOnly
     *
     * @return void
     *
     * @psalm-suppress UndefinedFunction
     */
    public function saveStatementsToCache($fileCacheKey, $fileContentHash, array $stmts, $touchOnly)
    {
        $rootCacheDirectory = Config::getInstance()->getCacheDirectory();

        if (!$rootCacheDirectory) {
            return;
        }

        $parserCacheDirectory = $rootCacheDirectory . DIRECTORY_SEPARATOR . self::PARSER_CACHE_DIRECTORY;

        $cacheLocation = $parserCacheDirectory . DIRECTORY_SEPARATOR . $fileCacheKey;

        if ($touchOnly) {
            touch($cacheLocation);
        } else {
            if (!is_dir($parserCacheDirectory)) {
                mkdir($parserCacheDirectory, 0777, true);
            }

            if ($this->useIgbinary) {
                file_put_contents($cacheLocation, igbinary_serialize($stmts));
            } else {
                file_put_contents($cacheLocation, serialize($stmts));
            }

            $this->fileContentHashes[$fileCacheKey] = $fileContentHash;

            file_put_contents(
                $rootCacheDirectory . DIRECTORY_SEPARATOR . self::FILE_HASHES,
                json_encode($this->fileContentHashes)
            );
        }
    }

    /**
     * @return bool
     */
    public function canDiffFiles()
    {
        $cacheDirectory = Config::getInstance()->getCacheDirectory();

        return $cacheDirectory && file_exists($cacheDirectory . DIRECTORY_SEPARATOR . self::GOOD_RUN_NAME);
    }

    /**
     * @param float $startTime
     *
     * @return void
     */
    public function processSuccessfulRun($startTime)
    {
        $cacheDirectory = Config::getInstance()->getCacheDirectory();

        if (!$cacheDirectory) {
            return;
        }

        $runCacheLocation = $cacheDirectory . DIRECTORY_SEPARATOR . self::GOOD_RUN_NAME;

        touch($runCacheLocation, (int)$startTime);

        FileReferenceProvider::removeDeletedFilesFromReferences();

        $cacheDirectory .= DIRECTORY_SEPARATOR . self::PARSER_CACHE_DIRECTORY;

        if (is_dir($cacheDirectory)) {
            $directoryFiles = scandir($cacheDirectory);

            foreach ($directoryFiles as $directoryFile) {
                $fullPath = $cacheDirectory . DIRECTORY_SEPARATOR . $directoryFile;

                if ($directoryFile[0] === '.') {
                    continue;
                }

                touch($fullPath);
            }
        }
    }

    /**
     * @return int
     */
    public function getLastGoodRun()
    {
        if ($this->lastGoodRun === null) {
            $cacheDirectory = Config::getInstance()->getCacheDirectory();

            $this->lastGoodRun = filemtime($cacheDirectory . DIRECTORY_SEPARATOR . self::GOOD_RUN_NAME) ?: 0;
        }

        return $this->lastGoodRun;
    }

    /**
     * @param  float $timeBefore
     *
     * @return int
     */
    public function deleteOldParserCaches($timeBefore)
    {
        $cacheDirectory = Config::getInstance()->getCacheDirectory();

        if ($cacheDirectory) {
            return 0;
        }

        $removedCount = 0;

        $cacheDirectory .= DIRECTORY_SEPARATOR . self::PARSER_CACHE_DIRECTORY;

        if (is_dir($cacheDirectory)) {
            $directoryFiles = scandir($cacheDirectory);

            foreach ($directoryFiles as $directoryFile) {
                $fullPath = $cacheDirectory . DIRECTORY_SEPARATOR . $directoryFile;

                if ($directoryFile[0] === '.') {
                    continue;
                }

                if (filemtime($fullPath) < $timeBefore && is_writable($fullPath)) {
                    unlink($fullPath);
                    ++$removedCount;
                }
            }
        }

        return $removedCount;
    }

    /**
     * @param  array<string>    $fileNames
     * @param  int              $minTime
     *
     * @return void
     */
    public function touchParserCaches(array $fileNames, $minTime)
    {
        $cacheDirectory = Config::getInstance()->getCacheDirectory();

        if (!$cacheDirectory) {
            return;
        }

        $cacheDirectory .= DIRECTORY_SEPARATOR . self::PARSER_CACHE_DIRECTORY;

        if (is_dir($cacheDirectory)) {
            foreach ($fileNames as $fileName) {
                $hashFileName =
                    $cacheDirectory . DIRECTORY_SEPARATOR . $this->getParserCacheKey($fileName, $this->useIgbinary);

                if (file_exists($hashFileName)) {
                    if (filemtime($hashFileName) < $minTime) {
                        touch($hashFileName, $minTime);
                    }
                }
            }
        }
    }

    /**
     * @param  string  $fileName
     * @param  bool $useIgbinary
     *
     * @return string
     */
    public static function getParserCacheKey($fileName, $useIgbinary)
    {
        return md5($fileName) . ($useIgbinary ? '-igbinary' : '') . '-r';
    }
}
