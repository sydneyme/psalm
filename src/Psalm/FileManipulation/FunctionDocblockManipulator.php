<?php
namespace Psalm\FileManipulation;

use PhpParser\Node\Expr\Closure;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use Psalm\Checker\CommentChecker;
use Psalm\Checker\ProjectChecker;

class FunctionDocblockManipulator
{
    /** @var array<string, array<string, FunctionDocblockManipulator>> */
    private static $manipulators = [];

    /**
     * Manipulators ordered by line number
     *
     * @var array<string, array<int, FunctionDocblockManipulator>>
     */
    private static $orderedManipulators = [];

    /** @var Closure|Function_|ClassMethod */
    private $stmt;

    /** @var int */
    private $docblockStart;

    /** @var int */
    private $docblockEnd;

    /** @var int */
    private $returnTypehintAreaStart;

    /** @var null|int */
    private $returnTypehintColonStart;

    /** @var null|int */
    private $returnTypehintStart;

    /** @var null|int */
    private $returnTypehintEnd;

    /** @var null|string */
    private $newPhpReturnType;

    /** @var bool */
    private $returnTypeIsPhpCompatible = false;

    /** @var null|string */
    private $newPhpdocReturnType;

    /** @var null|string */
    private $newPsalmReturnType;

    /** @var array<string, int> */
    private $paramTypehintAreaStarts = [];

    /** @var array<string, int> */
    private $paramTypehintStarts = [];

    /** @var array<string, int> */
    private $paramTypehintEnds = [];

    /** @var array<string, string> */
    private $newPhpParamTypes = [];

    /** @var array<string, bool> */
    private $paramTypeIsPhpCompatible = [];

    /** @var array<string, string> */
    private $newPhpdocParamTypes = [];

    /** @var array<string, string> */
    private $newPsalmParamTypes = [];

    /** @var string */
    private $indentation;

    /**
     * @param  string $filePath
     * @param  string $functionId
     * @param  Closure|Function_|ClassMethod $stmt
     *
     * @return self
     */
    public static function getForFunction(
        ProjectChecker $projectChecker,
        $filePath,
        $functionId,
        FunctionLike $stmt
    ) {
        if (isset(self::$manipulators[$filePath][$functionId])) {
            return self::$manipulators[$filePath][$functionId];
        }

        $manipulator
            = self::$manipulators[$filePath][$functionId]
            = self::$orderedManipulators[$filePath][$stmt->getLine()]
            = new self($filePath, $stmt, $projectChecker);

        return $manipulator;
    }

    /**
     * @param string $filePath
     * @param Closure|Function_|ClassMethod $stmt
     */
    private function __construct($filePath, FunctionLike $stmt, ProjectChecker $projectChecker)
    {
        $this->stmt = $stmt;
        $docblock = $stmt->getDocComment();
        $this->docblockStart = $docblock ? $docblock->getFilePos() : (int)$stmt->getAttribute('startFilePos');
        $this->docblockEnd = $functionStart = (int)$stmt->getAttribute('startFilePos');
        $functionEnd = (int)$stmt->getAttribute('endFilePos');

        $fileContents = $projectChecker->codebase->getFileContents($filePath);

        $lastArgPosition = $stmt->params
            ? (int) $stmt->params[count($stmt->params) - 1]->getAttribute('endFilePos') + 1
            : null;

        if ($stmt instanceof Closure && $stmt->uses) {
            $lastArgPosition = (int) $stmt->uses[count($stmt->uses) - 1]->getAttribute('endFilePos') + 1;
        }

        $endBracketPosition = (int) strpos($fileContents, ')', $lastArgPosition ?: $functionStart);

        $this->returnTypehintAreaStart = $endBracketPosition + 1;

        $functionCode = substr($fileContents, $functionStart, $functionEnd);

        $functionCodeAfterBracket = substr($functionCode, $endBracketPosition + 1 - $functionStart);

        // do a little parsing here
        $chars = str_split($functionCodeAfterBracket);

        $inSingleLineComment = $inMultiLineComment = false;

        for ($i = 0; $i < count($chars); ++$i) {
            $char = $chars[$i];

            switch ($char) {
                case "\n":
                    $inSingleLineComment = false;
                    continue 2;

                case ':':
                    if ($inMultiLineComment || $inSingleLineComment) {
                        continue 2;
                    }

                    $this->returnTypehintColonStart = $i + $endBracketPosition + 1;

                    continue 2;

                case '/':
                    if ($inMultiLineComment || $inSingleLineComment) {
                        continue 2;
                    }

                    if ($chars[$i + 1] === '*') {
                        $inMultiLineComment = true;
                        ++$i;
                    }

                    if ($chars[$i + 1] === '/') {
                        $inSingleLineComment = true;
                        ++$i;
                    }

                    continue 2;

                case '*':
                    if ($inSingleLineComment) {
                        continue 2;
                    }

                    if ($chars[$i + 1] === '/') {
                        $inMultiLineComment = false;
                        ++$i;
                    }

                    continue 2;

                case '{':
                    if ($inMultiLineComment || $inSingleLineComment) {
                        continue 2;
                    }

                    break 2;

                case '?':
                    if ($inMultiLineComment || $inSingleLineComment) {
                        continue 2;
                    }

                    $this->returnTypehintStart = $i + $endBracketPosition + 1;
                    break;
            }

            if ($inMultiLineComment || $inSingleLineComment) {
                continue;
            }

            if ($chars[$i] === '\\' || preg_match('/\w/', $char)) {
                if ($this->returnTypehintStart === null) {
                    $this->returnTypehintStart = $i + $endBracketPosition + 1;
                }

                if ($chars[$i + 1] !== '\\' && !preg_match('/[\w]/', $chars[$i + 1])) {
                    $this->returnTypehintEnd = $i + $endBracketPosition + 2;
                    break;
                }
            }
        }

        $precedingNewlinePos = strrpos($fileContents, "\n", $this->docblockEnd - strlen($fileContents));

        if ($precedingNewlinePos === false) {
            $this->indentation = '';

            return;
        }

        $firstLine = substr($fileContents, $precedingNewlinePos + 1, $this->docblockEnd - $precedingNewlinePos);

        $this->indentation = str_replace(ltrim($firstLine), '', $firstLine);
    }

