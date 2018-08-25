<?php
namespace Psalm;

use Psalm\Checker\CommentChecker;

class CodeLocation
{
    /** @var string */
    public $filePath;

    /** @var string */
    public $fileName;

    /** @var int */
    private $lineNumber;

    /** @var int */
    private $endLineNumber = -1;

    /** @var int */
    private $fileStart;

    /** @var int */
    private $fileEnd;

    /** @var bool */
    private $singleLine;

    /** @var int */
    private $previewStart;

    /** @var int */
    private $previewEnd = -1;

    /** @var int */
    private $selectionStart = -1;

    /** @var int */
    private $selectionEnd = -1;

    /** @var int */
    private $columnFrom = -1;

    /** @var int */
    private $columnTo = -1;

    /** @var string */
    private $snippet = '';

    /** @var null|string */
    private $text;

    /** @var int|null */
    private $docblockStartLineNumber;

    /** @var int|null */
    private $docblockLineNumber;

    /** @var null|int */
    private $regexType;

    /** @var bool */
    private $haveRecalculated = false;

    /** @var null|CodeLocation */
    public $previousLocation;

    const VAR_TYPE = 0;
    const FUNCTION_RETURN_TYPE = 1;
    const FUNCTION_PARAM_TYPE = 2;
    const FUNCTION_PHPDOC_RETURN_TYPE = 3;
    const FUNCTION_PHPDOC_PARAM_TYPE = 4;
    const FUNCTION_PARAM_VAR = 5;
    const CATCH_VAR = 6;

    /**
     * @param bool                 $singleLine
     * @param null|CodeLocation    $previousLocation
     * @param null|int             $regexType
     * @param null|string          $selectedText
     */
    public function __construct(
        FileSource $fileSource,
        \PhpParser\Node $stmt,
        CodeLocation $previousLocation = null,
        $singleLine = false,
        $regexType = null,
        $selectedText = null
    ) {
        $this->fileStart = (int)$stmt->getAttribute('startFilePos');
        $this->fileEnd = (int)$stmt->getAttribute('endFilePos');
        $this->filePath = $fileSource->getFilePath();
        $this->fileName = $fileSource->getFileName();
        $this->singleLine = $singleLine;
        $this->regexType = $regexType;
        $this->previousLocation = $previousLocation;
        $this->text = $selectedText;

        $docComment = $stmt->getDocComment();
        $this->previewStart = $docComment ? $docComment->getFilePos() : $this->fileStart;
        $this->docblockStartLineNumber = $docComment ? $docComment->getLine() : null;
        $this->lineNumber = $stmt->getLine();
    }

    /**
     * @param int $line
     *
     * @return void
     */
    public function setCommentLine($line)
    {
        $this->docblockLineNumber = $line;
    }

