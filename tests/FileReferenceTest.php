<?php
namespace Psalm\Tests;

use Psalm\Checker\FileChecker;
use Psalm\Context;

class FileReferenceTest extends TestCase
{
    /** @var \Psalm\Checker\ProjectChecker */
    protected $projectChecker;

    /**
     * @return void
     */
    public function setUp()
    {
        FileChecker::clearCache();
        \Psalm\FileManipulation\FunctionDocblockManipulator::clearCache();

        $this->fileProvider = new Provider\FakeFileProvider();

        $this->projectChecker = new \Psalm\Checker\ProjectChecker(
            new TestConfig(),
            $this->fileProvider,
            new Provider\FakeParserCacheProvider(),
            new \Psalm\Provider\NoCache\NoFileStorageCacheProvider(),
            new \Psalm\Provider\NoCache\NoClassLikeStorageCacheProvider()
        );

        $this->projectChecker->getCodebase()->collectReferences();
    }

    /**
     * @dataProvider providerFileCheckerValidCodeParse
     *
     * @param string $inputCode
     * @param string $symbol
     * @param array<int, string> $expectedLocations
     *
     * @return void
     */
    public function testValidCode($inputCode, $symbol, $expectedLocations)
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

        $context = new Context();

        $filePath = self::$srcDirPath . 'somefile.php';

        $this->addFile($filePath, $inputCode);

        $this->analyzeFile($filePath, $context);

        $foundReferences = $this->projectChecker->getCodebase()->findReferencesToSymbol($symbol);

        if (!isset($foundReferences[$filePath])) {
            throw new \UnexpectedValueException('No file references found in this file');
        }

        $fileReferences = $foundReferences[$filePath];

        $this->assertSame(count($fileReferences), count($expectedLocations));

        foreach ($expectedLocations as $i => $expectedLocation) {
            $actualLocation = $fileReferences[$i];

            $this->assertSame(
                $expectedLocation,
                $actualLocation->getLineNumber() . ':' . $actualLocation->getColumn()
                    . ':' . $actualLocation->getSelectedText()
            );
        }
    }

    /**
     * @return array
     */
    public function providerFileCheckerValidCodeParse()
    {
        return [
            'getClassLocation' => [
                '<?php
                    class A {}

                    new A();',
                'A',
                ['4:25:A'],
            ],
            'getMethodLocation' => [
                '<?php
                    class A {
                        public function foo(): void {}
                    }

                    (new A())->foo();',
                'A::foo',
                ['6:21:(new A())->foo()'],
            ],
        ];
    }
}
