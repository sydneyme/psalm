<?php
namespace Psalm\Provider;

use Psalm\Storage\ClassLikeStorage;

class ClassLikeStorageProvider
{
    /**
     * Storing this statically is much faster (at least in PHP 7.2.1)
     *
     * @var array<string, ClassLikeStorage>
     */
    private static $storage = [];

    /**
     * @var ClassLikeStorageCacheProvider
     */
    public $cache;

    public function __construct(ClassLikeStorageCacheProvider $cache)
    {
        $this->cache = $cache;
    }

    /**
     * @param  string $fqClasslikeName
     *
     * @return ClassLikeStorage
     */
    public function get($fqClasslikeName)
    {
        $fqClasslikeNameLc = strtolower($fqClasslikeName);

        if (!isset(self::$storage[$fqClasslikeNameLc])) {
            throw new \InvalidArgumentException('Could not get class storage for ' . $fqClasslikeName);
        }

        return self::$storage[$fqClasslikeNameLc];
    }

    /**
     * @param  string $fqClasslikeName
     *
     * @return bool
     */
    public function has($fqClasslikeName)
    {
        $fqClasslikeNameLc = strtolower($fqClasslikeName);

        return isset(self::$storage[$fqClasslikeNameLc]);
    }

    /**
     * @param  string  $fqClasslikeName
     * @param  string|null $filePath
     * @param  string|null $fileContents
     *
     * @return ClassLikeStorage
     */
    public function exhume($fqClasslikeName, $filePath, $fileContents)
    {
        $fqClasslikeNameLc = strtolower($fqClasslikeName);

        if (isset(self::$storage[$fqClasslikeNameLc])) {
            return self::$storage[$fqClasslikeNameLc];
        }

        self::$storage[$fqClasslikeNameLc]
            = $cachedValue
            = $this->cache->getLatestFromCache($fqClasslikeNameLc, $filePath, $fileContents);

        return $cachedValue;
    }

    /**
     * @return array<string, ClassLikeStorage>
     */
    public function getAll()
    {
        return self::$storage;
    }

    /**
     * @param  string $fqClasslikeName
     *
     * @return ClassLikeStorage
     */
    public function create($fqClasslikeName)
    {
        $fqClasslikeNameLc = strtolower($fqClasslikeName);

        self::$storage[$fqClasslikeNameLc] = $storage = new ClassLikeStorage($fqClasslikeName);

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
