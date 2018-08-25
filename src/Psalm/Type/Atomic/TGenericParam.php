<?php
namespace Psalm\Type\Atomic;

class TGenericParam extends \Psalm\Type\Atomic
{
    /**
     * @var string
     */
    public $paramName;

    /**
     * @param string $paramName
     */
    public function __construct($paramName)
    {
        $this->paramName = $paramName;
    }

    public function __toString()
    {
        return $this->paramName;
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return $this->paramName;
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
     * @return null
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

    /**
     * @return bool
     */
    public function canBeFullyExpressedInPhp()
    {
        return false;
    }
}
