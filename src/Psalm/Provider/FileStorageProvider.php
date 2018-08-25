<?php
namespace Psalm\Provider;

use Psalm\Storage\FileStorage;

class FileStorageProvider
{
    /**
     * A list of data useful to analyse files
     * Storing this statically is much faster (at least in PHP 7.2.1)
     *
     * @var array<string, FileStorage>
     */
    private static $storage = [];

    /**
     * @var FileStorageCacheProvider
     */
    public $cache;

    public function __construct(FileStorageCacheProvider $cache)
    {
        $this->cache = $cache;
    }

    /**
     * @param  string $filePath
     *
     * @return FileStorage
     */
    public function get($filePath)
    {
        $filePath = strtolower($filePath);

        if (!isset(self::$storage[$filePath])) {
            throw new \InvalidArgumentException('Could not get file storage for ' . $filePath);
        }

        return self::$storage[$filePath];
    }

    /**
     * @param  string $filePath
     * @param  string $fileContents
     *
     * @return bool
     */
    public function has($filePath, $fileContents)
    {
        $filePath = strtolower($filePath);

        if (isset(self::$storage[$filePath])) {
            return true;
        }

        $cachedValue = $this->cache->getLatestFromCache($filePath, $fileContents);

        if (!$cachedValue) {
            return false;
        }

        self::$storage[$filePath] = $cachedValue;

        return true;
    }

    /**
     * @return array<string, FileStorage>
     */
    public function getAll()
    {
        return self::$storage;
    }

    /**
     * @param  string $filePath
     *
     * @return FileStorage
     */
    public function create($filePath)
    {
        $filePathLc = strtolower($filePath);

        self::$storage[$filePathLc] = $storage = new FileStorage($filePath);

        return $storage;
    }

    /**
     * @return void
     */
    public static function deleteAll()
    {
        self::$storage = [];
    }
}
