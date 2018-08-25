<?php
namespace Psalm\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Psalm\Checker\FileChecker;
use Psalm\Checker\ProjectChecker;
use RuntimeException;

class TestCase extends BaseTestCase
{
    /** @var string */
    protected static $srcDirPath;

    /** @var ProjectChecker */
    protected $projectChecker;

    /** @var Provider\FakeFileProvider */
    protected $fileProvider;

    /**
     * @return void
     */
    public static function setUpBeforeClass()
    {
        ini_set('memory_limit', '-1');

        if (!defined('PSALM_VERSION')) {
            define('PSALM_VERSION', '2.0.0');
        }

        if (!defined('PHP_PARSER_VERSION')) {
            define('PHP_PARSER_VERSION', '4.0.0');
        }

        parent::setUpBeforeClass();
        self::$srcDirPath = getcwd() . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
    }

    /**
     * @return void
     */
    public function setUp()
    {
        parent::setUp();

        FileChecker::clearCache();

        $this->fileProvider = new Provider\FakeFileProvider();

        $config = new TestConfig();
        $parserCacheProvider = new Provider\FakeParserCacheProvider();

        $this->projectChecker = new ProjectChecker(
            $config,
            $this->fileProvider,
            $parserCacheProvider,
            new \Psalm\Provider\NoCache\NoFileStorageCacheProvider(),
            new \Psalm\Provider\NoCache\NoClassLikeStorageCacheProvider(),
            false,
            true,
            ProjectChecker::TYPE_CONSOLE,
            1,
            false
        );

        $this->projectChecker->inferTypesFromUsage = true;
    }

    /**
     * @param string $filePath
     * @param string $contents
     *
     * @return void
     */
    public function addFile($filePath, $contents)
    {
        $this->fileProvider->registerFile($filePath, $contents);
        $this->projectChecker->getCodeBase()->scanner->addFileToShallowScan($filePath);
    }

    /**
     * @param  string         $filePath
     * @param  \Psalm\Context $context
     *
     * @return void
     */
    public function analyzeFile($filePath, \Psalm\Context $context)
    {
        $codebase = $this->projectChecker->getCodebase();
        $codebase->addFilesToAnalyze([$filePath => $filePath]);

        $codebase->scanFiles();

        $codebase->config->visitStubFiles($codebase);

        $fileChecker = new FileChecker(
            $this->projectChecker,
            $filePath,
            $codebase->config->shortenFileName($filePath)
        );
        $fileChecker->analyze($context);
    }

    /**
     * @param  bool $withDataSet
     * @return string
     */
    protected function getTestName($withDataSet = true)
    {
        $name = parent::getName($withDataSet);
        /** @psalm-suppress DocblockTypeContradiction PHPUnit 7 introduced nullable name */
        if (null === $name) {
            throw new RuntimeException('anonymous test - shouldn\'t happen');
        }
        return $name;
    }
}
