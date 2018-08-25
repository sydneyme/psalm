<?php
namespace Psalm\Storage;

use Psalm\CodeLocation;

class MethodStorage extends FunctionLikeStorage
{
    /**
     * @var bool
     */
    public $isStatic;

    /**
     * @var int
     */
    public $visibility;

    /**
     * @var bool
     */
    public $final;

    /**
     * @var bool
     */
    public $abstract;

    /**
     * @var array<int, CodeLocation>
     */
    public $unusedParams = [];

    /**
     * @var array<int, bool>
     */
    public $usedParams = [];

    /**
     * @var bool
     */
    public $overriddenDownstream = false;

    /**
     * @var bool
     */
    public $overriddenSomewhere = false;
}
