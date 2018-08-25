<?php
namespace Psalm\Type\Atomic;

class TResource extends \Psalm\Type\Atomic
{
    public function __toString()
    {
        return 'resource';
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return 'resource';
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
