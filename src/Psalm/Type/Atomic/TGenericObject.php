<?php
namespace Psalm\Type\Atomic;

use Psalm\Type\Atomic;
use Psalm\Type\Union;

class TGenericObject extends TNamedObject
{
    use GenericTrait;

    /**
     * @param string                            $value the name of the object
     * @param array<int, \Psalm\Type\Union>     $typeParams
     */
    public function __construct($value, array $typeParams)
    {
        if ($value[0] === '\\') {
            $value = substr($value, 1);
        }
        $this->value = $value;
        $this->typeParams = $typeParams;
    }

    /**
     * @return bool
     */
    public function canBeFullyExpressedInPhp()
    {
        return false;
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
        return parent::toNamespacedString($namespace, $aliasedClasses, $thisClass, false);
    }

    /**
     * @return bool
     */
    public function equals(Atomic $otherType)
    {
        if (!$otherType instanceof self) {
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
