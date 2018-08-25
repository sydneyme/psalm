<?php
namespace Psalm\Issue;

abstract class PropertyIssue extends CodeIssue
{
    /**
     * @var string
     */
    public $propertyId;

    /**
     * @param string        $message
     * @param \Psalm\CodeLocation  $codeLocation
     * @param string        $propertyId
     */
    public function __construct(
        $message,
        \Psalm\CodeLocation $codeLocation,
        $propertyId
    ) {
        parent::__construct($message, $codeLocation);
        $this->propertyId = $propertyId;
    }
}
