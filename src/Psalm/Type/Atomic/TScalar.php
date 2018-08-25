<?php
namespace Psalm\Type\Atomic;

class TScalar extends Scalar
{
    public function __toString()
    {
        return 'scalar';
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return 'scalar';
    }

    /**
     * @param  string|null   $namespace
     * @param  array<string> $aliasedClasses
     * @param  string|null   $thisClass
     * @param  int           $phpMajorVersion
     * @param  int           $phpMinorVersion
     *
     * @return null|string
     */
    public function toPhpString(
        $namespace,
        array $aliasedClasses,
        $thisClass,
        $phpMajorVersion,
        $phpMinorVersion
    ) {
        return null;
    }

    public function canBeFullyExpressedInPhp()
    {
        return false;
    }
}
