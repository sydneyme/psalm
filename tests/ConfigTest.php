<?php
namespace Psalm\Tests;

use Psalm\Checker\FileChecker;
use Psalm\Config;
use Psalm\Context;

class ConfigTest extends TestCase
{
    /** @var TestConfig */
    protected static $config;

    /** @var \Psalm\Checker\ProjectChecker */
    protected $projectChecker;

    /**
     * @return void
     */
    public static function setUpBeforeClass()
    {
        self::$config = new TestConfig();

        if (!defined('PSALM_VERSION')) {
            define('PSALM_VERSION', '2.0.0');
        }

        if (!defined('PHP_PARSER_VERSION')) {
            define('PHP_PARSER_VERSION', '4.0.0');
        }
    }

    /**
     * @return void
     */
    public function setUp()
    {
        FileChecker::clearCache();
        $this->fileProvider = new Provider\FakeFileProvider();
    }

    /**
     * @return       string[]
     * @psalm-return array<mixed, string>
     */
    public static function getAllIssues()
    {
        return array_filter(
            array_map(
                /**
                 * @param string $fileName
                 *
                 * @return string
                 */
                function ($fileName) {
                    return substr($fileName, 0, -4);
                },
                scandir(dirname(__DIR__) . '/src/Psalm/Issue')
            ),
            /**
             * @param string $issueName
             *
             * @return bool
             */
            function ($issueName) {
                return !empty($issueName)
                    && $issueName !== 'MethodIssue'
                    && $issueName !== 'PropertyIssue'
                    && $issueName !== 'ClassIssue'
                    && $issueName !== 'CodeIssue';
            }
        );
    }

    /**
     * @param  Config $config
     *
     * @return \Psalm\Checker\ProjectChecker
     */
    private function getProjectCheckerWithConfig(Config $config)
    {
        return new \Psalm\Checker\ProjectChecker(
            $config,
            $this->fileProvider,
            new Provider\FakeParserCacheProvider(),
            new \Psalm\Provider\NoCache\NoFileStorageCacheProvider(),
            new \Psalm\Provider\NoCache\NoClassLikeStorageCacheProvider()
        );
    }

