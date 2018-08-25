<?php
namespace Psalm\Type\Atomic;

class TNumeric extends Scalar
{
    public function __toString()
    {
        return 'numeric';
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return 'numeric';
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
}
