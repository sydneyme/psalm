<?php
namespace Psalm\Type;

use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\StatementsSource;
use Psalm\Storage\FileStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TLiteralFloat;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TString;
use Psalm\Type\TypeCombination;

class Union
{
    /**
     * @var array<string, Atomic>
     */
    private $types = [];

    /**
     * Whether the type originated in a docblock
     *
     * @var bool
     */
    public $fromDocblock = false;

    /**
     * Whether the type originated from integer calculation
     *
     * @var bool
     */
    public $fromCalculation = false;

    /**
     * Whether the property that this type has been derived from has been initialized in a constructor
     *
     * @var bool
     */
    public $initialized = true;

    /**
     * Whether or not the type has been checked yet
     *
     * @var bool
     */
    protected $checked = false;

    /**
     * @var bool
     */
    public $failedReconciliation = false;

    /**
     * Whether or not to ignore issues with possibly-null values
     *
     * @var bool
     */
    public $ignoreNullableIssues = false;

    /**
     * Whether or not to ignore issues with possibly-false values
     *
     * @var bool
     */
    public $ignoreFalsableIssues = false;

    /**
     * Whether or not this variable is possibly undefined
     *
     * @var bool
     */
    public $possiblyUndefined = false;

    /**
     * Whether or not this variable is possibly undefined
     *
     * @var bool
     */
    public $possiblyUndefinedFromTry = false;

    /**
     * @var array<string, TLiteralString>
     */
    private $literalStringTypes = [];

    /**
     * @var array<string, TLiteralInt>
     */
    private $literalIntTypes = [];

    /**
     * @var array<string, TLiteralFloat>
     */
    private $literalFloatTypes = [];

    /**
     * Whether or not the type was passed by reference
     *
     * @var bool
     */
    public $byRef = false;

    /** @var null|string */
    private $id;

    /**
     * Constructs an Union instance
     *
     * @param array<int, Atomic>     $types
     */
    public function __construct(array $types)
    {
        $fromDocblock = false;

        foreach ($types as $type) {
            $key = $type->getKey();
            $this->types[$key] = $type;

            if ($type instanceof TLiteralInt) {
                $this->literalIntTypes[$key] = $type;
            } elseif ($type instanceof TLiteralString) {
                $this->literalStringTypes[$key] = $type;
            } elseif ($type instanceof TLiteralFloat) {
                $this->literalFloatTypes[$key] = $type;
            }

            $fromDocblock = $fromDocblock || $type->fromDocblock;
        }

        $this->fromDocblock = $fromDocblock;
    }

    /**
     * @return array<string, Atomic>
     */
    public function getTypes()
    {
        return $this->types;
    }

    /**
     * @return void
     */
    public function addType(Atomic $type)
    {
        $this->types[$type->getKey()] = $type;

        if ($type instanceof TLiteralString) {
            $this->literalStringTypes[$type->getKey()] = $type;
        } elseif ($type instanceof TLiteralInt) {
            $this->literalIntTypes[$type->getKey()] = $type;
        } elseif ($type instanceof TLiteralFloat) {
            $this->literalFloatTypes[$type->getKey()] = $type;
        } elseif ($type instanceof TString && $this->literalStringTypes) {
            foreach ($this->literalStringTypes as $key => $_) {
                unset($this->literalStringTypes[$key]);
                unset($this->types[$key]);
            }
        } elseif ($type instanceof TInt && $this->literalIntTypes) {
            foreach ($this->literalIntTypes as $key => $_) {
                unset($this->literalIntTypes[$key]);
                unset($this->types[$key]);
            }
        } elseif ($type instanceof TFloat && $this->literalFloatTypes) {
            foreach ($this->literalFloatTypes as $key => $_) {
                unset($this->literalFloatTypes[$key]);
                unset($this->types[$key]);
            }
        }

        $this->id = null;
    }

