<?php
namespace Psalm\Provider;

use Psalm\Config;
use Psalm\Storage\ClassLikeStorage;

class ClassLikeStorageCacheProvider
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var string
     */
    private $modifiedTimestamps = '';

    const CLASS_CACHE_DIRECTORY = 'class_cache';

    public function __construct(Config $config)
    {
        $this->config = $config;

        $storageDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Storage' . DIRECTORY_SEPARATOR;

        $dependentFiles = [
            $storageDir . 'FileStorage.php',
            $storageDir . 'FunctionLikeStorage.php',
            $storageDir . 'ClassLikeStorage.php',
            $storageDir . 'MethodStorage.php',
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
     * @param  string|null $filePath
     * @param  string|null $fileContents
     *
     * @return void
     */
    public function writeToCache(ClassLikeStorage $storage, $filePath, $fileContents)
    {
        $fqClasslikeNameLc = strtolower($storage->name);
        $cacheLocation = $this->getCacheLocationForClass($fqClasslikeNameLc, $filePath, true);
        $storage->hash = $this->getCacheHash($filePath, $fileContents);

        if ($this->config->useIgbinary) {
            file_put_contents($cacheLocation, igbinary_serialize($storage));
        } else {
            file_put_contents($cacheLocation, serialize($storage));
        }
    }

    /**
     * @param  string  $fqClasslikeNameLc
     * @param  string|null $filePath
     * @param  string|null $fileContents
     *
     * @return ClassLikeStorage
     */
    public function getLatestFromCache($fqClasslikeNameLc, $filePath, $fileContents)
    {
        $cachedValue = $this->loadFromCache($fqClasslikeNameLc, $filePath);

        if (!$cachedValue) {
            throw new \UnexpectedValueException('Should be in cache');
        }

        $cacheHash = $this->getCacheHash($filePath, $fileContents);

        if (@get_class($cachedValue) === '__PHP_Incomplete_Class'
            || $cacheHash !== $cachedValue->hash
        ) {
            unlink($this->getCacheLocationForClass($fqClasslikeNameLc, $filePath));

            throw new \UnexpectedValueException('Should not be outdated');
        }

        return $cachedValue;
    }

    /**
     * @param  string|null $filePath
     * @param  string|null $fileContents
     *
     * @return string
     */
    private function getCacheHash($filePath, $fileContents)
    {
        return sha1(($filePath ? $fileContents : '') . $this->modifiedTimestamps);
    }

    /**
     * @param  string  $fqClasslikeNameLc
     * @param  string|null  $filePath
     *
     * @return ClassLikeStorage|null
     */
    private function loadFromCache($fqClasslikeNameLc, $filePath)
    {
        $cacheLocation = $this->getCacheLocationForClass($fqClasslikeNameLc, $filePath);

        if (file_exists($cacheLocation)) {
            if ($this->config->useIgbinary) {
                /** @var ClassLikeStorage */
                return igbinary_unserialize((string)file_get_contents($cacheLocation)) ?: null;
            }
            /** @var ClassLikeStorage */
            return unserialize((string)file_get_contents($cacheLocation)) ?: null;
        }

        return null;
    }

    /**
     * @param  string  $fqClasslikeNameLc
     * @param  string|null  $filePath
     * @param  bool $createDirectory
     *
     * @return string
     */
    private function getCacheLocationForClass($fqClasslikeNameLc, $filePath, $createDirectory = false)
    {
        $rootCacheDirectory = $this->config->getCacheDirectory();

        if (!$rootCacheDirectory) {
            throw new \UnexpectedValueException('No cache directory defined');
        }

        $parserCacheDirectory = $rootCacheDirectory . DIRECTORY_SEPARATOR . self::CLASS_CACHE_DIRECTORY;

        if ($createDirectory && !is_dir($parserCacheDirectory)) {
            mkdir($parserCacheDirectory, 0777, true);
        }

        return $parserCacheDirectory
            . DIRECTORY_SEPARATOR
            . sha1(($filePath ? strtolower($filePath) . ' ' : '') . $fqClasslikeNameLc)
            . ($this->config->useIgbinary ? '-igbinary' : '');
    }
}
