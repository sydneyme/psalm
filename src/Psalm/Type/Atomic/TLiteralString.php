<?php
namespace Psalm\Type\Atomic;

use Psalm\Type\Atomic;

class TLiteralString extends TString
{
    /** @var string */
    public $value;

    /**
     * @param string $value
     */
    public function __construct($value)
    {
        $this->value = $value;
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return 'string(' . $this->value . ')';
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return 'string';
    }

    /**
     * @return string
     */
    public function getId()
    {
        return 'string(' . $this->value . ')';
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
        return $phpMajorVersion >= 7 ? 'string' : null;
    }

    /**
     * @param  string|null   $namespace
     * @param  array<string> $aliasedClasses
     * @param  string|null   $thisClass
     * @param  bool          $usePhpdocFormat
     *
     * @return string
     */
    public function toNamespacedString($namespace, array $aliasedClasses, $thisClass, $usePhpdocFormat)
    {
        return 'string';
    }
}
