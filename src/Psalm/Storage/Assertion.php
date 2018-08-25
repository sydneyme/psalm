<?php
namespace Psalm\Storage;

class Assertion
{
    /**
     * @var array<int, array<int, string>> the rule being asserted
     */
    public $rule;

    /**
     * @var int|string the id of the property/variable, or
     *  the parameter offset of the affected arg
     */
    public $varId;

    /**
     * @param string|int $varId
     * @param array<int, array<int, string>> $rule
     */
    public function __construct($varId, $rule)
    {
        $this->rule = $rule;
        $this->varId = $varId;
    }
}
