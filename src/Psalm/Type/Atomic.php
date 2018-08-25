<?php
namespace Psalm\Type;

use Psalm\Checker\ClassLikeChecker;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Issue\ReservedWord;
use Psalm\IssueBuffer;
use Psalm\StatementsSource;
use Psalm\Storage\FileStorage;
use Psalm\Type;
use Psalm\Type\Atomic\ObjectLike;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TEmpty;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TGenericParam;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TNumeric;
use Psalm\Type\Atomic\TNumericString;
use Psalm\Type\Atomic\TObject;
use Psalm\Type\Atomic\TResource;
use Psalm\Type\Atomic\TScalar;
use Psalm\Type\Atomic\TScalarClassConstant;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TTrue;
use Psalm\Type\Atomic\TVoid;

abstract class Atomic
{
    const KEY = 'atomic';

    /**
     * Whether or not the type has been checked yet
     *
     * @var bool
     */
    protected $checked = false;

    /**
     * Whether or not the type comes from a docblock
     *
     * @var bool
     */
    public $fromDocblock = false;

    /**
     * @param  string $value
     * @param  bool   $phpCompatible
     * @param  array<string, string> $templateTypeNames
     *
     * @return Atomic
     */
    public static function create($value, $phpCompatible = false, array $templateTypeNames = [])
    {
        switch ($value) {
            case 'int':
                return new TInt();

            case 'void':
                return new TVoid();

            case 'float':
                return new TFloat();

            case 'string':
                return new TString();

            case 'bool':
                return new TBool();

            case 'object':
                return new TObject();

            case 'callable':
                return new TCallable();

            case 'array':
                return new TArray([new Union([new TMixed]), new Union([new TMixed])]);

            case 'resource':
                return $phpCompatible ? new TNamedObject($value) : new TResource();

            case 'numeric':
                return $phpCompatible ? new TNamedObject($value) : new TNumeric();

            case 'true':
                return $phpCompatible ? new TNamedObject($value) : new TTrue();

            case 'false':
                return $phpCompatible ? new TNamedObject($value) : new TFalse();

            case 'empty':
                return $phpCompatible ? new TNamedObject($value) : new TEmpty();

            case 'scalar':
                return $phpCompatible ? new TNamedObject($value) : new TScalar();

            case 'null':
                return $phpCompatible ? new TNamedObject($value) : new TNull();

            case 'mixed':
                return $phpCompatible ? new TNamedObject($value) : new TMixed();

            case 'class-string':
                return new TClassString();

            case 'numeric-string':
                return new TNumericString();

            case '$this':
                return new TNamedObject('static');

            default:
                if (strpos($value, '-')) {
                    throw new \Psalm\Exception\TypeParseTreeException('no hyphens allowed');
                }

                if (is_numeric($value[0])) {
                    throw new \Psalm\Exception\TypeParseTreeException('First character of type cannot be numeric');
                }

                if (isset($templateTypeNames[$value])) {
                    return new TGenericParam($value);
                }

                return new TNamedObject($value);
        }
    }

    /**
     * @return string
     */
    abstract public function getKey();

    /**
     * @return bool
     */
    public function isNumericType()
    {
        return $this instanceof TInt
            || $this instanceof TFloat
            || $this instanceof TNumericString
            || $this instanceof TNumeric;
    }

    /**
     * @return bool
     */
    public function isObjectType()
    {
        return $this instanceof TObject || $this instanceof TNamedObject;
    }

    /**
     * @return bool
     */
    public function isIterable(Codebase $codebase)
    {
        return $this instanceof TNamedObject && (strtolower($this->value) === 'iterable')
            || $this->isTraversable($codebase)
            || $this instanceof TArray
            || $this instanceof ObjectLike;
    }

    /**
     * @return bool
     */
    public function isTraversable(Codebase $codebase)
    {
        return $this instanceof TNamedObject
            && (strtolower($this->value) === 'traversable'
                || $codebase->classExtendsOrImplements(
                    $this->value,
                    'Traversable'
                ) || $codebase->interfaceExtends(
                    $this->value,
                    'Traversable'
                )
            );
    }

