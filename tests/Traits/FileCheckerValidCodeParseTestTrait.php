<?php
namespace Psalm\Tests\Traits;

use Psalm\Config;
use Psalm\Context;
use Psalm\Type\Union;

trait FileCheckerValidCodeParseTestTrait
{
    /**
     * @return array
     */
    abstract public function providerFileCheckerValidCodeParse();

    /**
     * @dataProvider providerFileCheckerValidCodeParse
     *
     * @param string $code
     * @param array<string, string> $assertions
     * @param array<string|int, string> $errorLevels
     * @param array<string, Union> $scopeVars
     *
     * @small
     *
     * @return void
     */
    public function testValidCode($code, $assertions = [], $errorLevels = [], $scopeVars = [])
    {
        $testName = $this->getTestName();
        if (strpos($testName, 'PHP7-') !== false) {
            if (version_compare(PHP_VERSION, '7.0.0dev', '<')) {
                $this->markTestSkipped('Test case requires PHP 7.');

                return;
            }
        } elseif (strpos($testName, 'PHP71-') !== false) {
            if (version_compare(PHP_VERSION, '7.1.0', '<')) {
                $this->markTestSkipped('Test case requires PHP 7.1.');

                return;
            }
        } elseif (strpos($testName, 'SKIPPED-') !== false) {
            $this->markTestSkipped('Skipped due to a bug.');
        }

        foreach ($errorLevels as $errorLevelKey => $errorLevel) {
            if (is_int($errorLevelKey)) {
                $issueName = $errorLevel;
                $errorLevel = Config::REPORT_SUPPRESS;
            } else {
                $issueName = $errorLevelKey;
            }

            Config::getInstance()->setCustomErrorLevel($issueName, $errorLevel);
        }

        $context = new Context();
        foreach ($scopeVars as $var => $value) {
            $context->varsInScope[$var] = $value;
        }

        $filePath = self::$srcDirPath . 'somefile.php';

        $this->addFile($filePath, $code);
        $this->analyzeFile($filePath, $context);

        $actualVars = [];
        foreach ($assertions as $var => $_) {
            if (isset($context->varsInScope[$var])) {
                $actualVars[$var] = (string)$context->varsInScope[$var];
            }
        }

        $this->assertSame($assertions, $actualVars);
    }
}
