<?php
namespace Psalm\Issue;

abstract class ClassIssue extends CodeIssue
{
    /**
     * @var string
     */
    public $fqClasslikeName;

    /**
     * @param string        $message
     * @param \Psalm\CodeLocation  $codeLocation
     * @param string        $fqClasslikeName
     */
    public function __construct(
        $message,
        \Psalm\CodeLocation $codeLocation,
        $fqClasslikeName
    ) {
        parent::__construct($message, $codeLocation);
        $this->fqClasslikeName = $fqClasslikeName;
    }
}
