<?php
namespace Psalm\Type\Atomic;

class TCallable extends \Psalm\Type\Atomic
{
    use CallableTrait;

    /**
     * @var string
     */
    public $value;

    /**
     * @return string
     */
    public function getKey()
    {
        return 'callable';
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
        return 'callable';
    }

    public function canBeFullyExpressedInPhp()
    {
        return false;
    }
}
