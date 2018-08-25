<?php
namespace Psalm\Issue;

abstract class MethodIssue extends CodeIssue
{
    /**
     * @var string
     */
    public $methodId;

    /**
     * @param string        $message
     * @param \Psalm\CodeLocation  $codeLocation
     * @param string        $methodId
     */
    public function __construct(
        $message,
        \Psalm\CodeLocation $codeLocation,
        $methodId
    ) {
        parent::__construct($message, $codeLocation);
        $this->methodId = strtolower($methodId);
    }
}
