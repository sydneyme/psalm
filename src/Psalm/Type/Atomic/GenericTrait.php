<?php
namespace Psalm\Type\Atomic;

use Psalm\Codebase;
use Psalm\Type\Atomic;
use Psalm\Type\Union;

trait GenericTrait
{
    /**
     * @var array<int, Union>
     */
    public $typeParams;

    public function __toString()
    {
        $s = '';
        foreach ($this->typeParams as $typeParam) {
            $s .= $typeParam . ', ';
        }

        $extraTypes = '';

        if ($this instanceof TNamedObject && $this->extraTypes) {
            $extraTypes = '&' . implode('&', $this->extraTypes);
        }

        return $this->value . '<' . substr($s, 0, -2) . '>' . $extraTypes;
    }

    public function getId()
    {
        $s = '';
        foreach ($this->typeParams as $typeParam) {
            $s .= $typeParam->getId() . ', ';
        }

        $extraTypes = '';

        if ($this instanceof TNamedObject && $this->extraTypes) {
            $extraTypes = '&' . implode('&', $this->extraTypes);
        }

        return $this->value . '<' . substr($s, 0, -2) . '>' . $extraTypes;
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
        $baseValue = $this instanceof TNamedObject
            ? parent::toNamespacedString($namespace, $aliasedClasses, $thisClass, $usePhpdocFormat)
            : $this->value;

        if ($usePhpdocFormat) {
            if ($this instanceof TNamedObject) {
                return $baseValue;
            }

            $valueType = $this->typeParams[1];

            if ($valueType->isMixed()) {
                return $this->value;
            }

            $valueTypeString = $valueType->toNamespacedString($namespace, $aliasedClasses, $thisClass, true);

            if (!$valueType->isSingle()) {
                return '(' . $valueTypeString . ')[]';
            }

            return $valueTypeString . '[]';
        }

        $extraTypes = '';

        if ($this instanceof TNamedObject && $this->extraTypes) {
            $extraTypes = '&' . implode(
                '&',
                array_map(
                    /**
                     * @return string
                     */
                    function (Atomic $extraType) use ($namespace, $aliasedClasses, $thisClass) {
                        return $extraType->toNamespacedString($namespace, $aliasedClasses, $thisClass, false);
                    },
                    $this->extraTypes
                )
            );
        }

        return $baseValue .
                '<' .
                implode(
                    ', ',
                    array_map(
                        /**
                         * @return string
                         */
                        function (Union $typeParam) use ($namespace, $aliasedClasses, $thisClass) {
                            return $typeParam->toNamespacedString($namespace, $aliasedClasses, $thisClass, false);
                        },
                        $this->typeParams
                    )
                ) .
                '>' . $extraTypes;
    }

    public function __clone()
    {
        foreach ($this->typeParams as &$typeParam) {
            $typeParam = clone $typeParam;
        }
    }

    /**
     * @return void
     */
    public function setFromDocblock()
    {
        $this->fromDocblock = true;

        foreach ($this->typeParams as $typeParam) {
            $typeParam->setFromDocblock();
        }
    }

    /**
     * @param  array<string, Union>     $templateTypes
     * @param  array<string, Union>     $genericParams
     * @param  Atomic|null              $inputType
     *
     * @return void
     */
    public function replaceTemplateTypesWithStandins(
        array $templateTypes,
        array &$genericParams,
        Codebase $codebase = null,
        Atomic $inputType = null
    ) {
        foreach ($this->typeParams as $offset => $typeParam) {
            $inputTypeParam = null;

            if (($inputType instanceof Atomic\TGenericObject || $inputType instanceof Atomic\TArray) &&
                    isset($inputType->typeParams[$offset])
            ) {
                $inputTypeParam = $inputType->typeParams[$offset];
            } elseif ($inputType instanceof Atomic\ObjectLike) {
                if ($offset === 0) {
                    $inputTypeParam = $inputType->getGenericKeyType();
                } elseif ($offset === 1) {
                    $inputTypeParam = $inputType->getGenericValueType();
                } else {
                    throw new \UnexpectedValueException('Not expecting offset of ' . $offset);
                }
            }

            $typeParam->replaceTemplateTypesWithStandins(
                $templateTypes,
                $genericParams,
                $codebase,
                $inputTypeParam
            );
        }
    }

    /**
     * @param  array<string, Union>     $templateTypes
     *
     * @return void
     */
    public function replaceTemplateTypesWithArgTypes(array $templateTypes)
    {
        foreach ($this->typeParams as $typeParam) {
            $typeParam->replaceTemplateTypesWithArgTypes($templateTypes);
        }
    }
}