    /**
     * @return void
     */
    public function testBarebonesConfig()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            Config::loadFromXML(
                (string)getcwd(),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                    </projectFiles>
                </psalm>'
            )
        );

        $config = $this->projectChecker->getConfig();

        $this->assertTrue($config->isInProjectDirs(realpath('src/Psalm/Type.php')));
        $this->assertFalse($config->isInProjectDirs(realpath('examples/StringChecker.php')));
    }

    /**
     * @return void
     */
    public function testIgnoreProjectDirectory()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            Config::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                        <ignoreFiles>
                            <directory name="src/Psalm/Checker" />
                        </ignoreFiles>
                    </projectFiles>
                </psalm>'
            )
        );

        $config = $this->projectChecker->getConfig();

        $this->assertTrue($config->isInProjectDirs(realpath('src/Psalm/Type.php')));
        $this->assertFalse($config->isInProjectDirs(realpath('src/Psalm/Checker/FileChecker.php')));
        $this->assertFalse($config->isInProjectDirs(realpath('examples/StringChecker.php')));
    }

    /**
     * @return void
     */
    public function testIgnoreWildcardProjectDirectory()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            Config::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                        <ignoreFiles>
                            <directory name="src/**/Checker" />
                        </ignoreFiles>
                    </projectFiles>
                </psalm>'
            )
        );

        $config = $this->projectChecker->getConfig();

        $this->assertTrue($config->isInProjectDirs(realpath('src/Psalm/Type.php')));
        $this->assertFalse($config->isInProjectDirs(realpath('src/Psalm/Checker/FileChecker.php')));
        $this->assertFalse($config->isInProjectDirs(realpath('src/Psalm/Checker/Statements/ReturnChecker.php')));
        $this->assertFalse($config->isInProjectDirs(realpath('examples/StringChecker.php')));
    }

    /**
     * @return void
     */
    public function testIgnoreWildcardFiles()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            Config::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                        <ignoreFiles>
                            <file name="src/Psalm/Checker/*Checker.php" />
                        </ignoreFiles>
                    </projectFiles>
                </psalm>'
            )
        );

        $config = $this->projectChecker->getConfig();

        $this->assertTrue($config->isInProjectDirs(realpath('src/Psalm/Type.php')));
        $this->assertFalse($config->isInProjectDirs(realpath('src/Psalm/Checker/FileChecker.php')));
        $this->assertTrue($config->isInProjectDirs(realpath('src/Psalm/Checker/Statements/ReturnChecker.php')));
        $this->assertFalse($config->isInProjectDirs(realpath('examples/StringChecker.php')));
    }

    /**
     * @return void
     */
    public function testIgnoreWildcardFilesInWildcardFolder()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            Config::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                        <directory name="examples" />
                        <ignoreFiles>
                            <file name="src/Psalm/**/*Checker.php" />
                            <file name="src/Psalm/**/**/*Checker.php" />
                        </ignoreFiles>
                    </projectFiles>
                </psalm>'
            )
        );

        $config = $this->projectChecker->getConfig();

        $this->assertTrue($config->isInProjectDirs(realpath('src/Psalm/Type.php')));
        $this->assertTrue($config->isInProjectDirs(realpath('src/Psalm/Visitor/DependencyFinderVisitor.php')));
        $this->assertFalse($config->isInProjectDirs(realpath('src/Psalm/Checker/FileChecker.php')));
        $this->assertFalse($config->isInProjectDirs(realpath('src/Psalm/Checker/Statements/ReturnChecker.php')));
        $this->assertTrue($config->isInProjectDirs(realpath('examples/StringChecker.php')));
    }

    /**
     * @return void
     */
    public function testIgnoreWildcardFilesInAllPossibleWildcardFolders()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            Config::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                        <directory name="examples" />
                        <ignoreFiles>
                            <file name="**/*Checker.php" />
                            <file name="**/**/**/*Checker.php" />
                            <file name="**/**/**/**/*Checker.php" />
                        </ignoreFiles>
                    </projectFiles>
                </psalm>'
            )
        );

        $config = $this->projectChecker->getConfig();

        $this->assertTrue($config->isInProjectDirs(realpath('src/Psalm/Type.php')));
        $this->assertTrue($config->isInProjectDirs(realpath('src/Psalm/Visitor/DependencyFinderVisitor.php')));
        $this->assertFalse($config->isInProjectDirs(realpath('src/Psalm/Checker/FileChecker.php')));
        $this->assertFalse($config->isInProjectDirs(realpath('src/Psalm/Checker/Statements/ReturnChecker.php')));
        $this->assertFalse($config->isInProjectDirs(realpath('examples/StringChecker.php')));
    }

    /**
     * @return void
     */
    public function testIssueHandler()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            Config::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                        <directory name="tests" />
                    </projectFiles>

                    <issueHandlers>
                        <MissingReturnType errorLevel="suppress" />
                    </issueHandlers>
                </psalm>'
            )
        );

        $config = $this->projectChecker->getConfig();

        $this->assertFalse($config->reportIssueInFile('MissingReturnType', realpath('tests/ConfigTest.php')));
        $this->assertFalse($config->reportIssueInFile('MissingReturnType', realpath('src/Psalm/Type.php')));
    }

    /**
     * @return void
     */
    public function testIssueHandlerWithCustomErrorLevels()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            Config::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                        <directory name="tests" />
                    </projectFiles>

                    <issueHandlers>
                        <MissingReturnType errorLevel="info">
                            <errorLevel type="suppress">
                                <directory name="tests" />
                            </errorLevel>
                            <errorLevel type="error">
                                <directory name="src/Psalm/Checker" />
                            </errorLevel>
                        </MissingReturnType>
                        <UndefinedClass>
                            <errorLevel type="suppress">
                                <referencedClass name="Psalm\Badger" />
                            </errorLevel>
                        </UndefinedClass>
                        <UndefinedMethod>
                            <errorLevel type="suppress">
                                <referencedMethod name="Psalm\Bodger::find1" />
                            </errorLevel>
                        </UndefinedMethod>
                        <UndefinedPropertyFetch>
                            <errorLevel type="suppress">
                                <referencedProperty name="Psalm\Bodger::$find3" />
                            </errorLevel>
                        </UndefinedPropertyFetch>
                    </issueHandlers>
                </psalm>'
            )
        );

        $config = $this->projectChecker->getConfig();

        $this->assertSame(
            'info',
            $config->getReportingLevelForFile(
                'MissingReturnType',
                realpath('src/Psalm/Type.php')
            )
        );

        $this->assertSame(
            'error',
            $config->getReportingLevelForFile(
                'MissingReturnType',
                realpath('src/Psalm/Checker/FileChecker.php')
            )
        );

        $this->assertSame(
            'suppress',
            $config->getReportingLevelForClass(
                'UndefinedClass',
                'Psalm\Badger'
            )
        );

        $this->assertSame(
            'error',
            $config->getReportingLevelForClass(
                'UndefinedClass',
                'Psalm\Bodger'
            )
        );

        $this->assertSame(
            'suppress',
            $config->getReportingLevelForMethod(
                'UndefinedMethod',
                'Psalm\Bodger::find1'
            )
        );

        $this->assertSame(
            'error',
            $config->getReportingLevelForProperty(
                'UndefinedMethod',
                'Psalm\Bodger::$find3'
            )
        );

        $this->assertSame(
            'error',
            $config->getReportingLevelForProperty(
                'UndefinedMethod',
                'Psalm\Bodger::$find4'
            )
        );
    }

    /**
     * @return void
     */
    public function testAllPossibleIssues()
    {
        $allPossibleHandlers = implode(
            ' ',
            array_map(
                /**
                 * @param string $issueName
                 *
                 * @return string
                 */
                function ($issueName) {
                    return '<' . $issueName . ' errorLevel="suppress" />' . "\n";
                },
                self::getAllIssues()
            )
        );

        $this->projectChecker = $this->getProjectCheckerWithConfig(
            Config::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                    </projectFiles>

                    <issueHandlers>
                    ' . $allPossibleHandlers . '
                    </issueHandlers>
                </psalm>'
            )
        );
    }

    /**
     * @expectedException        \Psalm\Exception\ConfigException
     * @expectedExceptionMessage This element is not expected
     *
     * @return                   void
     */
    public function testImpossibleIssue()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            Config::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                    </projectFiles>

                    <issueHandlers>
                        <ImpossibleIssue errorLevel="suppress" />
                    </issueHandlers>
                </psalm>'
            )
        );
    }

    /**
     * @expectedException        \Psalm\Exception\CodeException
     * @expectedExceptionMessage MissingReturnType
     *
     * @return                   void
     */
    public function testRequireVoidReturnTypeExists()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            TestConfig::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm
                    requireVoidReturnType="true">
                    <projectFiles>
                        <directory name="src" />
                    </projectFiles>
                </psalm>'
            )
        );

        $filePath = getcwd() . '/src/somefile.php';

        $this->addFile(
            $filePath,
            '<?php
                function foo() {}'
        );

        $this->analyzeFile($filePath, new Context());
    }

    /**
     * @return void
     */
    public function testDoNotRequireVoidReturnTypeExists()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            TestConfig::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm
                    requireVoidReturnType="false">
                    <projectFiles>
                        <directory name="src" />
                    </projectFiles>
                </psalm>'
            )
        );

        $filePath = getcwd() . '/src/somefile.php';

        $this->addFile(
            $filePath,
            '<?php
                function foo() {}'
        );

        $this->analyzeFile($filePath, new Context());
    }

    /**
     * @return void
     */
    public function testMethodCallMemoize()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            TestConfig::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm memoizeMethodCallResults="true">
                    <projectFiles>
                        <directory name="src" />
                    </projectFiles>
                </psalm>'
            )
        );

        $filePath = getcwd() . '/src/somefile.php';

        $this->addFile(
            $filePath,
            '<?php
                class A {
                    function getFoo() : ?Foo {
                        return rand(0, 1) ? new Foo : null;
                    }
                }
                class Foo {
                    function getBar() : ?Bar {
                        return rand(0, 1) ? new Bar : null;
                    }
                }
                class Bar {
                    public function bat() : void {}
                };

                $a = new A();

                if ($a->getFoo()) {
                    if ($a->getFoo()->getBar()) {
                        $a->getFoo()->getBar()->bat();
                    }
                }'
        );

        $this->analyzeFile($filePath, new Context());
    }

    /**
     * @return void
     */
    public function testExitFunctions()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            TestConfig::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
                    <exitFunctions>
                        <function name="leave" />
                        <function name="Foo\namespacedLeave" />
                        <function name="Foo\Bar::staticLeave" />
                    </exitFunctions>
                </psalm>'
            )
        );

        $filePath = getcwd() . '/src/somefile.php';

        $this->addFile(
            $filePath,
            '<?php
                namespace {
                    function leave() : void {
                        exit();
                    }

                    function mightLeave() : string {
                        if (rand(0, 1)) {
                            leave();
                        } else {
                            return "here";
                        }
                    }

                    function mightLeaveWithNamespacedFunction() : string {
                        if (rand(0, 1)) {
                            \Foo\namespacedLeave();
                        } else {
                            return "here";
                        }
                    }

                    function mightLeaveWithStaticMethod() : string {
                        if (rand(0, 1)) {
                            Foo\Bar::staticLeave();
                        } else {
                            return "here";
                        }
                    }
                }

                namespace Foo {
                    function namespacedLeave() : void {
                        exit();
                    }

                    class Bar {
                        public static function staticLeave() : void {
                            exit();
                        }
                    }
                }'
        );

        $this->analyzeFile($filePath, new Context());
    }

    /**
     * @return void
     */
    public function testTemplatedFiles()
    {
        foreach (['1.xml', '2.xml', '3.xml', '4.xml', '5.xml', '6.xml', '7.xml', '8.xml'] as $fileName) {
            Config::loadFromXMLFile(
                realpath(dirname(__DIR__) . '/assets/config_levels/' . $fileName),
                dirname(__DIR__)
            );
        }
    }
}
