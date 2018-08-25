<?php
namespace Psalm\Storage;

use Psalm\CodeLocation;
use Psalm\Type;

class PropertyStorage
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
     * @var CodeLocation|null
     */
    public $location;

    /**
     * @var CodeLocation|null
     */
    public $typeLocation;

    /**
     * @var Type\Union|false
     */
    public $type;

    /**
     * @var Type\Union|null
     */
    public $suggestedType;

    /**
     * @var bool
     */
    public $hasDefault = false;

    /**
     * @var bool
     */
    public $deprecated = false;

    /**
     * @var array<string, array<int, CodeLocation>>|null
     */
    public $referencingLocations;
}
