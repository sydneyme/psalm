<?php
namespace Psalm\Tests;

use Psalm\Checker\FileChecker;
use Psalm\Config;
use Psalm\Context;

class StubTest extends TestCase
{
    /** @var TestConfig */
    protected static $config;

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
     * @param  Config $config
     *
     * @return \Psalm\Checker\ProjectChecker
     */
    private function getProjectCheckerWithConfig(Config $config)
    {
        $projectChecker = new \Psalm\Checker\ProjectChecker(
            $config,
            $this->fileProvider,
            new Provider\FakeParserCacheProvider(),
            new \Psalm\Provider\NoCache\NoFileStorageCacheProvider(),
            new \Psalm\Provider\NoCache\NoClassLikeStorageCacheProvider()
        );

        $config->visitComposerAutoloadFiles($projectChecker, false);

        return $projectChecker;
    }

    /**
     * @expectedException        \Psalm\Exception\ConfigException
     * @expectedExceptionMessage Cannot resolve stubfile path
     *
     * @return                   void
     */
    public function testNonexistentStubFile()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            Config::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                    </projectFiles>

                    <stubs>
                        <file name="stubs/invalidfile.php" />
                    </stubs>
                </psalm>'
            )
        );
    }

    /**
     * @return void
     */
    public function testStubFile()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            TestConfig::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                    </projectFiles>

                    <stubs>
                        <file name="tests/stubs/systemclass.php" />
                    </stubs>
                </psalm>'
            )
        );

        $filePath = getcwd() . '/src/somefile.php';

        $this->addFile(
            $filePath,
            '<?php
                $a = new SystemClass();
                echo SystemClass::HELLO;

                $b = $a->foo(5, "hello");
                $c = SystemClass::bar(5, "hello");'
        );

        $this->analyzeFile($filePath, new Context());
    }

    /**
     * @return void
     */
    public function testNamespacedStubClass()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            TestConfig::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                    </projectFiles>

                    <stubs>
                        <file name="tests/stubs/namespaced_class.php" />
                    </stubs>
                </psalm>'
            )
        );

        $filePath = getcwd() . '/src/somefile.php';

        $this->addFile(
            $filePath,
            '<?php
                $a = new Foo\SystemClass();
                echo Foo\SystemClass::HELLO;

                $b = $a->foo(5, "hello");
                $c = Foo\SystemClass::bar(5, "hello");

                echo Foo\BAR;'
        );

        $this->analyzeFile($filePath, new Context());
    }

    /**
     * @return void
     */
    public function testStubFunction()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            TestConfig::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                    </projectFiles>

                    <stubs>
                        <file name="tests/stubs/custom_functions.php" />
                    </stubs>
                </psalm>'
            )
        );

        $filePath = getcwd() . '/src/somefile.php';

        $this->addFile(
            $filePath,
            '<?php
                echo barBar("hello");'
        );

        $this->analyzeFile($filePath, new Context());
    }

    /**
     * @return void
     */
    public function testPolyfilledFunction()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            TestConfig::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm
                    autoloader="tests/stubs/polyfill.php"
                >
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
                $a = random_bytes(16);
                $b = new_random_bytes(16);'
        );

        $this->analyzeFile($filePath, new Context());
    }

    /**
     * @return void
     */
    public function testStubFunctionWithFunctionExists()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            TestConfig::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                    </projectFiles>

                    <stubs>
                        <file name="tests/stubs/custom_functions.php" />
                    </stubs>
                </psalm>'
            )
        );

        $filePath = getcwd() . '/src/somefile.php';

        $this->addFile(
            $filePath,
            '<?php
                function_exists("fooBar");
                echo barBar("hello");'
        );

        $this->analyzeFile($filePath, new Context());
    }

    /**
     * @return void
     */
    public function testNamespacedStubFunctionWithFunctionExists()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            TestConfig::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                    </projectFiles>

                    <stubs>
                        <file name="tests/stubs/custom_functions.php" />
                    </stubs>
                </psalm>'
            )
        );

        $filePath = getcwd() . '/src/somefile.php';

        $this->addFile(
            $filePath,
            '<?php
                namespace A;
                function_exists("fooBar");
                echo barBar("hello");'
        );

        $this->analyzeFile($filePath, new Context());
    }

    /**
     * @expectedException        \Psalm\Exception\CodeException
     * @expectedExceptionMessage UndefinedFunction - /src/somefile.php:2 - Function barBar does not exist
     *
     * @return                   void
     */
    public function testNoStubFunction()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            TestConfig::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
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
                echo barBar("hello");'
        );

        $this->analyzeFile($filePath, new Context());
    }

    /**
     * @return void
     */
    public function testNamespacedStubFunction()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            TestConfig::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                    </projectFiles>

                    <stubs>
                        <file name="tests/stubs/namespaced_functions.php" />
                    </stubs>
                </psalm>'
            )
        );

        $filePath = getcwd() . '/src/somefile.php';

        $this->addFile(
            $filePath,
            '<?php
                echo Foo\barBar("hello");'
        );

        $this->analyzeFile($filePath, new Context());
    }

    /**
     * @return void
     */
    public function testConditionalNamespacedStubFunction()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            TestConfig::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                    </projectFiles>

                    <stubs>
                        <file name="tests/stubs/conditional_namespaced_functions.php" />
                    </stubs>
                </psalm>'
            )
        );

        $filePath = getcwd() . '/src/somefile.php';

        $this->addFile(
            $filePath,
            '<?php
                echo Foo\barBar("hello");'
        );

        $this->analyzeFile($filePath, new Context());
    }

    /**
     * @return void
     */
    public function testStubFileWithExistingClassDefinition()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            TestConfig::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                    </projectFiles>

                    <stubs>
                        <file name="tests/stubs/logicexception.php" />
                    </stubs>
                </psalm>'
            )
        );

        $filePath = getcwd() . '/src/somefile.php';

        $this->addFile(
            $filePath,
            '<?php
                $a = new LogicException(5);'
        );

        $this->analyzeFile($filePath, new Context());
    }

    /**
     * @return void
     */
    public function testStubFileWithPartialClassDefinitionWithMoreMethods()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            TestConfig::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                    </projectFiles>

                    <stubs>
                        <file name="tests/stubs/partial_class.php" />
                    </stubs>
                </psalm>'
            )
        );

        $filePath = getcwd() . '/src/somefile.php';

        $this->addFile(
            $filePath,
            '<?php
                namespace Foo;

                class PartiallyStubbedClass  {
                    /**
                     * @param string $a
                     * @return object
                     */
                    public function foo(string $a) {
                        return new self;
                    }

                    public function bar(int $i) : void {}
                }

                class A {}

                (new PartiallyStubbedClass())->foo(A::class);
                (new PartiallyStubbedClass())->bar(5);'
        );

        $this->analyzeFile($filePath, new Context());
    }

    /**
     * @expectedException        \Psalm\Exception\CodeException
     * @expectedExceptionMessage TypeCoercion
     *
     * @return                   void
     */
    public function testStubFileWithPartialClassDefinitionWithCoercion()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            TestConfig::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                    </projectFiles>

                    <stubs>
                        <file name="tests/stubs/partial_class.php" />
                    </stubs>
                </psalm>'
            )
        );

        $filePath = getcwd() . '/src/somefile.php';

        $this->addFile(
            $filePath,
            '<?php
                namespace Foo;

                class PartiallyStubbedClass  {
                    /**
                     * @param string $a
                     * @return object
                     */
                    public function foo(string $a) {
                        return new self;
                    }
                }

                (new PartiallyStubbedClass())->foo("dasda");'
        );

        $this->analyzeFile($filePath, new Context());
    }

    /**
     * @expectedException        \Psalm\Exception\CodeException
     * @expectedExceptionMessage InvalidReturnStatement
     *
     * @return                   void
     */
    public function testStubFileWithPartialClassDefinitionGeneralReturnType()
    {
        $this->projectChecker = $this->getProjectCheckerWithConfig(
            TestConfig::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                    </projectFiles>

                    <stubs>
                        <file name="tests/stubs/partial_class.php" />
                    </stubs>
                </psalm>'
            )
        );

        $filePath = getcwd() . '/src/somefile.php';

        $this->addFile(
            $filePath,
            '<?php
                namespace Foo;

                class PartiallyStubbedClass  {
                    /**
                     * @param string $a
                     * @return object
                     */
                    public function foo(string $a) {
                        return new \stdClass;
                    }
                }'
        );

        $this->analyzeFile($filePath, new Context());
    }
}
