<?php
namespace Psalm\Type\Atomic;

class TEmpty extends Scalar
{
    public function __toString()
    {
        return 'empty';
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return 'empty';
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