    public function __clone()
    {
        $this->literalStringTypes = [];
        $this->literalIntTypes = [];
        $this->literalFloatTypes = [];

        foreach ($this->types as $key => &$type) {
            $type = clone $type;

            if ($type instanceof TLiteralInt) {
                $this->literalIntTypes[$key] = $type;
            } elseif ($type instanceof TLiteralString) {
                $this->literalStringTypes[$key] = $type;
            } elseif ($type instanceof TLiteralFloat) {
                $this->literalFloatTypes[$key] = $type;
            }
        }
    }

    public function __toString()
    {
        if (empty($this->types)) {
            return '';
        }
        $s = '';

        $printedInt = false;
        $printedFloat = false;
        $printedString = false;

        foreach ($this->types as $type) {
            if ($type instanceof TLiteralFloat) {
                if ($printedFloat) {
                    continue;
                }

                $printedFloat = true;
            } elseif ($type instanceof TLiteralString) {
                if ($printedString) {
                    continue;
                }

                $printedString = true;
            } elseif ($type instanceof TLiteralInt) {
                if ($printedInt) {
                    continue;
                }

                $printedInt = true;
            }

            $s .= $type . '|';
        }

        return substr($s, 0, -1) ?: '';
    }

    /**
     * @return string
     */
    public function getId()
    {
        if ($this->id) {
            return $this->id;
        }

        $s = '';
        foreach ($this->types as $type) {
            $s .= $type->getId() . '|';
        }

        $id = substr($s, 0, -1);

        $this->id = $id;

        return $id;
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
        $printedInt = false;
        $printedFloat = false;
        $printedString = false;

        $s = '';

        foreach ($this->types as $type) {
            if ($type instanceof TLiteralFloat) {
                if ($printedFloat) {
                    continue;
                }

                $printedFloat = true;
            } elseif ($type instanceof TLiteralString) {
                if ($printedString) {
                    continue;
                }

                $printedString = true;
            } elseif ($type instanceof TLiteralInt) {
                if ($printedInt) {
                    continue;
                }

                $printedInt = true;
            }

            $s .= $type->toNamespacedString($namespace, $aliasedClasses, $thisClass, $usePhpdocFormat) . '|';
        }

        return substr($s, 0, -1) ?: '';
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
        $nullable = false;

        if (!$this->isSingleAndMaybeNullable()
            || $phpMajorVersion < 7
            || (isset($this->types['null']) && $phpMinorVersion < 1)
        ) {
            return null;
        }

        $types = $this->types;

        if (isset($types['null'])) {
            unset($types['null']);

            $nullable = true;
        }

        if (!$types) {
            return null;
        }

        $atomicType = array_values($types)[0];

        $atomicTypeString = $atomicType->toPhpString(
            $namespace,
            $aliasedClasses,
            $thisClass,
            $phpMajorVersion,
            $phpMinorVersion
        );

        if ($atomicTypeString) {
            return ($nullable ? '?' : '') . $atomicTypeString;
        }

        return null;
    }

    /**
     * @return bool
     */
    public function canBeFullyExpressedInPhp()
    {
        if (!$this->isSingleAndMaybeNullable()) {
            return false;
        }

        $types = $this->types;

        if (isset($types['null'])) {
            unset($types['null']);
        }

        if (!$types) {
            return false;
        }

        $atomicType = array_values($types)[0];

        return $atomicType->canBeFullyExpressedInPhp();
    }

    /**
     * @return void
     */
    public function setFromDocblock()
    {
        $this->fromDocblock = true;

        foreach ($this->types as $type) {
            $type->setFromDocblock();
        }
    }

    /**
     * @param  string $typeString
     *
     * @return bool
     */
    public function removeType($typeString)
    {
        if (isset($this->types[$typeString])) {
            unset($this->types[$typeString]);

            if (strpos($typeString, '(')) {
                unset($this->literalStringTypes[$typeString]);
                unset($this->literalIntTypes[$typeString]);
                unset($this->literalFloatTypes[$typeString]);
            }

            $this->id = null;

            return true;
        } elseif ($typeString === 'string' && $this->literalStringTypes) {
            foreach ($this->literalStringTypes as $literalKey => $_) {
                unset($this->types[$literalKey]);
            }
            $this->literalStringTypes = [];
        } elseif ($typeString === 'int' && $this->literalIntTypes) {
            foreach ($this->literalIntTypes as $literalKey => $_) {
                unset($this->types[$literalKey]);
            }
            $this->literalIntTypes = [];
        } elseif ($typeString === 'float' && $this->literalFloatTypes) {
            foreach ($this->literalFloatTypes as $literalKey => $_) {
                unset($this->types[$literalKey]);
            }
            $this->literalFloatTypes = [];
        }

        return false;
    }

