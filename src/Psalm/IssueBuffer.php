<?php
namespace Psalm;

use LSS\Array2XML;
use Psalm\Checker\ProjectChecker;
use Psalm\Issue\ClassIssue;
use Psalm\Issue\CodeIssue;
use Psalm\Issue\MethodIssue;
use Psalm\Issue\PropertyIssue;

class IssueBuffer
{
    /**
     * @var array<int, array{severity: string, line_from: int, line_to: int, type: string, message: string,
     * file_name: string, file_path: string, snippet: string, from: int, to: int,
     * snippet_from: int, snippet_to: int, column_from: int, column_to: int}>
     */
    protected static $issuesData = [];

    /**
     * @var array<int, array>
     */
    protected static $consoleIssues = [];

    /**
     * @var int
     */
    protected static $errorCount = 0;

    /**
     * @var array<string, bool>
     */
    protected static $emitted = [];

    /** @var int */
    protected static $recordingLevel = 0;

    /** @var array<int, array<int, CodeIssue>> */
    protected static $recordedIssues = [];

    /**
     * @param   CodeIssue $e
     * @param   array     $suppressedIssues
     *
     * @return  bool
     */
    public static function accepts(CodeIssue $e, array $suppressedIssues = [])
    {
        $config = Config::getInstance();

        $fqcnParts = explode('\\', get_class($e));
        $issueType = array_pop($fqcnParts);

        if (in_array($issueType, $suppressedIssues, true)) {
            return false;
        }

        if (!$config->reportIssueInFile($issueType, $e->getFilePath())) {
            return false;
        }

        if ($e instanceof ClassIssue
            && $config->getReportingLevelForClass($issueType, $e->fqClasslikeName) === Config::REPORT_SUPPRESS
        ) {
            return false;
        }

        if ($e instanceof MethodIssue
            && $config->getReportingLevelForMethod($issueType, $e->methodId) === Config::REPORT_SUPPRESS
        ) {
            return false;
        }

        if ($e instanceof PropertyIssue
            && $config->getReportingLevelForProperty($issueType, $e->propertyId) === Config::REPORT_SUPPRESS
        ) {
            return false;
        }

        $parentIssueType = self::getParentIssueType($issueType);

        if ($parentIssueType) {
            if (in_array($parentIssueType, $suppressedIssues, true)) {
                return false;
            }

            if (!$config->reportIssueInFile($parentIssueType, $e->getFilePath())) {
                return false;
            }
        }

        if (self::$recordingLevel > 0) {
            self::$recordedIssues[self::$recordingLevel][] = $e;

            return false;
        }

        return self::add($e);
    }

    /**
     * @param  string $issueType
     * @return string|null
     */
    private static function getParentIssueType($issueType)
    {
        if (strpos($issueType, 'Possibly') === 0) {
            $strippedIssueType = preg_replace('/^Possibly(False|Null)?/', '', $issueType);

            if (strpos($strippedIssueType, 'Invalid') === false && strpos($strippedIssueType, 'Un') !== 0) {
                $strippedIssueType = 'Invalid' . $strippedIssueType;
            }

            return $strippedIssueType;
        }

        if (preg_match('/^(False|Null)[A-Z]/', $issueType)) {
            return preg_replace('/^(False|Null)/', 'Invalid', $issueType);
        }

        return null;
    }

    /**
     * @param   CodeIssue $e
     *
     * @throws  Exception\CodeException
     *
     * @return  bool
     */
    public static function add(CodeIssue $e)
    {
        $config = Config::getInstance();

        $fqcnParts = explode('\\', get_class($e));
        $issueType = array_pop($fqcnParts);

        $projectChecker = ProjectChecker::getInstance();

        if (!$projectChecker->showIssues) {
            return false;
        }

        $errorMessage = $issueType . ' - ' . $e->getShortLocation() . ' - ' . $e->getMessage();

        $reportingLevel = $config->getReportingLevelForFile($issueType, $e->getFilePath());

        $parentIssueType = self::getParentIssueType($issueType);

        if ($parentIssueType && $reportingLevel === Config::REPORT_ERROR) {
            $parentReportingLevel = $config->getReportingLevelForFile($parentIssueType, $e->getFilePath());

            if ($parentReportingLevel !== $reportingLevel) {
                $reportingLevel = $parentReportingLevel;
            }
        }

        if ($reportingLevel === Config::REPORT_SUPPRESS) {
            return false;
        }

        if ($reportingLevel === Config::REPORT_INFO) {
            if ($projectChecker->showInfo && !self::alreadyEmitted($errorMessage)) {
                self::$issuesData[] = $e->toArray(Config::REPORT_INFO);
            }

            return false;
        }

        if ($config->throwException) {
            throw new Exception\CodeException($errorMessage);
        }

        if (!self::alreadyEmitted($errorMessage)) {
            self::$issuesData[] = $e->toArray(Config::REPORT_ERROR);
        }

        return true;
    }

