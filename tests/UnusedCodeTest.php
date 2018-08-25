<?php
namespace Psalm\Tests;

use Psalm\Checker\FileChecker;
use Psalm\Config;
use Psalm\Context;

class UnusedCodeTest extends TestCase
{
    /** @var \Psalm\Checker\ProjectChecker */
    protected $projectChecker;

    /**
     * @return void
     */
    public function setUp()
    {
        FileChecker::clearCache();

        $this->fileProvider = new Provider\FakeFileProvider();

        $this->projectChecker = new \Psalm\Checker\ProjectChecker(
            new TestConfig(),
            $this->fileProvider,
            new Provider\FakeParserCacheProvider(),
            new \Psalm\Provider\NoCache\NoFileStorageCacheProvider(),
            new \Psalm\Provider\NoCache\NoClassLikeStorageCacheProvider()
        );

        $this->projectChecker->getCodebase()->reportUnusedCode();
    }

    /**
     * @dataProvider providerFileCheckerValidCodeParse
     *
     * @param string $code
     * @param array<string> $errorLevels
     *
     * @return void
     */
    public function testValidCode($code, array $errorLevels = [])
    {
        $testName = $this->getTestName();
        if (strpos($testName, 'PHP7-') !== false) {
            if (version_compare(PHP_VERSION, '7.0.0dev', '<')) {
                $this->markTestSkipped('Test case requires PHP 7.');

                return;
            }
        } elseif (strpos($testName, 'SKIPPED-') !== false) {
            $this->markTestSkipped('Skipped due to a bug.');
        }

        $filePath = self::$srcDirPath . 'somefile.php';

        $this->addFile(
            $filePath,
            $code
        );

        foreach ($errorLevels as $errorLevel) {
            $this->projectChecker->config->setCustomErrorLevel($errorLevel, Config::REPORT_SUPPRESS);
        }

        $context = new Context();
        $context->collectReferences = true;

        $this->analyzeFile($filePath, $context);

        $this->projectChecker->checkClassReferences();
    }

    /**
     * @dataProvider providerFileCheckerInvalidCodeParse
     *
     * @param string $code
     * @param string $errorMessage
     * @param array<string> $errorLevels
     *
     * @return void
     */
    public function testInvalidCode($code, $errorMessage, $errorLevels = [])
    {
        if (strpos($this->getTestName(), 'SKIPPED-') !== false) {
            $this->markTestSkipped();
        }

        $this->expectException('\Psalm\Exception\CodeException');
        $this->expectExceptionMessageRegexp('/\b' . preg_quote($errorMessage, '/') . '\b/');

        $filePath = self::$srcDirPath . 'somefile.php';

        foreach ($errorLevels as $errorLevel) {
            $this->projectChecker->config->setCustomErrorLevel($errorLevel, Config::REPORT_SUPPRESS);
        }

        $this->addFile(
            $filePath,
            $code
        );

        $context = new Context();
        $context->collectReferences = true;

        $this->analyzeFile($filePath, $context);

        $this->projectChecker->checkClassReferences();
    }

