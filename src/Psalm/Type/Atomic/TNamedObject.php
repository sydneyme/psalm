<?php
namespace Psalm\Type\Atomic;

use Psalm\Type\Atomic;

class TNamedObject extends Atomic
{
    /**
     * @var string
     */
    public $value;

    /**
     * @var TNamedObject[]|null
     */
    public $extraTypes;

    /**
     * @param string $value the name of the object
     */
    public function __construct($value)
    {
        if ($value[0] === '\\') {
            $value = substr($value, 1);
        }

        $this->value = $value;
    }

    public function __toString()
    {
        return $this->getKey();
    }

    /**
     * @return string
     */
    public function getKey()
    {
        if ($this->extraTypes) {
            return $this->value . '&' . implode('&', $this->extraTypes);
        }

        return $this->value;
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
        $classParts = explode('\\', $this->value);
        $className = array_pop($classParts);

        $intersectionTypes = $this->extraTypes
            ? '&' . implode(
                '&',
                array_map(
                    /**
                     * @return string
                     */
                    function (TNamedObject $extraType) use (
                        $namespace,
                        $aliasedClasses,
                        $thisClass,
                        $usePhpdocFormat
                    ) {
                        return $extraType->toNamespacedString(
                            $namespace,
                            $aliasedClasses,
                            $thisClass,
                            $usePhpdocFormat
                        );
                    },
                    $this->extraTypes
                )
            )
            : '';

        if ($this->value === $thisClass) {
            return 'self' . $intersectionTypes;
        }

        if ($namespace && preg_match('/^' . preg_quote($namespace) . '\\\\' . $className . '$/i', $this->value)) {
            return $className . $intersectionTypes;
        }

        if (!$namespace && stripos($this->value, '\\') === false) {
            return $this->value . $intersectionTypes;
        }

        if (isset($aliasedClasses[strtolower($this->value)])) {
            return $aliasedClasses[strtolower($this->value)] . $intersectionTypes;
        }

        return '\\' . $this->value . $intersectionTypes;
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
        return $this->toNamespacedString($namespace, $aliasedClasses, $thisClass, false);
    }

    public function canBeFullyExpressedInPhp()
    {
        return true;
    }

    /**
     * @param TNamedObject $type
     *
     * @return void
     */
    public function addIntersectionType(TNamedObject $type)
    {
        $this->extraTypes[] = $type;
    }

    /**
     * @return TNamedObject[]|null
     */
    public function getIntersectionTypes()
    {
        return $this->extraTypes;
    }
}
