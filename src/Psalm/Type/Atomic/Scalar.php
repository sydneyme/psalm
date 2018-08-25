<?php
namespace Psalm\Type\Atomic;

abstract class Scalar extends \Psalm\Type\Atomic
{
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
        return $phpMajorVersion >= 7 ? $this->getKey() : null;
    }

    /**
     * @return bool
     */
    public function canBeFullyExpressedInPhp()
    {
        return true;
    }
}