    /**
     * @psalm-suppress MixedArrayAccess
     *
     * @return void
     */
    private function calculateRealLocation()
    {
        if ($this->haveRecalculated) {
            return;
        }

        $this->haveRecalculated = true;

        $this->selectionStart = $this->fileStart;
        $this->selectionEnd = $this->fileEnd + 1;

        $projectChecker = Checker\ProjectChecker::getInstance();

        $fileContents = $projectChecker->codebase->getFileContents($this->filePath);

        $previewEnd = strpos(
            $fileContents,
            "\n",
            $this->singleLine ? $this->selectionStart : $this->selectionEnd
        );

        // if the string didn't contain a newline
        if ($previewEnd === false) {
            $previewEnd = $this->selectionEnd;
        }

        $this->previewEnd = $previewEnd;

        if ($this->docblockLineNumber &&
            $this->docblockStartLineNumber &&
            $this->previewStart < $this->selectionStart
        ) {
            $previewLines = explode(
                "\n",
                substr(
                    $fileContents,
                    $this->previewStart,
                    $this->selectionStart - $this->previewStart - 1
                )
            );

            $previewOffset = 0;

            $commentLineOffset = $this->docblockLineNumber - $this->docblockStartLineNumber;

            for ($i = 0; $i < $commentLineOffset; ++$i) {
                $previewOffset += strlen($previewLines[$i]) + 1;
            }

            $keyLine = $previewLines[$i];

            $indentation = (int)strpos($keyLine, '@');

            $keyLine = trim(preg_replace('@\**/\s*@', '', substr($keyLine, $indentation)));

            $this->selectionStart = $previewOffset + $indentation + $this->previewStart;
            $this->selectionEnd = $this->selectionStart + strlen($keyLine);
        }

        if ($this->regexType !== null) {
            switch ($this->regexType) {
                case self::VAR_TYPE:
                    $regex = '/@(psalm-)?var[ \t]+' . CommentChecker::TYPE_REGEX . '/';
                    $matchOffset = 2;
                    break;

                case self::FUNCTION_RETURN_TYPE:
                    $regex = '/\\:\s+(\\??\s*[A-Za-z0-9_\\\\\[\]]+)/';
                    $matchOffset = 1;
                    break;

                case self::FUNCTION_PARAM_TYPE:
                    $regex = '/^(\\??\s*[A-Za-z0-9_\\\\\[\]]+)\s/';
                    $matchOffset = 1;
                    break;

                case self::FUNCTION_PHPDOC_RETURN_TYPE:
                    $regex = '/@(psalm-)?return[ \t]+' . CommentChecker::TYPE_REGEX . '/';
                    $matchOffset = 2;
                    break;

                case self::FUNCTION_PHPDOC_PARAM_TYPE:
                    $regex = '/@(psalm-)?param[ \t]+' . CommentChecker::TYPE_REGEX . '/';
                    $matchOffset = 2;
                    break;

                case self::FUNCTION_PARAM_VAR:
                    $regex = '/(\$[^ ]*)/';
                    $matchOffset = 1;
                    break;

                case self::CATCH_VAR:
                    $regex = '/(\$[^ ^\)]*)/';
                    $matchOffset = 1;
                    break;

                default:
                    throw new \UnexpectedValueException('Unrecognised regex type ' . $this->regexType);
            }

            $previewSnippet = substr(
                $fileContents,
                $this->selectionStart,
                $this->selectionEnd - $this->selectionStart
            );

            if ($this->text) {
                $regex = '/(' . str_replace(',', ',[ ]*', preg_quote($this->text)) . ')/';
                $matchOffset = 1;
            }

            if (preg_match($regex, $previewSnippet, $matches, PREG_OFFSET_CAPTURE)) {
                $this->selectionStart = $this->selectionStart + (int)$matches[$matchOffset][1];
                $this->selectionEnd = $this->selectionStart + strlen((string)$matches[$matchOffset][0]);
            }
        }

        // reset preview start to beginning of line
        $this->previewStart = (int)strrpos(
            $fileContents,
            "\n",
            min($this->previewStart, $this->selectionStart) - strlen($fileContents)
        ) + 1;

        $this->selectionStart = max($this->previewStart, $this->selectionStart);
        $this->selectionEnd = min($this->previewEnd, $this->selectionEnd);

        if ($this->previewEnd - $this->selectionEnd > 200) {
            $this->previewEnd = (int)strrpos(
                $fileContents,
                "\n",
                $this->selectionEnd + 200 - strlen($fileContents)
            );

            // if the line is over 200 characters long
            if ($this->previewEnd < $this->selectionEnd) {
                $this->previewEnd = $this->selectionEnd + 50;
            }
        }

        $this->snippet = substr($fileContents, $this->previewStart, $this->previewEnd - $this->previewStart);
        $this->text = substr($fileContents, $this->selectionStart, $this->selectionEnd - $this->selectionStart);

        // reset preview start to beginning of line
        $this->columnFrom = $this->selectionStart -
            (int)strrpos($fileContents, "\n", $this->selectionStart - strlen($fileContents));

        $newlines = substr_count($this->text, "\n");

        if ($newlines) {
            $this->columnTo = $this->selectionEnd -
                (int)strrpos($fileContents, "\n", $this->selectionEnd - strlen($fileContents));
        } else {
            $this->columnTo = $this->columnFrom + strlen($this->text);
        }

        $this->endLineNumber = $this->getLineNumber() + $newlines;
    }

    /**
     * @return int
     */
    public function getLineNumber()
    {
        return $this->docblockLineNumber ?: $this->lineNumber;
    }

    /**
     * @return int
     */
    public function getEndLineNumber()
    {
        $this->calculateRealLocation();

        return $this->endLineNumber;
    }

    /**
     * @return string
     */
    public function getSnippet()
    {
        $this->calculateRealLocation();

        return $this->snippet;
    }

    /**
     * @return string
     */
    public function getSelectedText()
    {
        $this->calculateRealLocation();

        return (string)$this->text;
    }

    /**
     * @return int
     */
    public function getColumn()
    {
        $this->calculateRealLocation();

        return $this->columnFrom;
    }

    /**
     * @return int
     */
    public function getEndColumn()
    {
        $this->calculateRealLocation();

        return $this->columnTo;
    }

    /**
     * @return array<int, int>
     */
    public function getSelectionBounds()
    {
        $this->calculateRealLocation();

        return [$this->selectionStart, $this->selectionEnd];
    }

    /**
     * @return array<int, int>
     */
    public function getSnippetBounds()
    {
        $this->calculateRealLocation();

        return [$this->previewStart, $this->previewEnd];
    }

    /**
     * @return string
     */
    public function getHash()
    {
        return (string) $this->fileStart;
    }
}
