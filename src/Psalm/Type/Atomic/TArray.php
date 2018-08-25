<?php
namespace Psalm\Type\Atomic;

use Psalm\Type\Atomic;
use Psalm\Type\Union;

/**
 * Represents an array with generic type parameters.
 */
class TArray extends \Psalm\Type\Atomic
{
    use GenericTrait;

    /**
     * @var string
     */
    public $value = 'array';

    /**
     * @var int|null
     */
    public $count;

    /**
     * Constructs a new instance of a generic type
     *
     * @param array<int, \Psalm\Type\Union> $typeParams
     */
    public function __construct(array $typeParams)
    {
        $this->typeParams = $typeParams;
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return 'array';
    }

    /**
     * @param  string|null   $namespace
     * @param  array<string> $aliasedClasses
     * @param  string|null   $thisClass
     * @param  int           $phpMajorVersion
     * @param  int           $phpMinorVersion
     *
     * @return string
     */
    public function toPhpString(
        $namespace,
        array $aliasedClasses,
        $thisClass,
        $phpMajorVersion,
        $phpMinorVersion
    ) {
        return $this->getKey();
    }

    public function canBeFullyExpressedInPhp()
    {
        return $this->typeParams[0]->isMixed() && $this->typeParams[1]->isMixed();
    }

    /**
     * @return bool
     */
    public function equals(Atomic $otherType)
    {
        if (!$otherType instanceof self) {
            return false;
        }

        if ($this->count !== $otherType->count) {
            return false;
        }

        if (count($this->typeParams) !== count($otherType->typeParams)) {
            return false;
        }

        foreach ($this->typeParams as $i => $typeParam) {
            if (!$typeParam->equals($otherType->typeParams[$i])) {
                return false;
            }
        }

        return true;
    }
}