    /**
     * @param  array{severity: string, line_from: int, line_to: int, type: string, message: string,
     *  file_name: string, file_path: string, snippet: string, from: int, to: int,
     *  snippet_from: int, snippet_to: int, column_from: int, column_to: int} $issueData
     *
     * @return string
     */
    protected static function getEmacsOutput(array $issueData)
    {
        return $issueData['file_path'] . ':' . $issueData['line_from'] . ':' . $issueData['column_from'] . ':' .
            ($issueData['severity'] === Config::REPORT_ERROR ? 'error' : 'warning') . ' - ' . $issueData['message'];
    }

    /**
     * @param  array{severity: string, line_from: int, line_to: int, type: string, message: string,
     *  file_name: string, file_path: string, snippet: string, from: int, to: int,
     *  snippet_from: int, snippet_to: int, column_from: int, column_to: int} $issueData
     *
     * @return string
     */
    protected static function getPylintOutput(array $issueData)
    {
        $message = sprintf(
            '%s: %s',
            $issueData['type'],
            $issueData['message']
        );
        if ($issueData['severity'] === Config::REPORT_ERROR) {
            $code = 'E0001';
        } else {
            $code = 'W0001';
        }

        // https://docs.pylint.org/en/1.6.0/output.html doesn't mention what to do about 'column',
        // but it's still useful for users.
        // E.g. jenkins can't parse %s:%d:%d.
        $message = sprintf('%s (column %d)', $message, $issueData['column_from']);
        $issueString = sprintf(
            '%s:%d: [%s] %s',
            $issueData['file_name'],
            $issueData['line_from'],
            $code,
            $message
        );

        return $issueString;
    }

    /**
     * @param  array{severity: string, line_from: int, line_to: int, type: string, message: string,
     *  file_name: string, file_path: string, snippet: string, from: int, to: int,
     *  snippet_from: int, snippet_to: int, column_from: int, column_to: int} $issueData
     * @param  bool  $includeSnippet
     * @param  bool  $useColor
     *
     * @return string
     */
    protected static function getConsoleOutput(array $issueData, $useColor, $includeSnippet = true)
    {
        $issueString = '';

        $isError = $issueData['severity'] === Config::REPORT_ERROR;

        if ($isError) {
            $issueString .= ($useColor ? "\e[0;31mERROR\e[0m" : 'ERROR');
        } else {
            $issueString .= 'INFO';
        }

        $issueString .= ': ' . $issueData['type'] . ' - ' . $issueData['file_name'] . ':' .
            $issueData['line_from'] . ':' . $issueData['column_from'] . ' - ' . $issueData['message'] . "\n";

        if ($includeSnippet) {
            $snippet = $issueData['snippet'];

            if (!$useColor) {
                $issueString .= $snippet;
            } else {
                $selectionStart = $issueData['from'] - $issueData['snippet_from'];
                $selectionLength = $issueData['to'] - $issueData['from'];

                $issueString .= substr($snippet, 0, $selectionStart)
                    . ($isError ? "\e[97;41m" : "\e[30;47m") . substr($snippet, $selectionStart, $selectionLength)
                    . "\e[0m" . substr($snippet, $selectionLength + $selectionStart) . "\n";
            }
        }

        return $issueString;
    }

    /**
     * @return array<int, array{severity: string, line_from: int, type: string, message: string, file_name: string,
     *  file_path: string, snippet: string, from: int, to: int, snippet_from: int, snippet_to: int, column_from: int,
     *  column_to: int}>
     */
    public static function getIssuesData()
    {
        return self::$issuesData;
    }

    /**
     * @param array<int, array{severity: string, line_from: int, line_to: int, type: string, message: string,
     *  file_name: string, file_path: string, snippet: string, from: int, to: int, snippet_from: int,
     *  snippet_to: int, column_from: int, column_to: int}> $issuesData
     *
     * @return void
     */
    public static function addIssues(array $issuesData)
    {
        self::$issuesData = array_merge($issuesData, self::$issuesData);
    }