    /**
     * @param  StatementsSource $source
     * @param  CodeLocation     $codeLocation
     * @param  array<string>    $suppressedIssues
     * @param  array<string, bool> $phantomClasses
     * @param  bool             $inferred
     *
     * @return false|null
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

        if ($this instanceof TNamedObject) {
            if (!isset($phantomClasses[strtolower($this->value)]) &&
                ClassLikeChecker::checkFullyQualifiedClassLikeName(
                    $source,
                    $this->value,
                    $codeLocation,
                    $suppressedIssues,
                    $inferred
                ) === false
            ) {
                return false;
            }

            if ($this->extraTypes) {
                foreach ($this->extraTypes as $extraType) {
                    if (!isset($phantomClasses[strtolower($extraType->value)]) &&
                        ClassLikeChecker::checkFullyQualifiedClassLikeName(
                            $source,
                            $extraType->value,
                            $codeLocation,
                            $suppressedIssues,
                            $inferred
                        ) === false
                    ) {
                        return false;
                    }
                }
            }
        }

        if ($this instanceof TScalarClassConstant) {
            if (ClassLikeChecker::checkFullyQualifiedClassLikeName(
                $source,
                $this->fqClasslikeName,
                $codeLocation,
                $suppressedIssues,
                $inferred
            ) === false
            ) {
                return false;
            }
        }

        if ($this instanceof TResource && !$this->fromDocblock) {
            if (IssueBuffer::accepts(
                new ReservedWord(
                    '\'resource\' is a reserved word',
                    $codeLocation,
                    'resource'
                ),
                $source->getSuppressedIssues()
            )) {
                // fall through
            }
        }

        if ($this instanceof Type\Atomic\TArray || $this instanceof Type\Atomic\TGenericObject) {
            foreach ($this->typeParams as $typeParam) {
                $typeParam->check($source, $codeLocation, $suppressedIssues, $phantomClasses, $inferred);
            }
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
        if ($this instanceof TNamedObject && !isset($phantomClasses[strtolower($this->value)])) {
            $codebase->scanner->queueClassLikeForScanning(
                $this->value,
                $fileStorage ? $fileStorage->filePath : null,
                false,
                !$this->fromDocblock
            );
            if ($fileStorage) {
                $fileStorage->referencedClasslikes[] = $this->value;
            }

            return;
        }

        if ($this instanceof TScalarClassConstant) {
            $codebase->scanner->queueClassLikeForScanning(
                $this->fqClasslikeName,
                $fileStorage ? $fileStorage->filePath : null,
                false,
                !$this->fromDocblock
            );
            if ($fileStorage) {
                $fileStorage->referencedClasslikes[] = $this->fqClasslikeName;
            }
        }

        if ($this instanceof Type\Atomic\TArray || $this instanceof Type\Atomic\TGenericObject) {
            foreach ($this->typeParams as $typeParam) {
                $typeParam->queueClassLikesForScanning(
                    $codebase,
                    $fileStorage,
                    $phantomClasses
                );
            }
        }
    }

    /**
     * @param  Atomic $other
     *
     * @return bool
     */
    public function shallowEquals(Atomic $other)
    {
        return strtolower($this->getKey()) === strtolower($other->getKey())
            && !($other instanceof ObjectLike && $this instanceof ObjectLike);
    }

    public function __toString()
    {
        return '';
    }

    /**
     * @return string
     */
    public function getId()
    {
        return $this->__toString();
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
        return $this->getKey();
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
    abstract public function toPhpString(
        $namespace,
        array $aliasedClasses,
        $thisClass,
        $phpMajorVersion,
        $phpMinorVersion
    );

    /**
     * @return bool
     */
    abstract public function canBeFullyExpressedInPhp();

    /**
     * @return void
     */
    public function setFromDocblock()
    {
        $this->fromDocblock = true;
    }

    /**
     * @param  array<string, Type\Union> $templateTypes
     * @param  array<string, Type\Union> $genericParams
     * @param  Type\Atomic|null          $inputType
     *
     * @return void
     */
    public function replaceTemplateTypesWithStandins(
        array $templateTypes,
        array &$genericParams,
        Codebase $codebase = null,
        Type\Atomic $inputType = null
    ) {
        // do nothing
    }

    /**
     * @param  array<string, Type\Union>     $templateTypes
     *
     * @return void
     */
    public function replaceTemplateTypesWithArgTypes(array $templateTypes)
    {
        // do nothing
    }

    /**
     * @return bool
     */
    public function equals(Atomic $otherType)
    {
        if (get_class($otherType) !== get_class($this)) {
            return false;
        }

        return true;
    }
}
