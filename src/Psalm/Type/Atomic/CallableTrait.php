<?php
namespace Psalm\Type\Atomic;

use Psalm\Codebase;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type\Atomic;
use Psalm\Type\Union;

trait CallableTrait
{
    /**
     * @var array<int, FunctionLikeParameter>|null
     */
    public $params = [];

    /**
     * @var Union|null
     */
    public $returnType;

    /**
     * Constructs a new instance of a generic type
     *
     * @param string                            $value
     * @param array<int, FunctionLikeParameter> $params
     * @param Union                             $returnType
     */
    public function __construct($value = 'callable', array $params = null, Union $returnType = null)
    {
        $this->value = $value;
        $this->params = $params;
        $this->returnType = $returnType;
    }

    public function __clone()
    {
        if ($this->params) {
            foreach ($this->params as &$param) {
                $param = clone $param;
            }
        }

        $this->returnType = $this->returnType ? clone $this->returnType : null;
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
            if ($this instanceof TNamedObject) {
                return parent::toNamespacedString($namespace, $aliasedClasses, $thisClass, true);
            }

            return $this->value;
        }

        $paramString = '';
        $returnTypeString = '';

        if ($this->params !== null) {
            $paramString = '(' . implode(
                ', ',
                array_map(
                    /**
                     * @return string
                     */
                    function (FunctionLikeParameter $param) use ($namespace, $aliasedClasses, $thisClass) {
                        if (!$param->type) {
                            throw new \UnexpectedValueException('Param type must not be null');
                        }

                        $typeString = $param->type->toNamespacedString(
                            $namespace,
                            $aliasedClasses,
                            $thisClass,
                            false
                        );

                        return ($param->isVariadic ? '...' : '') . $typeString . ($param->isOptional ? '=' : '');
                    },
                    $this->params
                )
            ) . ')';
        }

        if ($this->returnType !== null) {
            $returnTypeMultiple = count($this->returnType->getTypes()) > 1;

            $returnTypeString = ':' . ($returnTypeMultiple ? '(' : '') . $this->returnType->toNamespacedString(
                $namespace,
                $aliasedClasses,
                $thisClass,
                false
            ) . ($returnTypeMultiple ? ')' : '');
        }

        if ($this instanceof TNamedObject) {
            return parent::toNamespacedString($namespace, $aliasedClasses, $thisClass, true)
                . $paramString . $returnTypeString;
        }

        return 'callable' . $paramString . $returnTypeString;
    }

    public function getId()
    {
        $paramString = '';
        $returnTypeString = '';

        if ($this->params !== null) {
            $paramString = '(' . implode(', ', $this->params) . ')';
        }

        if ($this->returnType !== null) {
            $returnTypeMultiple = count($this->returnType->getTypes()) > 1;
            $returnTypeString = ':' . ($returnTypeMultiple ? '(' : '')
                . $this->returnType . ($returnTypeMultiple ? ')' : '');
        }

        return $this->value . $paramString . $returnTypeString;
    }

    public function __toString()
    {
        return $this->getId();
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
        if ($this->params) {
            foreach ($this->params as $offset => $param) {
                $inputParamType = null;

                if ($inputType instanceof Atomic\Fn
                    && isset($inputType->params[$offset])
                ) {
                    $inputParamType = $inputType->params[$offset]->type;
                }

                if (!$param->type) {
                    continue;
                }

                $param->type->replaceTemplateTypesWithStandins(
                    $templateTypes,
                    $genericParams,
                    $codebase,
                    $inputParamType
                );
            }
        }

        if (($inputType instanceof Atomic\TCallable || $inputType instanceof Atomic\Fn)
            && $this->returnType
            && $inputType->returnType
        ) {
            $this->returnType->replaceTemplateTypesWithStandins(
                $templateTypes,
                $genericParams,
                $codebase,
                $inputType->returnType
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
        if ($this->params) {
            foreach ($this->params as $param) {
                if (!$param->type) {
                    continue;
                }

                $param->type->replaceTemplateTypesWithArgTypes($templateTypes);
            }
        }

        if ($this->returnType) {
            $this->returnType->replaceTemplateTypesWithArgTypes($templateTypes);
        }
    }
}
