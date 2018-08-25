<?php
namespace Psalm\Provider\NoCache;

use Psalm\Storage\FileStorage;

class NoFileStorageCacheProvider extends \Psalm\Provider\FileStorageCacheProvider
{
    public function __construct()
    {
    }

    /**
     * @param  string $filePath
     * @param  string $fileContents
     *
     * @return void
     */
    public function writeToCache(FileStorage $storage, $fileContents)
    {
    }

    /**
     * @param  string $filePath
     * @param  string $fileContents
     *
     * @return FileStorage|null
     */
    public function getLatestFromCache($filePath, $fileContents)
    {
    }

    public function removeCacheForFile($filePath)
    {
    }
}
