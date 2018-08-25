<?php
namespace Psalm\FileManipulation;

class FileManipulation
{
    /** @var int */
    public $start;

    /** @var int */
    public $end;

    /** @var string */
    public $insertionText;

    /**
     * @param int $start
     * @param int $end
     * @param string $insertionText
     */
    public function __construct($start, $end, $insertionText)
    {
        $this->start = $start;
        $this->end = $end;
        $this->insertionText = $insertionText;
    }
}
