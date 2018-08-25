<?php
namespace Psalm\Type\Atomic;

class TObject extends \Psalm\Type\Atomic
{
    public function __toString()
    {
        return 'object';
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return 'object';
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
        return $phpMajorVersion >= 7 && $phpMinorVersion >= 2 ? $this->getKey() : null;
    }

    public function canBeFullyExpressedInPhp()
    {
        return true;
    }
}
