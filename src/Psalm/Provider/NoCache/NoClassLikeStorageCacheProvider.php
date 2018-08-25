<?php
namespace Psalm\Provider\NoCache;

use Psalm\Storage\ClassLikeStorage;

class NoClassLikeStorageCacheProvider extends \Psalm\Provider\ClassLikeStorageCacheProvider
{
    public function __construct()
    {
    }

    /**
     * @param  string|null $filePath
     * @param  string|null $fileContents
     *
     * @return void
     */
    public function writeToCache(ClassLikeStorage $storage, $filePath, $fileContents)
    {
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
        throw new \LogicException('nothing here');
    }
}
