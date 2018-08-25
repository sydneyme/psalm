<?php
namespace Psalm\Tests\Provider;

use PhpParser;

class FakeParserCacheProvider extends \Psalm\Provider\ParserCacheProvider
{
    /**
     * @param  string   $fileContentHash
     * @param  string   $fileCacheKey
     * @param mixed $fileModifiedTime
     *
     * @return array<int, PhpParser\Node\Stmt>|null
     */
    public function loadStatementsFromCache($fileModifiedTime, $fileContentHash, $fileCacheKey)
    {
        return null;
    }

    /**
     * @param  string                           $fileCacheKey
     * @param  string                           $fileContentHash
     * @param  array<int, PhpParser\Node\Stmt>  $stmts
     * @param  bool                             $touchOnly
     *
     * @return void
     */
    public function saveStatementsToCache($fileCacheKey, $fileContentHash, array $stmts, $touchOnly)
    {
    }
}