    /**
     * @return void
     */
    public function bustCache()
    {
        $this->id = null;
    }

    /**
     * @param  string  $typeString
     *
     * @return bool
     */
    public function hasType($typeString)
    {
        return isset($this->types[$typeString]);
    }

    /**
     * @return bool
     */
    public function hasGeneric()
    {
        foreach ($this->types as $type) {
            if ($type instanceof Atomic\TGenericObject || $type instanceof Atomic\TArray) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    public function hasArray()
    {
        return isset($this->types['array']);
    }

    /**
     * @return bool
     */
    public function hasObjectType()
    {
        foreach ($this->types as $type) {
            if ($type->isObjectType()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    public function hasObject()
    {
        foreach ($this->types as $type) {
            if ($type instanceof Type\Atomic\TObject) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    public function isNullable()
    {
        return isset($this->types['null']);
    }

    /**
     * @return bool
     */
    public function isFalsable()
    {
        return isset($this->types['false']);
    }

    /**
     * @return bool
     */
    public function hasBool()
    {
        return isset($this->types['bool']) || isset($this->types['false']) || isset($this->types['true']);
    }

    /**
     * @return bool
     */
    public function hasString()
    {
        return isset($this->types['string']) || $this->literalStringTypes;
    }

    /**
     * @return bool
     */
    public function hasInt()
    {
        return isset($this->types['int']) || $this->literalIntTypes;
    }

    /**
     * @return bool
     */
    public function hasFloat()
    {
        return isset($this->types['float']) || $this->literalFloatTypes;
    }

    /**
     * @return bool
     */
    public function hasDefinitelyNumericType()
    {
        return isset($this->types['int'])
            || isset($this->types['float'])
            || isset($this->types['numeric-string'])
            || $this->literalIntTypes
            || $this->literalFloatTypes;
    }

    /**
     * @return bool
     */
    public function hasPossiblyNumericType()
    {
        return isset($this->types['int'])
            || isset($this->types['float'])
            || isset($this->types['string'])
            || isset($this->types['numeric-string'])
            || $this->literalIntTypes
            || $this->literalFloatTypes
            || $this->literalStringTypes;
    }

    /**
     * @return bool
     */
    public function hasScalar()
    {
        return isset($this->types['scalar']);
    }

    /**
     * @return bool
     */
    public function hasScalarType()
    {
        return isset($this->types['int'])
            || isset($this->types['float'])
            || isset($this->types['string'])
            || isset($this->types['bool'])
            || isset($this->types['false'])
            || isset($this->types['true'])
            || isset($this->types['numeric'])
            || isset($this->types['numeric-string'])
            || $this->literalIntTypes
            || $this->literalFloatTypes
            || $this->literalStringTypes;
    }

    /**
     * @return bool
     */
    public function isMixed()
    {
        return isset($this->types['mixed']);
    }

    /**
     * @return bool
     */
    public function isEmptyMixed()
    {
        return isset($this->types['mixed'])
            && $this->types['mixed'] instanceof Type\Atomic\TEmptyMixed;
    }

    /**
     * @return bool
     */
    public function isVanillaMixed()
    {
        /**
         * @psalm-suppress UndefinedPropertyFetch
         */
        return isset($this->types['mixed'])
            && !$this->types['mixed']->fromIsset
            && !$this->types['mixed'] instanceof Type\Atomic\TEmptyMixed;
    }

    /**
     * @return bool
     */
    public function isNull()
    {
        return count($this->types) === 1 && isset($this->types['null']);
    }

    /**
     * @return bool
     */
    public function isFalse()
    {
        return count($this->types) === 1 && isset($this->types['false']);
    }

    /**
     * @return bool
     */
    public function isVoid()
    {
        return isset($this->types['void']);
    }

    /**
     * @return bool
     */
    public function isGenerator()
    {
        return count($this->types) === 1 && isset($this->types['Generator']);
    }

    /**
     * @return bool
     */
    public function isEmpty()
    {
        return isset($this->types['empty']);
    }

    /**
     * @return void
     */
    public function substitute(Union $oldType, Union $newType = null)
    {
        if ($this->isMixed() && !$this->isEmptyMixed()) {
            return;
        }

        if ($newType && $newType->ignoreNullableIssues) {
            $this->ignoreNullableIssues = true;
        }

        if ($newType && $newType->ignoreFalsableIssues) {
            $this->ignoreFalsableIssues = true;
        }

        foreach ($oldType->types as $oldTypePart) {
            if (!$this->removeType($oldTypePart->getKey())) {
                if ($oldTypePart instanceof Type\Atomic\TFalse
                    && isset($this->types['bool'])
                    && !isset($this->types['true'])
                ) {
                    $this->removeType('bool');
                    $this->types['true'] = new Type\Atomic\TTrue;
                } elseif ($oldTypePart instanceof Type\Atomic\TTrue
                    && isset($this->types['bool'])
                    && !isset($this->types['false'])
                ) {
                    $this->removeType('bool');
                    $this->types['false'] = new Type\Atomic\TFalse;
                } elseif (isset($this->types['iterable'])) {
                    if ($oldTypePart instanceof Type\Atomic\TNamedObject
                        && $oldTypePart->value === 'Traversable'
                        && !isset($this->types['array'])
                    ) {
                        $this->removeType('iterable');
                        $this->types['array'] = new Type\Atomic\TArray([Type::getMixed(), Type::getMixed()]);
                    }

                    if ($oldTypePart instanceof Type\Atomic\TArray
                        && !isset($this->types['traversable'])
                    ) {
                        $this->removeType('iterable');
                        $this->types['traversable'] = new Type\Atomic\TNamedObject('Traversable');
                    }
                }
            }
        }

        if ($newType) {
            foreach ($newType->types as $key => $newTypePart) {
                if (!isset($this->types[$key])
                    || ($newTypePart instanceof Type\Atomic\Scalar
                        && get_class($newTypePart) === get_class($this->types[$key]))
                ) {
                    $this->types[$key] = $newTypePart;
                } else {
                    $combined = TypeCombination::combineTypes([$newTypePart, $this->types[$key]]);
                    $this->types[$key] = array_values($combined->types)[0];
                }
            }
        } elseif (count($this->types) === 0) {
            $this->types['mixed'] = new Atomic\TMixed();
        }

        $this->id = null;
    }

    /**
     * @param  array<string, Union> $templateTypes
     * @param  array<string, Union> $genericParams
     * @param  Type\Union|null      $inputType
     *
     * @return void
     */
    public function replaceTemplateTypesWithStandins(
        array $templateTypes,
        array &$genericParams,
        Codebase $codebase = null,
        Type\Union $inputType = null
    ) {
        $keysToUnset = [];

        foreach ($this->types as $key => $atomicType) {
            if (isset($templateTypes[$key])) {
                if ($templateTypes[$key]->getId() !== $key) {
                    $keysToUnset[] = $key;
                    $firstAtomicType = array_values($templateTypes[$key]->getTypes())[0];
                    $this->types[$firstAtomicType->getKey()] = clone $firstAtomicType;

                    if ($inputType) {
                        $genericParams[$key] = clone $inputType;
                        $genericParams[$key]->setFromDocblock();
                    }
                }
            } else {
                $matchingAtomicType = null;

                if ($inputType && $codebase) {
                    foreach ($inputType->types as $inputKey => $atomicInputType) {
                        if ($inputKey === $key) {
                            $matchingAtomicType = $atomicInputType;
                            break;
                        }

                        if ($inputKey === 'Closure' && $key === 'callable') {
                            $matchingAtomicType = $atomicInputType;
                            break;
                        }

                        if (strpos($inputKey, $key . '&') === 0) {
                            $matchingAtomicType = $atomicInputType;
                            break;
                        }

                        if ($atomicInputType instanceof TNamedObject && $atomicType instanceof TNamedObject) {
                            try {
                                $classlikeStorage =
                                    $codebase->classlikeStorageProvider->get($atomicInputType->value);

                                if ($classlikeStorage->templateParents
                                    && in_array($atomicType->value, $classlikeStorage->templateParents)
                                ) {
                                    $matchingAtomicType = $atomicInputType;
                                        break;
                                }
                            } catch (\InvalidArgumentException $e) {
                                // do nothing
                            }
                        }
                    }
                }

                $atomicType->replaceTemplateTypesWithStandins(
                    $templateTypes,
                    $genericParams,
                    $codebase,
                    $matchingAtomicType
                );
            }
        }

        foreach ($keysToUnset as $key) {
            unset($this->types[$key]);
        }

        //var_dump($this->types, $genericParams);

        $this->id = null;
    }

    /**
     * @param  array<string, Type\Union>     $templateTypes
     *
     * @return void
     */
    public function replaceTemplateTypesWithArgTypes(array $templateTypes)
    {
        $keysToUnset = [];

        $newTypes = [];

        $isMixed = false;

        foreach ($this->types as $key => $atomicType) {
            if (isset($templateTypes[$key])) {
                $keysToUnset[] = $key;
                $templateType = clone $templateTypes[$key];

                foreach ($templateType->types as $templateTypePart) {
                    if ($templateTypePart instanceof Type\Atomic\TMixed) {
                        $isMixed = true;
                    }

                    $newTypes[$templateTypePart->getKey()] = $templateTypePart;
                }
            } else {
                $atomicType->replaceTemplateTypesWithArgTypes($templateTypes);
            }
        }

        $this->id = null;

        if ($isMixed) {
            $this->types = $newTypes;

            return;
        }

        foreach ($keysToUnset as $key) {
            unset($this->types[$key]);
        }

        $this->types = array_merge($this->types, $newTypes);
    }

    /**
     * @return bool
     */
    public function isSingle()
    {
        $typeCount = count($this->types);

        $intLiteralCount = count($this->literalIntTypes);
        $stringLiteralCount = count($this->literalStringTypes);
        $floatLiteralCount = count($this->literalFloatTypes);

        if (($intLiteralCount && $stringLiteralCount)
            || ($intLiteralCount && $floatLiteralCount)
            || ($stringLiteralCount && $floatLiteralCount)
        ) {
            return false;
        }

        if ($intLiteralCount || $stringLiteralCount || $floatLiteralCount) {
            $typeCount -= $intLiteralCount + $stringLiteralCount + $floatLiteralCount - 1;
        }

        return $typeCount === 1;
    }

    /**
     * @return bool
     */
    public function isSingleAndMaybeNullable()
    {
        $isNullable = isset($this->types['null']);

        $typeCount = count($this->types);

        if ($typeCount === 1 && $isNullable) {
            return false;
        }

        $intLiteralCount = count($this->literalIntTypes);
        $stringLiteralCount = count($this->literalStringTypes);
        $floatLiteralCount = count($this->literalFloatTypes);

        if (($intLiteralCount && $stringLiteralCount)
            || ($intLiteralCount && $floatLiteralCount)
            || ($stringLiteralCount && $floatLiteralCount)
        ) {
            return false;
        }

        if ($intLiteralCount || $stringLiteralCount || $floatLiteralCount) {
            $typeCount -= $intLiteralCount + $stringLiteralCount + $floatLiteralCount - 1;
        }

        return ($typeCount - (int) $isNullable) === 1;
    }

    /**
     * @return bool true if this is an int
     */
    public function isInt()
    {
        if (!$this->isSingle()) {
            return false;
        }

        return isset($this->types['float']) || $this->literalIntTypes;
    }

    /**
     * @return bool true if this is a float
     */
    public function isFloat()
    {
        if (!$this->isSingle()) {
            return false;
        }

        return isset($this->types['float']) || $this->literalFloatTypes;
    }

    /**
     * @return bool true if this is a string
     */
    public function isString()
    {
        if (!$this->isSingle()) {
            return false;
        }

        return isset($this->types['string']) || $this->literalStringTypes;
    }

    /**
     * @return bool true if this is a string literal with only one possible value
     */
    public function isSingleStringLiteral()
    {
        return count($this->types) === 1 && count($this->literalStringTypes) === 1;
    }

    /**
     * @return TLiteralString the only string literal represented by this union type
     * @throws \InvalidArgumentException if isSingleStringLiteral is false
     */
    public function getSingleStringLiteral()
    {
        if (count($this->types) !== 1 || count($this->literalStringTypes) !== 1) {
            throw new \InvalidArgumentException("Not a string literal");
        }

        return reset($this->literalStringTypes);
    }

    /**
     * @return bool true if this is a int literal with only one possible value
     */
    public function isSingleIntLiteral()
    {
        return count($this->types) === 1 && count($this->literalIntTypes) === 1;
    }

    /**
     * @return TLiteralInt the only int literal represented by this union type
     * @throws \InvalidArgumentException if isSingleIntLiteral is false
     */
    public function getSingleIntLiteral()
    {
        if (count($this->types) !== 1 || count($this->literalIntTypes) !== 1) {
            throw new \InvalidArgumentException("Not an int literal");
        }

        return reset($this->literalIntTypes);
    }

    /**
     * @param  StatementsSource $source
     * @param  CodeLocation     $codeLocation
     * @param  array<string>    $suppressedIssues
     * @param  array<string, bool> $phantomClasses
     * @param  bool             $inferred
     *
     * @return void
     */
    public function check(
        StatementsSource $source,
        CodeLocation $codeLocation,
        array $suppressedIssues,
        array $phantomClasses = [],
        $inferred = true
    ) {
        if ($this->checked) {
            return;
        }

        foreach ($this->types as $atomicType) {
            $atomicType->check($source, $codeLocation, $suppressedIssues, $phantomClasses, $inferred);
        }

        $this->checked = true;
    }

    /**
     * @param  array<string, mixed> $phantomClasses
     *
     * @return void
     */
    public function queueClassLikesForScanning(
        Codebase $codebase,
        FileStorage $fileStorage = null,
        array $phantomClasses = []
    ) {
        foreach ($this->types as $atomicType) {
            $atomicType->queueClassLikesForScanning(
                $codebase,
                $fileStorage,
                $phantomClasses
            );
        }
    }

    /**
     * @return bool
     */
    public function equals(Union $otherType)
    {
        if ($otherType->id && $this->id && $otherType->id !== $this->id) {
            return false;
        }

        if ($this->possiblyUndefined !== $otherType->possiblyUndefined) {
            return false;
        }

        if ($this->possiblyUndefinedFromTry !== $otherType->possiblyUndefinedFromTry) {
            return false;
        }

        if ($this->fromCalculation !== $otherType->fromCalculation) {
            return false;
        }

        if ($this->initialized !== $otherType->initialized) {
            return false;
        }

        if ($this->fromDocblock !== $otherType->fromDocblock) {
            return false;
        }

        if (count($this->types) !== count($otherType->types)) {
            return false;
        }

        $otherAtomicTypes = $otherType->types;

        foreach ($this->types as $key => $atomicType) {
            if (!isset($otherAtomicTypes[$key])) {
                return false;
            }

            if (!$atomicType->equals($otherAtomicTypes[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, TLiteralString>
     */
    public function getLiteralStrings()
    {
        return $this->literalStringTypes;
    }

    /**
     * @return array<string, TLiteralInt>
     */
    public function getLiteralInts()
    {
        return $this->literalIntTypes;
    }

    /**
     * @return array<string, TLiteralFloat>
     */
    public function getLiteralFloats()
    {
        return $this->literalFloatTypes;
    }
}
