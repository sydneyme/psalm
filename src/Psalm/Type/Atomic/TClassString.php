<?php
namespace Psalm\Type\Atomic;

class TClassString extends TString
{
    public function __toString()
    {
        return 'class-string';
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return 'class-string';
    }

    public function getId()
    {
        return $this->getKey();
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
        return 'string';
    }

    /**
     * @return bool
     */
    public function canBeFullyExpressedInPhp()
    {
        return false;
    }
}
