<?php
namespace Psalm\Provider;

use PhpParser;

class StatementsProvider
{
    /**
     * @var FileProvider
     */
    private $fileProvider;

    /**
     * @var ParserCacheProvider
     */
    private $cacheProvider;

    /**
     * @var int
     */
    private $thisModifiedTime;

    /**
     * @var FileStorageCacheProvider
     */
    private $fileStorageCacheProvider;

    /**
     * @var PhpParser\Parser|null
     */
    protected static $parser;

    public function __construct(
        FileProvider $fileProvider,
        ParserCacheProvider $cacheProvider,
        FileStorageCacheProvider $fileStorageCacheProvider
    ) {
        $this->fileProvider = $fileProvider;
        $this->cacheProvider = $cacheProvider;
        $this->thisModifiedTime = filemtime(__FILE__);
        $this->fileStorageCacheProvider = $fileStorageCacheProvider;
    }

    /**
     * @param  string  $filePath
     * @param  bool    $debugOutput
     *
     * @return array<int, \PhpParser\Node\Stmt>
     */
    public function getStatementsForFile($filePath, $debugOutput = false)
    {
        $fromCache = false;

        $version = (string) PHP_PARSER_VERSION . $this->thisModifiedTime;

        $fileContents = $this->fileProvider->getContents($filePath);
        $modifiedTime = $this->fileProvider->getModifiedTime($filePath);

        $fileContentHash = md5($version . $fileContents);
        $fileCacheKey = $this->cacheProvider->getParserCacheKey($filePath, $this->cacheProvider->useIgbinary);

        $stmts = $this->cacheProvider->loadStatementsFromCache(
            $modifiedTime,
            $fileContentHash,
            $fileCacheKey
        );

        if ($stmts === null) {
            if ($debugOutput) {
                echo 'Parsing ' . $filePath . "\n";
            }

            $stmts = self::parseStatements($fileContents);
            $this->fileStorageCacheProvider->removeCacheForFile($filePath);
        } else {
            $fromCache = true;
        }

        $nameResolver = new \Psalm\Visitor\SimpleNameResolver;
        $nodeTraverser = new PhpParser\NodeTraverser;
        $nodeTraverser->addVisitor($nameResolver);

        /** @var array<int, \PhpParser\Node\Stmt> */
        $stmts = $nodeTraverser->traverse($stmts);

        $this->cacheProvider->saveStatementsToCache($fileCacheKey, $fileContentHash, $stmts, $fromCache);

        if (!$stmts) {
            return [];
        }

        return $stmts;
    }

    /**
     * @param  string   $fileContents
     *
     * @return array<int, \PhpParser\Node\Stmt>
     */
    public static function parseStatements($fileContents)
    {
        if (!self::$parser) {
            $lexer = new PhpParser\Lexer([
                'usedAttributes' => [
                    'comments', 'startLine', 'startFilePos', 'endFilePos',
                ],
            ]);

            self::$parser = (new PhpParser\ParserFactory())->create(PhpParser\ParserFactory::PREFER_PHP7, $lexer);
        }

        $errorHandler = new \PhpParser\ErrorHandler\Collecting();

        /** @var array<int, \PhpParser\Node\Stmt> */
        $stmts = self::$parser->parse($fileContents, $errorHandler);

        if (!$stmts && $errorHandler->hasErrors()) {
            foreach ($errorHandler->getErrors() as $error) {
                throw $error;
            }
        }

        return $stmts;
    }
}
