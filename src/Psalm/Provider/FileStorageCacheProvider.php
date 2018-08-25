<?php
namespace Psalm\Provider;

use Psalm\Config;
use Psalm\Storage\FileStorage;

class FileStorageCacheProvider
{
    /**
     * @var string
     */
    private $modifiedTimestamps = '';

    /**
     * @var Config
     */
    private $config;

    const FILE_CACHE_DIRECTORY = 'file_cache';

    public function __construct(Config $config)
    {
        $this->config = $config;

        $storageDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Storage' . DIRECTORY_SEPARATOR;

        $dependentFiles = [
            $storageDir . 'FileStorage.php',
            $storageDir . 'FunctionLikeStorage.php',
            $storageDir . 'ClassLikeStorage.php',
            $storageDir . 'MethodStorage.php',
            $storageDir . 'FunctionLikeParameter.php',
        ];

        foreach ($dependentFiles as $dependentFilePath) {
            if (!file_exists($dependentFilePath)) {
                throw new \UnexpectedValueException($dependentFilePath . ' must exist');
            }

            $this->modifiedTimestamps .= ' ' . filemtime($dependentFilePath);
        }

        $this->modifiedTimestamps .= PSALM_VERSION;
    }

    /**
     * @param  string $filePath
     * @param  string $fileContents
     *
     * @return void
     */
    public function writeToCache(FileStorage $storage, $fileContents)
    {
        $filePath = strtolower($storage->filePath);
        $cacheLocation = $this->getCacheLocationForPath($filePath, true);
        $storage->hash = $this->getCacheHash($filePath, $fileContents);

        if ($this->config->useIgbinary) {
            file_put_contents($cacheLocation, igbinary_serialize($storage));
        } else {
            file_put_contents($cacheLocation, serialize($storage));
        }
    }

    /**
     * @param  string $filePath
     * @param  string $fileContents
     *
     * @return FileStorage|null
     */
    public function getLatestFromCache($filePath, $fileContents)
    {
        $cachedValue = $this->loadFromCache($filePath);

        if (!$cachedValue) {
            return null;
        }

        $cacheHash = $this->getCacheHash($filePath, $fileContents);

        if (@get_class($cachedValue) === '__PHP_Incomplete_Class'
            || $cacheHash !== $cachedValue->hash
        ) {
            $this->removeCacheForFile($filePath);

            return null;
        }

        return $cachedValue;
    }

    /**
     * @param  string $filePath
     *
     * @return void
     */
    public function removeCacheForFile($filePath)
    {
        $cachePath = $this->getCacheLocationForPath($filePath);

        if (file_exists($cachePath)) {
            unlink($cachePath);
        }
    }

    /**
     * @param  string $filePath
     * @param  string $fileContents
     *
     * @return string
     */
    private function getCacheHash($filePath, $fileContents)
    {
        return sha1(strtolower($filePath) . ' ' . $fileContents . $this->modifiedTimestamps);
    }

    /**
     * @param  string  $filePath
     *
     * @return FileStorage|null
     */
    private function loadFromCache($filePath)
    {
        $cacheLocation = $this->getCacheLocationForPath($filePath);

        if (file_exists($cacheLocation)) {
            if ($this->config->useIgbinary) {
                /** @var FileStorage */
                return igbinary_unserialize((string)file_get_contents($cacheLocation)) ?: null;
            }
            /** @var FileStorage */
            return unserialize((string)file_get_contents($cacheLocation)) ?: null;
        }

        return null;
    }

    /**
     * @param  string  $filePath
     * @param  bool $createDirectory
     *
     * @return string
     */
    private function getCacheLocationForPath($filePath, $createDirectory = false)
    {
        $rootCacheDirectory = $this->config->getCacheDirectory();

        if (!$rootCacheDirectory) {
            throw new \UnexpectedValueException('No cache directory defined');
        }

        $parserCacheDirectory = $rootCacheDirectory . DIRECTORY_SEPARATOR . self::FILE_CACHE_DIRECTORY;

        if ($createDirectory && !is_dir($parserCacheDirectory)) {
            mkdir($parserCacheDirectory, 0777, true);
        }

        return $parserCacheDirectory
            . DIRECTORY_SEPARATOR
            . sha1($filePath)
            . ($this->config->useIgbinary ? '-igbinary' : '');
    }
}