    /**
     * @param  ProjectChecker       $projectChecker
     * @param  bool                 $isFull
     * @param  float                $startTime
     * @param  bool                 $addStats
     *
     * @return void
     */
    public static function finish(
        ProjectChecker $projectChecker,
        $isFull,
        $startTime,
        $addStats = false
    ) {
        $scannedFiles = $projectChecker->codebase->scanner->getScannedFiles();
        Provider\FileReferenceProvider::updateReferenceCache($projectChecker, $scannedFiles);

        if ($projectChecker->outputFormat === ProjectChecker::TYPE_CONSOLE) {
            echo "\n";
        }

        $errorCount = 0;
        $infoCount = 0;

        if (self::$issuesData) {
            usort(
                self::$issuesData,
                /** @return int */
                function (array $d1, array $d2) {
                    if ($d1['file_path'] === $d2['file_path']) {
                        if ($d1['line_from'] === $d2['line_from']) {
                            if ($d1['column_from'] === $d2['column_from']) {
                                return 0;
                            }

                            return $d1['column_from'] > $d2['column_from'] ? 1 : -1;
                        }

                        return $d1['line_from'] > $d2['line_from'] ? 1 : -1;
                    }

                    return $d1['file_path'] > $d2['file_path'] ? 1 : -1;
                }
            );

            foreach (self::$issuesData as $issueData) {
                if ($issueData['severity'] === Config::REPORT_ERROR) {
                    ++$errorCount;
                } else {
                    ++$infoCount;
                }
            }

            echo self::getOutput(
                $projectChecker->outputFormat,
                $projectChecker->useColor,
                $projectChecker->showSnippet
            );
        }

        foreach ($projectChecker->reports as $format => $path) {
            file_put_contents(
                $path,
                self::getOutput($format, $projectChecker->useColor)
            );
        }

        if ($projectChecker->outputFormat === ProjectChecker::TYPE_CONSOLE) {
            echo str_repeat('-', 30) . "\n";

            if ($errorCount) {
                echo ($projectChecker->useColor
                    ? "\e[0;31m" . $errorCount . " errors\e[0m"
                    : $errorCount . ' errors'
                ) . ' found' . "\n";
            } else {
                echo 'No errors found!' . "\n";
            }

            if ($infoCount) {
                echo str_repeat('-', 30) . "\n";

                echo $infoCount . ' other issues found.' . "\n"
                    . 'You can hide them with ' .
                    ($projectChecker->useColor
                        ? "\e[30;48;5;195m--show-info=false\e[0m"
                        : '--show-info=false') . "\n";
            }

            echo str_repeat('-', 30) . "\n" . "\n";

            if ($startTime) {
                echo 'Checks took ' . number_format((float)microtime(true) - $startTime, 2) . ' seconds';
                echo ' and used ' . number_format(memory_get_peak_usage() / (1024 * 1024), 3) . 'MB of memory' . "\n";

                if ($isFull) {
                    $analysisSummary = $projectChecker->codebase->analyzer->getTypeInferenceSummary();
                    echo $analysisSummary . "\n";
                }

                if ($addStats) {
                    echo '-----------------' . "\n";
                    echo $projectChecker->codebase->analyzer->getNonMixedStats();
                    echo "\n";
                }
            }
        }

        if ($errorCount) {
            exit(1);
        }

        if ($isFull && $startTime) {
            $projectChecker->cacheProvider->processSuccessfulRun($startTime);
        }
    }

    /**
     * @param string $format
     * @param bool   $useColor
     * @param bool   $showSnippet
     *
     * @return string
     */
    public static function getOutput($format, $useColor, $showSnippet = true)
    {
        if ($format === ProjectChecker::TYPE_JSON) {
            return json_encode(self::$issuesData) . "\n";
        } elseif ($format === ProjectChecker::TYPE_XML) {
            $xml = Array2XML::createXML('report', ['item' => self::$issuesData]);

            return $xml->saveXML();
        } elseif ($format === ProjectChecker::TYPE_EMACS) {
            $output = '';
            foreach (self::$issuesData as $issueData) {
                $output .= self::getEmacsOutput($issueData) . "\n";
            }

            return $output;
        } elseif ($format === ProjectChecker::TYPE_PYLINT) {
            $output = '';
            foreach (self::$issuesData as $issueData) {
                $output .= self::getPylintOutput($issueData) . "\n";
            }

            return $output;
        }

        $output = '';
        foreach (self::$issuesData as $issueData) {
            $output .= self::getConsoleOutput($issueData, $useColor, $showSnippet) . "\n" . "\n";
        }

        return $output;
    }

    /**
     * @param  string $message
     *
     * @return bool
     */
    protected static function alreadyEmitted($message)
    {
        $sham = sha1($message);

        if (isset(self::$emitted[$sham])) {
            return true;
        }

        self::$emitted[$sham] = true;

        return false;
    }

    /**
     * @return void
     */
    public static function clearCache()
    {
        self::$issuesData = [];
        self::$emitted = [];
        self::$errorCount = 0;
        self::$recordingLevel = 0;
        self::$recordedIssues = [];
        self::$consoleIssues = [];
    }

    /**
     * @return bool
     */
    public static function isRecording()
    {
        return self::$recordingLevel > 0;
    }

    /**
     * @return void
     */
    public static function startRecording()
    {
        ++self::$recordingLevel;
        self::$recordedIssues[self::$recordingLevel] = [];
    }

    /**
     * @return void
     */
    public static function stopRecording()
    {
        if (self::$recordingLevel === 0) {
            throw new \UnexpectedValueException('Cannot stop recording - already at base level');
        }

        --self::$recordingLevel;
    }

    /**
     * @return array<int, CodeIssue>
     */
    public static function clearRecordingLevel()
    {
        if (self::$recordingLevel === 0) {
            throw new \UnexpectedValueException('Not currently recording');
        }

        $recordedIssues = self::$recordedIssues[self::$recordingLevel];

        self::$recordedIssues[self::$recordingLevel] = [];

        return $recordedIssues;
    }

    /**
     * @return void
     */
    public static function bubbleUp(CodeIssue $e)
    {
        if (self::$recordingLevel === 0) {
            self::add($e);

            return;
        }

        self::$recordedIssues[self::$recordingLevel][] = $e;
    }
}
