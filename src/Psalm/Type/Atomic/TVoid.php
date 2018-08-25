<?php
namespace Psalm\Type\Atomic;

class TVoid extends \Psalm\Type\Atomic
{
    public function __toString()
    {
        return 'void';
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return 'void';
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
        return $phpMajorVersion >= 7 && $phpMinorVersion >= 1 ? $this->getKey() : null;
    }

    public function canBeFullyExpressedInPhp()
    {
        return true;
    }
}
