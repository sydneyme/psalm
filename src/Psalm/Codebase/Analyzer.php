<?php
namespace Psalm\Codebase;

use Psalm\Checker\FileChecker;
use Psalm\Checker\ProjectChecker;
use Psalm\Config;
use Psalm\FileManipulation\FileManipulation;
use Psalm\FileManipulation\FileManipulationBuffer;
use Psalm\FileManipulation\FunctionDocblockManipulator;
use Psalm\IssueBuffer;
use Psalm\Provider\FileProvider;
use Psalm\Provider\FileReferenceProvider;
use Psalm\Provider\FileStorageProvider;

/**
 * @internal
 *
 * Called in the analysis phase of Psalm's execution
 */
class Analyzer
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var FileProvider
     */
    private $fileProvider;

    /**
     * @var FileStorageProvider
     */
    private $fileStorageProvider;

    /**
     * @var bool
     */
    private $debugOutput;

    /**
     * Used to store counts of mixed vs non-mixed variables
     *
     * @var array<string, array{0: int, 1: int}
     */
    private $mixedCounts = [];

    /**
     * @var bool
     */
    private $countMixed = true;

    /**
     * We analyze more files than we necessarily report errors in
     *
     * @var array<string, string>
     */
    private $filesToAnalyze = [];

    /**
     * @param bool $debugOutput
     */
    public function __construct(
        Config $config,
        FileProvider $fileProvider,
        FileStorageProvider $fileStorageProvider,
        $debugOutput
    ) {
        $this->config = $config;
        $this->fileProvider = $fileProvider;
        $this->fileStorageProvider = $fileStorageProvider;
        $this->debugOutput = $debugOutput;
    }

    /**
     * @param array<string, string> $filesToAnalyze
     *
     * @return void
     */
    public function addFiles(array $filesToAnalyze)
    {
        $this->filesToAnalyze += $filesToAnalyze;
    }

    /**
     * @param  string $filePath
     *
     * @return bool
     */
    public function canReportIssues($filePath)
    {
        return isset($this->filesToAnalyze[$filePath]);
    }

    /**
     * @param  string $filePath
     * @param  array<string, string> $filetypeCheckers
     *
     * @return FileChecker
     *
     * @psalm-suppress MixedOperand
     */
    private function getFileChecker(ProjectChecker $projectChecker, $filePath, array $filetypeCheckers)
    {
        $extension = (string)pathinfo($filePath)['extension'];

        $fileName = $this->config->shortenFileName($filePath);

        if (isset($filetypeCheckers[$extension])) {
            /** @var FileChecker */
            $fileChecker = new $filetypeCheckers[$extension]($projectChecker, $filePath, $fileName);
        } else {
            $fileChecker = new FileChecker($projectChecker, $filePath, $fileName);
        }

        if ($this->debugOutput) {
            echo 'Getting ' . $filePath . "\n";
        }

        return $fileChecker;
    }

    /**
     * @param  ProjectChecker $projectChecker
     * @param  int            $poolSize
     * @param  bool           $alterCode
     *
     * @return void
     */
    public function analyzeFiles(ProjectChecker $projectChecker, $poolSize, $alterCode)
    {
        $filetypeCheckers = $this->config->getFiletypeCheckers();

        $analysisWorker =
            /**
             * @param int $_
             * @param string $filePath
             *
             * @return void
             */
            function ($_, $filePath) use ($projectChecker, $filetypeCheckers) {
                $fileChecker = $this->getFileChecker($projectChecker, $filePath, $filetypeCheckers);

                if ($this->debugOutput) {
                    echo 'Analyzing ' . $fileChecker->getFilePath() . "\n";
                }

                $fileChecker->analyze(null);
            };

        if ($poolSize > 1 && count($this->filesToAnalyze) > $poolSize) {
            $processFilePaths = [];

            $i = 0;

            foreach ($this->filesToAnalyze as $filePath) {
                $processFilePaths[$i % $poolSize][] = $filePath;
                ++$i;
            }

            // Run analysis one file at a time, splitting the set of
            // files up among a given number of child processes.
            $pool = new \Psalm\Fork\Pool(
                $processFilePaths,
                /** @return void */
                function () {
                },
                $analysisWorker,
                /** @return array */
                function () {
                    return [
                        'issues' => IssueBuffer::getIssuesData(),
                        'file_references' => FileReferenceProvider::getAllFileReferences(),
                        'mixed_counts' => ProjectChecker::getInstance()->codebase->analyzer->getMixedCounts(),
                    ];
                }
            );

            // Wait for all tasks to complete and collect the results.
            /**
             * @var array<array{issues: array<int, array{severity: string, line_from: int, line_to: int, type: string,
             *  message: string, file_name: string, file_path: string, snippet: string, from: int, to: int,
             *  snippet_from: int, snippet_to: int, column_from: int, column_to: int}>, file_references: array<string,
             *  array<string,bool>>, mixed_counts: array<string, array{0: int, 1: int}>}>
             */
            $forkedPoolData = $pool->wait();

            foreach ($forkedPoolData as $poolData) {
                IssueBuffer::addIssues($poolData['issues']);
                FileReferenceProvider::addFileReferences($poolData['file_references']);

                foreach ($poolData['mixed_counts'] as $filePath => list($mixedCount, $nonmixedCount)) {
                    if (!isset($this->mixedCounts[$filePath])) {
                        $this->mixedCounts[$filePath] = [$mixedCount, $nonmixedCount];
                    } else {
                        $this->mixedCounts[$filePath][0] += $mixedCount;
                        $this->mixedCounts[$filePath][1] += $nonmixedCount;
                    }
                }
            }

            // TODO: Tell the caller that the fork pool encountered an error in another PR?
            // $didForkPoolHaveError = $pool->didHaveError();
        } else {
            $i = 0;

            foreach ($this->filesToAnalyze as $filePath => $_) {
                $analysisWorker($i, $filePath);
                ++$i;
            }
        }

        if ($alterCode) {
            foreach ($this->filesToAnalyze as $filePath) {
                $this->updateFile($filePath, $projectChecker->dryRun, true);
            }
        }
    }

    /**
     * @param  string $filePath
     *
     * @return array{0:int, 1:int}
     */
    public function getMixedCountsForFile($filePath)
    {
        if (!isset($this->mixedCounts[$filePath])) {
            $this->mixedCounts[$filePath] = [0, 0];
        }

        return $this->mixedCounts[$filePath];
    }

    /**
     * @param  string $filePath
     * @param  array{0:int, 1:int} $mixedCounts
     *
     * @return void
     */
    public function setMixedCountsForFile($filePath, array $mixedCounts)
    {
        $this->mixedCounts[$filePath] = $mixedCounts;
    }

    /**
     * @param  string $filePath
     *
     * @return void
     */
    public function incrementMixedCount($filePath)
    {
        if (!$this->countMixed) {
            return;
        }

        if (!isset($this->mixedCounts[$filePath])) {
            $this->mixedCounts[$filePath] = [0, 0];
        }

        ++$this->mixedCounts[$filePath][0];
    }

    /**
     * @param  string $filePath
     *
     * @return void
     */
    public function incrementNonMixedCount($filePath)
    {
        if (!$this->countMixed) {
            return;
        }

        if (!isset($this->mixedCounts[$filePath])) {
            $this->mixedCounts[$filePath] = [0, 0];
        }

        ++$this->mixedCounts[$filePath][1];
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public function getMixedCounts()
    {
        return $this->mixedCounts;
    }

    /**
     * @return string
     */
    public function getTypeInferenceSummary()
    {
        $mixedCount = 0;
        $nonmixedCount = 0;

        $allDeepScannedFiles = [];

        foreach ($this->filesToAnalyze as $filePath => $_) {
            $allDeepScannedFiles[$filePath] = true;

            foreach ($this->fileStorageProvider->get($filePath)->requiredFilePaths as $requiredFilePath) {
                $allDeepScannedFiles[$requiredFilePath] = true;
            }
        }

        foreach ($allDeepScannedFiles as $filePath => $_) {
            if (!$this->config->reportTypeStatsForFile($filePath)) {
                continue;
            }

            if (isset($this->mixedCounts[$filePath])) {
                list($pathMixedCount, $pathNonmixedCount) = $this->mixedCounts[$filePath];
                $mixedCount += $pathMixedCount;
                $nonmixedCount += $pathNonmixedCount;
            }
        }

        $total = $mixedCount + $nonmixedCount;

        $totalFiles = count($allDeepScannedFiles);

        if (!$totalFiles) {
            return 'No files analyzed';
        }

        if (!$total) {
            return 'Psalm was unable to infer types in any of '
                . $totalFiles . ' file' . ($totalFiles > 1 ? 's' : '');
        }

        return 'Psalm was able to infer types for ' . number_format(100 * $nonmixedCount / $total, 3) . '%'
            . ' of analyzed code (' . $totalFiles . ' file' . ($totalFiles > 1 ? 's' : '') . ')';
    }

    /**
     * @return string
     */
    public function getNonMixedStats()
    {
        $stats = '';

        $allDeepScannedFiles = [];

        foreach ($this->filesToAnalyze as $filePath => $_) {
            $allDeepScannedFiles[$filePath] = true;

            if (!$this->config->reportTypeStatsForFile($filePath)) {
                continue;
            }

            foreach ($this->fileStorageProvider->get($filePath)->requiredFilePaths as $requiredFilePath) {
                $allDeepScannedFiles[$requiredFilePath] = true;
            }
        }

        foreach ($allDeepScannedFiles as $filePath => $_) {
            if (isset($this->mixedCounts[$filePath])) {
                list($pathMixedCount, $pathNonmixedCount) = $this->mixedCounts[$filePath];
                $stats .= number_format(100 * $pathNonmixedCount / ($pathMixedCount + $pathNonmixedCount), 0)
                    . '% ' . $this->config->shortenFileName($filePath)
                    . ' (' . $pathMixedCount . ' mixed)' . "\n";
            }
        }

        return $stats;
    }

    /**
     * @return void
     */
    public function disableMixedCounts()
    {
        $this->countMixed = false;
    }

    /**
     * @return void
     */
    public function enableMixedCounts()
    {
        $this->countMixed = true;
    }

    /**
     * @param  string $filePath
     * @param  bool $dryRun
     * @param  bool $outputChanges to console
     *
     * @return void
     */
    public function updateFile($filePath, $dryRun, $outputChanges = false)
    {
        $newReturnTypeManipulations = FunctionDocblockManipulator::getManipulationsForFile($filePath);

        $otherManipulations = FileManipulationBuffer::getForFile($filePath);

        $fileManipulations = array_merge($newReturnTypeManipulations, $otherManipulations);

        usort(
            $fileManipulations,
            /**
             * @return int
             */
            function (FileManipulation $a, FileManipulation $b) {
                if ($a->start === $b->start) {
                    if ($b->end === $a->end) {
                        return $b->insertionText > $a->insertionText ? 1 : -1;
                    }

                    return $b->end > $a->end ? 1 : -1;
                }

                return $b->start > $a->start ? 1 : -1;
            }
        );

        $docblockUpdateCount = count($fileManipulations);

        $existingContents = $this->fileProvider->getContents($filePath);

        foreach ($fileManipulations as $manipulation) {
            $existingContents
                = substr($existingContents, 0, $manipulation->start)
                    . $manipulation->insertionText
                    . substr($existingContents, $manipulation->end);
        }

        if ($docblockUpdateCount) {
            if ($dryRun) {
                echo $filePath . ':' . "\n";

                $differ = new \PhpCsFixer\Diff\v2_0\Differ(
                    new \PhpCsFixer\Diff\GeckoPackages\DiffOutputBuilder\UnifiedDiffOutputBuilder([
                        'fromFile' => 'Original',
                        'toFile' => 'New',
                    ])
                );

                echo (string) $differ->diff($this->fileProvider->getContents($filePath), $existingContents);

                return;
            }

            if ($outputChanges) {
                echo 'Altering ' . $filePath . "\n";
            }

            $this->fileProvider->setContents($filePath, $existingContents);
        }
    }
}
