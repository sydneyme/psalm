<?php
namespace Psalm\Type\Atomic;

use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\TypeCombination;
use Psalm\Type\Union;

/**
 * Represents an array where we know its key values
 */
class ObjectLike extends \Psalm\Type\Atomic
{
    /**
     * @var array<string|int, Union>
     */
    public $properties;

    /**
     * @var array<string, bool>|null
     */
    public $classStrings = null;

    /**
     * @var bool - whether or not the objectlike has been created from an explicit array
     */
    public $sealed = false;

    /**
     * Constructs a new instance of a generic type
     *
     * @param array<string|int, Union> $properties
     * @param array<string, bool> $classStrings
     */
    public function __construct(array $properties, array $classStrings = null)
    {
        $this->properties = $properties;
        $this->classStrings = $classStrings;
    }

    public function __toString()
    {
        return 'array{' .
                implode(
                    ', ',
                    array_map(
                        /**
                         * @param  string|int $name
                         * @param  Union $type
                         *
                         * @return string
                         */
                        function ($name, Union $type) {
                            return $name . ($type->possiblyUndefined ? '?' : '') . ':' . $type;
                        },
                        array_keys($this->properties),
                        $this->properties
                    )
                ) .
                '}';
    }

    public function getId()
    {
        return 'array{' .
                implode(
                    ', ',
                    array_map(
                        /**
                         * @param  string|int $name
                         * @param  Union $type
                         *
                         * @return string
                         */
                        function ($name, Union $type) {
                            return $name . ($type->possiblyUndefined ? '?' : '') . ':' . $type->getId();
                        },
                        array_keys($this->properties),
                        $this->properties
                    )
                ) .
                '}';
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
        if ($usePhpdocFormat) {
            return $this->getGenericArrayType()->toNamespacedString(
                $namespace,
                $aliasedClasses,
                $thisClass,
                $usePhpdocFormat
            );
        }

        return 'array{' .
                implode(
                    ', ',
                    array_map(
                        /**
                         * @param  string|int $name
                         * @param  Union  $type
                         *
                         * @return string
                         */
                        function (
                            $name,
                            Union $type
                        ) use (
                            $namespace,
                            $aliasedClasses,
                            $thisClass,
                            $usePhpdocFormat
                        ) {
                            return $name . ($type->possiblyUndefined ? '?' : '') . ':' . $type->toNamespacedString(
                                $namespace,
                                $aliasedClasses,
                                $thisClass,
                                $usePhpdocFormat
                            );
                        },
                        array_keys($this->properties),
                        $this->properties
                    )
                ) .
                '}';
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
    public function toPhpString($namespace, array $aliasedClasses, $thisClass, $phpMajorVersion, $phpMinorVersion)
    {
        return $this->getKey();
    }

    public function canBeFullyExpressedInPhp()
    {
        return false;
    }

    /**
     * @return Union
     */
    public function getGenericKeyType()
    {
        $keyTypes = [];

        foreach ($this->properties as $key => $_) {
            if (is_int($key)) {
                $keyTypes[] = new Type\Atomic\TLiteralInt($key);
            } elseif (isset($this->classStrings[$key])) {
                $keyTypes[] = new Type\Atomic\TLiteralClassString($key);
            } else {
                $keyTypes[] = new Type\Atomic\TLiteralString($key);
            }
        }

        return TypeCombination::combineTypes($keyTypes);
    }

    /**
     * @return Union
     */
    public function getGenericValueType()
    {
        $valueType = null;

        foreach ($this->properties as $property) {
            if ($valueType === null) {
                $valueType = clone $property;
            } else {
                $valueType = Type::combineUnionTypes($property, $valueType);
            }
        }

        if (!$valueType) {
            throw new \UnexpectedValueException('$valueType should not be null here');
        }

        $valueType->possiblyUndefined = false;

        return $valueType;
    }

    /**
     * @return Type\Atomic\TArray
     */
    public function getGenericArrayType()
    {
        $keyTypes = [];
        $valueType = null;

        foreach ($this->properties as $key => $property) {
            if (is_int($key)) {
                $keyTypes[] = new Type\Atomic\TLiteralInt($key);
            } elseif (isset($this->classStrings[$key])) {
                $keyTypes[] = new Type\Atomic\TLiteralClassString($key);
            } else {
                $keyTypes[] = new Type\Atomic\TLiteralString($key);
            }

            if ($valueType === null) {
                $valueType = clone $property;
            } else {
                $valueType = Type::combineUnionTypes($property, $valueType);
            }
        }

        if (!$valueType) {
            throw new \UnexpectedValueException('$valueType should not be null here');
        }

        $valueType->possiblyUndefined = false;

        $arrayType = new TArray([TypeCombination::combineTypes($keyTypes), $valueType]);

        if ($this->sealed) {
            $arrayType->count = count($this->properties);
        }

        return $arrayType;
    }

    public function __clone()
    {
        foreach ($this->properties as &$property) {
            $property = clone $property;
        }
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return 'array';
    }

    public function setFromDocblock()
    {
        $this->fromDocblock = true;

        foreach ($this->properties as $propertyType) {
            $propertyType->setFromDocblock();
        }
    }

    /**
     * @return bool
     */
    public function equals(Atomic $otherType)
    {
        if (!$otherType instanceof self) {
            return false;
        }

        if (count($this->properties) !== count($otherType->properties)) {
            return false;
        }

        if ($this->sealed !== $otherType->sealed) {
            return false;
        }

        foreach ($this->properties as $propertyName => $propertyType) {
            if (!isset($otherType->properties[$propertyName])) {
                return false;
            }

            if (!$propertyType->equals($otherType->properties[$propertyName])) {
                return false;
            }
        }

        return true;
    }
}
