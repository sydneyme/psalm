<?php
namespace Psalm\Type\Atomic;

use Psalm\Type\Atomic;

class TScalarClassConstant extends Scalar
{
    /** @var string */
    public $fqClasslikeName;

    /** @var string */
    public $constName;

    /**
     * @param string $fqClasslikeName
     * @param string $constName
     */
    public function __construct($fqClasslikeName, $constName)
    {
        $this->fqClasslikeName = $fqClasslikeName;
        $this->constName = $constName;
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return 'scalar-class-constant(' . $this->fqClasslikeName . '::' . $this->constName . ')';
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return 'scalar-class-constant';
    }

    /**
     * @return string
     */
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
     * @return string|null
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
