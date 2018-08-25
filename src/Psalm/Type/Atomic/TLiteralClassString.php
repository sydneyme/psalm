<?php
namespace Psalm\Type\Atomic;

class TLiteralClassString extends TLiteralString
{
    /**
     * @param string $value string
     */
    public function __construct($value)
    {
        $this->value = $value;
    }

    public function __toString()
    {
        return 'class-string';
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return 'class-string(' . $this->value . ')';
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

    /**
     * @return string
     */
    public function getId()
    {
        return 'class-string(' . $this->value . ')';
    }
}
