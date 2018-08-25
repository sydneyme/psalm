<?php
namespace Psalm\Tests;

use Psalm\Checker\FileChecker;
use Psalm\Config;
use Psalm\Context;

class DocumentationTest extends TestCase
{
    /** @var \Psalm\Checker\ProjectChecker */
    protected $projectChecker;

    /**
     * @return array<string, array<int, string>>
     */
    private static function getCodeBlocksFromDocs()
    {
        $issueFile = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'issues.md';

        if (!file_exists($issueFile)) {
            throw new \UnexpectedValueException('docs not found');
        }

        $fileContents = file_get_contents($issueFile);

        if (!$fileContents) {
            throw new \UnexpectedValueException('Docs are empty');
        }

        $fileLines = explode("\n", $fileContents);

        $issueCode = [];

        $currentIssue = null;

        for ($i = 0, $j = count($fileLines); $i < $j; ++$i) {
            $currentLine = $fileLines[$i];

            if (substr($currentLine, 0, 4) === '### ') {
                $currentIssue = trim(substr($currentLine, 4));
                ++$i;
                continue;
            }

            if (substr($currentLine, 0, 6) === '```php' && $currentIssue) {
                $currentBlock = '';
                ++$i;

                do {
                    $currentBlock .= $fileLines[$i] . "\n";
                    ++$i;
                } while (substr($fileLines[$i], 0, 3) !== '```' && $i < $j);

                $issueCode[(string) $currentIssue][] = trim($currentBlock);
            }
        }

        return $issueCode;
    }

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
    }

    /**
     * @return void
     */
    public function testAllIssuesCovered()
    {
        $allIssues = ConfigTest::getAllIssues();
        sort($allIssues);

        $codeBlocks = self::getCodeBlocksFromDocs();

        // these cannot have code
        $codeBlocks['UnrecognizedExpression'] = true;
        $codeBlocks['UnrecognizedStatement'] = true;

        $documentedIssues = array_keys($codeBlocks);
        sort($documentedIssues);

        $this->assertSame(implode("\n", $allIssues), implode("\n", $documentedIssues));
    }

    /**
     * @dataProvider providerFileCheckerInvalidCodeParse
     * @small
     *
     * @param string $code
     * @param string $errorMessage
     * @param array<string> $errorLevels
     * @param bool $checkReferences
     *
     * @return void
     */
    public function testInvalidCode($code, $errorMessage, $errorLevels = [], $checkReferences = false)
    {
        if (strpos($this->getTestName(), 'SKIPPED-') !== false) {
            $this->markTestSkipped();
        }

        if ($checkReferences) {
            $this->projectChecker->getCodebase()->reportUnusedCode();
        }

        foreach ($errorLevels as $errorLevel) {
            $this->projectChecker->config->setCustomErrorLevel($errorLevel, Config::REPORT_SUPPRESS);
        }

        $this->expectException('\Psalm\Exception\CodeException');
        $this->expectExceptionMessageRegexp('/\b' . preg_quote($errorMessage, '/') . '\b/');

        $filePath = self::$srcDirPath . 'somefile.php';

        $this->addFile($filePath, $code);

        $context = new Context();
        $context->collectReferences = $checkReferences;

        $this->analyzeFile($filePath, $context);

        if ($checkReferences) {
            $this->projectChecker->getCodebase()->classlikes->checkClassReferences();
        }
    }

    /**
     * @return array
     */
    public function providerFileCheckerInvalidCodeParse()
    {
        $invalidCodeData = [];

        foreach (self::getCodeBlocksFromDocs() as $issueName => $blocks) {
            switch ($issueName) {
                case 'MissingThrowsDocblock':
                    continue 2;

                case 'InvalidStringClass':
                    continue 2;

                case 'InvalidFalsableReturnType':
                    $ignoredIssues = ['FalsableReturnStatement'];
                    break;

                case 'InvalidNullableReturnType':
                    $ignoredIssues = ['NullableReturnStatement'];
                    break;

                case 'InvalidReturnType':
                    $ignoredIssues = ['InvalidReturnStatement'];
                    break;

                case 'MixedInferredReturnType':
                    $ignoredIssues = ['MixedReturnStatement'];
                    break;

                case 'MixedStringOffsetAssignment':
                    $ignoredIssues = ['MixedAssignment'];
                    break;

                case 'ParadoxicalCondition':
                    $ignoredIssues = ['MissingParamType'];
                    break;

                case 'UnusedClass':
                case 'UnusedMethod':
                    $ignoredIssues = ['UnusedVariable'];
                    break;

                default:
                    $ignoredIssues = [];
            }

            $invalidCodeData[$issueName] = [
                '<?php' . "\n" . $blocks[0],
                $issueName,
                $ignoredIssues,
                strpos($issueName, 'Unused') !== false || strpos($issueName, 'Unevaluated') !== false,
            ];
        }

        return $invalidCodeData;
    }
}
