<?php
namespace Psalm\Scanner;

use PhpParser;
use PhpParser\NodeTraverser;
use Psalm\Codebase;
use Psalm\FileSource;
use Psalm\Storage\FileStorage;
use Psalm\Visitor\DependencyFinderVisitor;

class FileScanner implements FileSource
{
    /**
     * @var string
     */
    public $filePath;

    /**
     * @var string
     */
    public $fileName;

    /**
     * @var bool
     */
    public $willAnalyze;

    /**
     * @param string $filePath
     * @param string $fileName
     * @param bool $willAnalyze
     */
    public function __construct($filePath, $fileName, $willAnalyze)
    {
        $this->filePath = $filePath;
        $this->fileName = $fileName;
        $this->willAnalyze = $willAnalyze;
    }

    /**
     * @param array<mixed, PhpParser\Node> $stmts
     * @param bool $storageFromCache
     * @param bool $debugOutput
     *
     * @return void
     */
    public function scan(
        Codebase $codebase,
        FileStorage $fileStorage,
        $storageFromCache = false,
        $debugOutput = false
    ) {
        if ((!$this->willAnalyze || $fileStorage->deepScan)
            && $storageFromCache
            && !$fileStorage->hasTrait
            && !$codebase->registerStubFiles
        ) {
            return;
        }

        $stmts = $codebase->statementsProvider->getStatementsForFile(
            $fileStorage->filePath,
            $debugOutput
        );

        foreach ($stmts as $stmt) {
            if (!$stmt instanceof PhpParser\Node\Stmt\ClassLike
                && !$stmt instanceof PhpParser\Node\Stmt\Function_
                && !$stmt instanceof PhpParser\Node\Expr\Include_
            ) {
                $fileStorage->hasExtraStatements = true;
                break;
            }
        }

        if ($debugOutput) {
            if ($this->willAnalyze) {
                echo 'Deep scanning ' . $fileStorage->filePath . "\n";
            } else {
                echo 'Scanning ' . $fileStorage->filePath . "\n";
            }
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new DependencyFinderVisitor($codebase, $fileStorage, $this));
        $traverser->traverse($stmts);

        $fileStorage->deepScan = $this->willAnalyze;
    }

    /**
     * @return string
     */
    public function getFilePath()
    {
        return $this->filePath;
    }

    /**
     * @return string
     */
    public function getFileName()
    {
        return $this->fileName;
    }

    /**
     * @return string
     */
    public function getRootFilePath()
    {
        return $this->filePath;
    }

    /**
     * @return string
     */
    public function getRootFileName()
    {
        return $this->fileName;
    }

    /**
     * @return \Psalm\Aliases
     */
    public function getAliases()
    {
        return new \Psalm\Aliases();
    }
}
