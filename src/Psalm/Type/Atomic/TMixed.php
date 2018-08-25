<?php
namespace Psalm\Type\Atomic;

class TMixed extends \Psalm\Type\Atomic
{
    /** @var bool */
    public $fromIsset = false;

    /**
     * @param bool $fromIsset
     */
    public function __construct($fromIsset = false)
    {
        $this->fromIsset = $fromIsset;
    }

    public function __toString()
    {
        return 'mixed';
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return 'mixed';
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
