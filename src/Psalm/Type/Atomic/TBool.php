<?php
namespace Psalm\Type\Atomic;

class TBool extends Scalar
{
    public function __toString()
    {
        return 'bool';
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return 'bool';
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
        return $phpMajorVersion >= 7 ? 'bool' : null;
    }
}