    /**
     * Sets the new return type
     *
     * @param   ?string     $phpType
     * @param   string      $newType
     * @param   string      $phpdocType
     * @param   bool        $isPhpCompatible
     *
     * @return  void
     */
    public function setReturnType($phpType, $newType, $phpdocType, $isPhpCompatible)
    {
        $newType = str_replace(['<mixed, mixed>', '<empty, empty>'], '', $newType);

        $this->newPhpReturnType = $phpType;
        $this->newPhpdocReturnType = $phpdocType;
        $this->newPsalmReturnType = $newType;
        $this->returnTypeIsPhpCompatible = $isPhpCompatible;
    }

    /**
     * Sets a new param type
     *
     * @param   string      $paramName
     * @param   ?string     $phpType
     * @param   string      $newType
     * @param   string      $phpdocType
     * @param   bool        $isPhpCompatible
     *
     * @return  void
     */
    public function setParamType($paramName, $phpType, $newType, $phpdocType, $isPhpCompatible)
    {
        $newType = str_replace(['<mixed, mixed>', '<empty, empty>'], '', $newType);

        if ($phpType) {
            $this->newPhpParamTypes[$paramName] = $phpType;
        }
        $this->newPhpdocParamTypes[$paramName] = $phpdocType;
        $this->newPsalmParamTypes[$paramName] = $newType;
        $this->paramTypeIsPhpCompatible[$paramName] = $isPhpCompatible;
    }

    /**
     * Gets a new docblock given the existing docblock, if one exists, and the updated return types
     * and/or parameters
     *
     * @return string
     */
    private function getDocblock()
    {
        $docblock = $this->stmt->getDocComment();

        if ($docblock) {
            $parsedDocblock = CommentChecker::parseDocComment((string)$docblock, null, true);
        } else {
            $parsedDocblock = ['description' => '', 'specials' => []];
        }

        foreach ($this->newPhpdocParamTypes as $paramName => $phpdocType) {
            $foundInParams = false;
            $newParamBlock = $phpdocType . ' ' . '$' . $paramName;

            if (isset($parsedDocblock['specials']['param'])) {
                foreach ($parsedDocblock['specials']['param'] as &$paramBlock) {
                    $docParts = CommentChecker::splitDocLine($paramBlock);

                    if ($docParts[1] === '$' . $paramName) {
                        $paramBlock = $newParamBlock;
                        $foundInParams = true;
                        break;
                    }
                }
            }

            if (!$foundInParams) {
                $parsedDocblock['specials']['params'][] = $newParamBlock;
            }
        }

        if ($this->newPhpdocReturnType) {
            $parsedDocblock['specials']['return'] = [$this->newPhpdocReturnType];
        }

        if ($this->newPhpdocReturnType !== $this->newPsalmReturnType && $this->newPsalmReturnType) {
            $parsedDocblock['specials']['psalm-return'] = [$this->newPsalmReturnType];
        }

        return CommentChecker::renderDocComment($parsedDocblock, $this->indentation);
    }

    /**
     * @param  string $filePath
     *
     * @return array<int, FileManipulation>
     */
    public static function getManipulationsForFile($filePath)
    {
        if (!isset(self::$manipulators[$filePath])) {
            return [];
        }

        $fileManipulations = [];

        foreach (self::$orderedManipulators[$filePath] as $manipulator) {
            if ($manipulator->newPhpReturnType) {
                if ($manipulator->returnTypehintStart && $manipulator->returnTypehintEnd) {
                    $fileManipulations[$manipulator->returnTypehintStart] = new FileManipulation(
                        $manipulator->returnTypehintStart,
                        $manipulator->returnTypehintEnd,
                        $manipulator->newPhpReturnType
                    );
                } else {
                    $fileManipulations[$manipulator->returnTypehintAreaStart] = new FileManipulation(
                        $manipulator->returnTypehintAreaStart,
                        $manipulator->returnTypehintAreaStart,
                        ': ' . $manipulator->newPhpReturnType
                    );
                }
            } elseif ($manipulator->returnTypehintColonStart
                && $manipulator->newPhpdocReturnType
                && $manipulator->returnTypehintStart
                && $manipulator->returnTypehintEnd
            ) {
                $fileManipulations[$manipulator->returnTypehintStart] = new FileManipulation(
                    $manipulator->returnTypehintColonStart,
                    $manipulator->returnTypehintEnd,
                    ''
                );
            }

            if (!$manipulator->newPhpReturnType
                || !$manipulator->returnTypeIsPhpCompatible
                || $manipulator->docblockStart !== $manipulator->docblockEnd
            ) {
                $fileManipulations[$manipulator->docblockStart] = new FileManipulation(
                    $manipulator->docblockStart,
                    $manipulator->docblockEnd,
                    $manipulator->getDocblock()
                );
            }
        }

        return $fileManipulations;
    }

    /**
     * @return void
     */
    public static function clearCache()
    {
        self::$manipulators = [];
        self::$orderedManipulators = [];
    }
}