    /**
     * @return array
     */
    public function providerFileCheckerValidCodeParse()
    {
        return [
            'magicCall' => [
                '<?php
                    class A {
                        /** @var string */
                        private $value = "default";

                        /** @param string[] $args */
                        public function __call(string $name, array $args) {
                            if (count($args) == 1) {
                                $this->modify($name, $args[0]);
                            }
                        }

                        private function modify(string $name, string $value): void {
                            call_user_func(array($this, "modify_" . $name), $value);
                        }

                        public function modifyFoo(string $value): void {
                            $this->value = $value;
                        }

                        public function getFoo() : string {
                            return $this->value;
                        }
                    }

                    $m = new A();
                    $m->foo("value");
                    $m->modifyFoo("value2");
                    echo $m->getFoo();',
            ],
            'usedTraitMethodWithExplicitCall' => [
                '<?php
                    class A {
                        public function foo(): void {
                            echo "parent method";
                        }
                    }

                    trait T {
                        public function foo(): void {
                            echo "trait method";
                        }
                    }

                    class B extends A {
                        use T;
                    }

                    (new A)->foo();
                    (new B)->foo();',
            ],
            'usedInterfaceMethod' => [
                '<?php
                    interface I {
                        public function foo(): void;
                    }

                    class A implements I {
                        public function foo(): void {}
                    }

                    (new A)->foo();',
            ],
            'constructorIsUsed' => [
                '<?php
                    class A {
                        public function __construct() {
                            $this->foo();
                        }
                        private function foo() : void {}
                    }
                    $a = new A();
                    echo (bool) $a;',
            ],
            'everythingUsed' => [
                '<?php
                    interface I {
                        public function foo();
                    }
                    class B implements I {
                        public function foo() : void {}
                    }

                    class A
                    {
                        /**
                         * @var I
                         */
                        private $i;

                        /**
                         * @param int[] $as
                         */
                        public function __construct(array $as) {

                            foreach ($as as $a) {
                                $this->a($a, 1);
                            }

                            $this->i = new B();
                        }

                        private function a(int $a, int $b): self
                        {
                            $this->v($a, $b);

                            $this->i->foo();

                            return $this;
                        }

                        private function v(int $a, int $b): void
                        {
                            if ($a + $b > 0) {
                                throw new \RuntimeException("");
                            }
                        }
                    }

                    new A([1, 2, 3]);',
            ],
            'unusedParamWithUnderscore' => [
                '<?php
                    function foo(int $_) : void {}

                    foo(4);',
            ],
            'unusedParamWithUnusedPrefix' => [
                '<?php
                    function foo(int $unusedArg) : void {}

                    foo(4);',
            ],
            'possiblyUnusedParamWithUnderscore' => [
                '<?php
                    class A {
                        public static function foo(int $_ = null) : void {}
                    }

                    A::foo();',
            ],
            'possiblyUnusedParamWithUnusedPrefix' => [
                '<?php
                    class A {
                        public static function foo(int $unusedArg = null) : void {}
                    }

                    A::foo();',
            ],
            'usedClass' => [
                '<?php
                    class A { }
                    new A();',
            ],
            'usedTraitMethodWithImplicitCall' => [
                '<?php
                    class A {
                        public function foo() : void {}
                    }
                    trait T {
                        public function foo() : void {}
                    }
                    class B extends A {
                        use T;
                    }
                    function takesA(A $a) : void {
                        $a->foo();
                    }
                    takesA(new B);'
            ],
            'usedMethodInTryCatch' => [
                '<?php
                    class A {
                        protected function getC() : C {
                            return new C;
                        }
                    }
                    class C {
                        public function foo() : void {}
                    }

                    class B extends A {
                        public function bar() : void {
                            $c = $this->getC();

                            foreach ([1, 2, 3] as $i) {
                                try {
                                    $c->foo();
                                } catch (Exception $e) {}
                            }
                        }
                    }

                    (new B)->bar();',
            ],
            'suppressPrivateUnusedMethod' => [
                '<?php
                    class A {
                        /**
                         * @psalm-suppress UnusedMethod
                         * @return void
                         */
                        private function foo() {}
                    }

                    new A();',
            ],
        ];
    }

    /**
     * @return array
     */
    public function providerFileCheckerInvalidCodeParse()
    {
        return [
            'unusedClass' => [
                '<?php
                    class A { }',
                'error_message' => 'UnusedClass',
            ],
            'publicUnusedMethod' => [
                '<?php
                    class A {
                        /** @return void */
                        public function foo() {}
                    }

                    new A();',
                'error_message' => 'PossiblyUnusedMethod',
            ],
            'possiblyUnusedParam' => [
                '<?php
                    class A {
                        /** @return void */
                        public function foo(int $i) {}
                    }

                    (new A)->foo(4);',
                'error_message' => 'PossiblyUnusedParam',
            ],
            'unusedParam' => [
                '<?php
                    function foo(int $i) {}

                    foo(4);',
                'error_message' => 'UnusedParam',
            ],
            'possiblyUnusedProperty' => [
                '<?php
                    class A {
                        /** @var string */
                        public $foo = "hello";
                    }

                    $a = new A();',
                'error_message' => 'PossiblyUnusedProperty',
                'error_levels' => ['UnusedVariable'],
            ],
            'unusedProperty' => [
                '<?php
                    class A {
                        /** @var string */
                        private $foo = "hello";
                    }

                    $a = new A();',
                'error_message' => 'UnusedProperty',
                'error_levels' => ['UnusedVariable'],
            ],
            'privateUnusedMethod' => [
                '<?php
                    class A {
                        /** @return void */
                        private function foo() {}
                    }

                    new A();',
                'error_message' => 'UnusedMethod',
            ],
            'unevaluatedCode' => [
                '<?php
                    function foo(): void {
                        return;
                        $a = "foo";
                    }',
                'error_message' => 'UnevaluatedCode',
            ],
            'unusedTraitMethodInParent' => [
                '<?php
                    class A {
                        public function foo() : void {}
                    }
                    trait T {
                        public function foo() : void {}

                        public function bar() : void {}
                    }
                    class B extends A {
                        use T;
                    }
                    function takesA(A $a) : void {
                        $a->foo();
                    }
                    takesA(new B);',
                'error_message' => 'PossiblyUnusedMethod',
            ],
            'unusedRecursivelyUsedMethod' => [
                '<?php
                    class C {
                        public function foo() : void {
                            if (rand(0, 1)) {
                                $this->foo();
                            }
                        }

                        public function bar() : void {}
                    }

                    (new C)->bar();',
                'error_message' => 'PossiblyUnusedMethod',
            ],
            'unusedRecursivelyUsedStaticMethod' => [
                '<?php
                    class C {
                        public static function foo() : void {
                            if (rand(0, 1)) {
                                self::foo();
                            }
                        }

                        public function bar() : void {}
                    }

                    (new C)->bar();',
                'error_message' => 'PossiblyUnusedMethod',
            ],
        ];
    }
}
