<?php
namespace Psalm\Examples\Template;

use PhpParser;
use Psalm;
use Psalm\Checker\CommentChecker;
use Psalm\Codebase;
use Psalm\Storage\FileStorage;

class TemplateScanner extends Psalm\Scanner\FileScanner
{
    const VIEW_CLASS = 'Your\\View\\Class';

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
        $stmts = $codebase->statementsProvider->getStatementsForFile(
            $fileStorage->filePath,
            $debugOutput
        );

        if (empty($stmts)) {
            return;
        }

        $firstStmt = $stmts[0];

        if (($firstStmt instanceof PhpParser\Node\Stmt\Nop) && ($docComment = $firstStmt->getDocComment())) {
            $commentBlock = CommentChecker::parseDocComment(trim($docComment->getText()));

            if (isset($commentBlock['specials']['variablesfrom'])) {
                $variablesFrom = trim($commentBlock['specials']['variablesfrom'][0]);

                $firstLineRegex = '/([A-Za-z\\\0-9]+::[a-z_A-Z]+)(\s+weak)?/';

                $matches = [];

                if (!preg_match($firstLineRegex, $variablesFrom, $matches)) {
                    throw new \InvalidArgumentException('Could not interpret doc comment correctly');
                }

                /** @psalm-suppress MixedArgument */
                list($fqClassName) = explode('::', $matches[1]);

                $codebase->scanner->queueClassLikeForScanning(
                    $fqClassName,
                    $this->filePath,
                    true
                );
            }
        }

        $codebase->scanner->queueClassLikeForScanning(self::VIEW_CLASS, $this->filePath);

        parent::scan($codebase, $fileStorage, $storageFromCache, $debugOutput);
    }
}
