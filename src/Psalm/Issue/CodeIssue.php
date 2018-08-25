<?php
namespace Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Config;

abstract class CodeIssue
{
    const CODE_EXCEPTION = 1;

    /**
     * @var CodeLocation
     */
    protected $codeLocation;

    /**
     * @var string
     */
    protected $message;

    /**
     * @param string        $message
     * @param CodeLocation  $codeLocation
     */
    public function __construct(
        $message,
        CodeLocation $codeLocation
    ) {
        $this->codeLocation = $codeLocation;
        $this->message = $message;
    }

    /**
     * @return CodeLocation
     */
    public function getLocation()
    {
        return $this->codeLocation;
    }

    /**
     * @return string
     */
    public function getShortLocation()
    {
        $previousText = '';

        if ($this->codeLocation->previousLocation) {
            $previousLocation = $this->codeLocation->previousLocation;
            $previousText = ' from ' . $previousLocation->fileName . ':' . $previousLocation->getLineNumber();
        }

        return $this->codeLocation->fileName . ':' . $this->codeLocation->getLineNumber() . $previousText;
    }

    /**
     * @return string
     */
    public function getFilePath()
    {
        return $this->codeLocation->filePath;
    }

    /**
     * @return string
     *
     * @psalm-suppress PossiblyUnusedMethod for convenience
     */
    public function getFileName()
    {
        return $this->codeLocation->fileName;
    }

    /**
     * @return string
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @param  string          $severity
     *
     * @return array{severity: string, line_from: int, line_to: int, type: string, message: string, file_name: string,
     *  file_path: string, snippet: string, selected_text: string, from: int, to: int, snippet_from: int,
     *  snippet_to: int, column_from: int, column_to: int}
     */
    public function toArray($severity = Config::REPORT_ERROR)
    {
        $location = $this->getLocation();
        $selectionBounds = $location->getSelectionBounds();
        $snippetBounds = $location->getSnippetBounds();

        $fqcnParts = explode('\\', get_called_class());
        $issueType = array_pop($fqcnParts);

        return [
            'severity' => $severity,
            'line_from' => $location->getLineNumber(),
            'line_to' => $location->getEndLineNumber(),
            'type' => $issueType,
            'message' => $this->getMessage(),
            'file_name' => $location->fileName,
            'file_path' => $location->filePath,
            'snippet' => $location->getSnippet(),
            'selected_text' => $location->getSelectedText(),
            'from' => $selectionBounds[0],
            'to' => $selectionBounds[1],
            'snippet_from' => $snippetBounds[0],
            'snippet_to' => $snippetBounds[1],
            'column_from' => $location->getColumn(),
            'column_to' => $location->getEndColumn(),
        ];
    }
}
