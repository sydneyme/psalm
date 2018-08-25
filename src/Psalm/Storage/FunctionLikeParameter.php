<?php
namespace Psalm\Storage;

use Psalm\CodeLocation;
use Psalm\Type;

class FunctionLikeParameter
{
    /**
     * @var string
     */
    public $name;

    /**
     * @var bool
     */
    public $byRef;

    /**
     * @var Type\Union|null
     */
    public $type;

    /**
     * @var Type\Union|null
     */
    public $signatureType;

    /**
     * @var bool
     */
    public $isOptional;

    /**
     * @var bool
     */
    public $isNullable;

    /**
     * @var Type\Union|null
     */
    public $defaultType;

    /**
     * @var CodeLocation|null
     */
    public $location;

    /**
     * @var CodeLocation|null
     */
    public $typeLocation;

    /**
     * @var CodeLocation|null
     */
    public $signatureTypeLocation;

    /**
     * @var bool
     */
    public $isVariadic;

    /**
     * @param string        $name
     * @param bool       $byRef
     * @param Type\Union|null    $type
     * @param CodeLocation|null  $location
     * @param bool       $isOptional
     * @param bool       $isNullable
     * @param bool       $isVariadic
     * @param Type\Union|null    $defaultType
     */
    public function __construct(
        $name,
        $byRef,
        Type\Union $type = null,
        CodeLocation $location = null,
        CodeLocation $typeLocation = null,
        $isOptional = true,
        $isNullable = false,
        $isVariadic = false,
        $defaultType = null
    ) {
        $this->name = $name;
        $this->byRef = $byRef;
        $this->type = $type;
        $this->signatureType = $type;
        $this->isOptional = $isOptional;
        $this->isNullable = $isNullable;
        $this->isVariadic = $isVariadic;
        $this->location = $location;
        $this->typeLocation = $typeLocation;
        $this->signatureTypeLocation = $typeLocation;
        $this->defaultType = $defaultType;
    }

    public function __toString()
    {
        return ($this->type ?: 'mixed')
            . ($this->isVariadic ? '...' : '')
            . ($this->isOptional ? '=' : '');
    }

    public function __clone()
    {
        if ($this->type) {
            $this->type = clone $this->type;
        }
    }
}
