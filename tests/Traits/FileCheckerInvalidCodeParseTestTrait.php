<?php
namespace Psalm\Tests\Traits;

use Psalm\Config;
use Psalm\Context;

trait FileCheckerInvalidCodeParseTestTrait
{
    /**
     * @return array
     */
    abstract public function providerFileCheckerInvalidCodeParse();

    /**
     * @dataProvider providerFileCheckerInvalidCodeParse
     * @small
     *
     * @param string $code
     * @param string $errorMessage
     * @param array<string> $errorLevels
     * @param bool $strictMode
     *
     * @return void
     */
    public function testInvalidCode($code, $errorMessage, $errorLevels = [], $strictMode = false)
    {
        if (strpos($this->getTestName(), 'SKIPPED-') !== false) {
            $this->markTestSkipped();
        }

        if ($strictMode) {
            Config::getInstance()->strictBinaryOperands = true;
        }

        foreach ($errorLevels as $errorLevel) {
            Config::getInstance()->setCustomErrorLevel($errorLevel, Config::REPORT_SUPPRESS);
        }

        $this->expectException('\Psalm\Exception\CodeException');
        $this->expectExceptionMessageRegexp('/\b' . preg_quote($errorMessage, '/') . '\b/');

        $filePath = self::$srcDirPath . 'somefile.php';

        $this->addFile($filePath, $code);
        $this->analyzeFile($filePath, new Context());
    }
}
