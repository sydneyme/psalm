<?php
namespace Psalm;

use Psalm\Exception\TypeParseTreeException;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\ObjectLike;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TEmpty;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TLiteralFloat;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TNumeric;
use Psalm\Type\Atomic\TObject;
use Psalm\Type\Atomic\TResource;
use Psalm\Type\Atomic\TSingleLetter;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TTrue;
use Psalm\Type\Atomic\TVoid;
use Psalm\Type\ParseTree;
use Psalm\Type\TypeCombination;
use Psalm\Type\Union;

abstract class Type
{
    /**
     * @var array<string, bool>
     */
    public static $PSALMRESERVEDWORDS = [
        'int' => true,
        'string' => true,
        'float' => true,
        'bool' => true,
        'false' => true,
        'true' => true,
        'object' => true,
        'empty' => true,
        'callable' => true,
        'array' => true,
        'iterable' => true,
        'null' => true,
        'mixed' => true,
        'numeric-string' => true,
        'class-string' => true,
        'boolean' => true,
        'integer' => true,
        'double' => true,
        'real' => true,
        'resource' => true,
        'void' => true,
        'self' => true,
        'static' => true,
        'scalar' => true,
        'numeric' => true,
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private static $memoizedTokens = [];

    /**
     * Parses a string type representation
     *
     * @param  string $typeString
     * @param  bool   $phpCompatible
     * @param  array<string, string> $templateTypeNames
     *
     * @return Union
     */
    public static function parseString($typeString, $phpCompatible = false, array $templateTypeNames = [])
    {
        return self::parseTokens(self::tokenize($typeString), $phpCompatible, $templateTypeNames);
    }

    /**
     * Parses a string type representation
     *
     * @param  array<int, string> $typeTokens
     * @param  bool   $phpCompatible
     * @param  array<string, string> $templateTypeNames
     *
     * @return Union
     */
    public static function parseTokens(array $typeTokens, $phpCompatible = false, array $templateTypeNames = [])
    {
        if (count($typeTokens) === 1) {
            $onlyToken = $typeTokens[0];

            // Note: valid identifiers can include class names or $this
            if (!preg_match('@^(\$this|\\\\?[a-zA-Z_\x7f-\xff][\\\\\-0-9a-zA-Z_\x7f-\xff]*)$@', $onlyToken)) {
                throw new TypeParseTreeException("Invalid type '$onlyToken'");
            }

            $onlyToken = self::fixScalarTerms($onlyToken, $phpCompatible);

            return new Union([Atomic::create($onlyToken, $phpCompatible, $templateTypeNames)]);
        }

        try {
            $parseTree = ParseTree::createFromTokens($typeTokens);
            $parsedType = self::getTypeFromTree($parseTree, $phpCompatible, $templateTypeNames);
        } catch (TypeParseTreeException $e) {
            throw $e;
        }

        if (!($parsedType instanceof Union)) {
            $parsedType = new Union([$parsedType]);
        }

        return $parsedType;
    }

    /**
     * @param  string $typeString
     * @param  bool   $phpCompatible
     *
     * @return string
     */
    private static function fixScalarTerms($typeString, $phpCompatible = false)
    {
        $typeStringLc = strtolower($typeString);

        switch ($typeStringLc) {
            case 'int':
            case 'void':
            case 'float':
            case 'string':
            case 'bool':
            case 'callable':
            case 'iterable':
            case 'array':
            case 'object':
            case 'numeric':
            case 'true':
            case 'false':
            case 'null':
            case 'mixed':
            case 'resource':
                return $typeStringLc;
        }

        switch ($typeString) {
            case 'boolean':
                return $phpCompatible ? $typeString : 'bool';

            case 'integer':
                return $phpCompatible ? $typeString : 'int';

            case 'double':
            case 'real':
                return $phpCompatible ? $typeString : 'float';
        }

        return $typeString;
    }

    /**
     * @param  ParseTree $parseTree
     * @param  bool      $phpCompatible
     * @param  array<string, string> $templateTypeNames
     *
     * @return  Atomic|TArray|TGenericObject|ObjectLike|Union
     */
    public static function getTypeFromTree(
        ParseTree $parseTree,
        $phpCompatible = false,
        array $templateTypeNames = []
    ) {
        if ($parseTree instanceof ParseTree\GenericTree) {
            $genericType = $parseTree->value;

            $genericParams = array_map(
                /**
                 * @return Union
                 */
                function (ParseTree $childTree) use ($templateTypeNames) {
                    $treeType = self::getTypeFromTree($childTree, false, $templateTypeNames);

                    return $treeType instanceof Union ? $treeType : new Union([$treeType]);
                },
                $parseTree->children
            );

            $genericTypeValue = self::fixScalarTerms($genericType, false);

            if (($genericTypeValue === 'array' || $genericTypeValue === 'Generator') &&
                count($genericParams) === 1
            ) {
                array_unshift($genericParams, new Union([new TMixed]));
            }

            if (!$genericParams) {
                throw new \InvalidArgumentException('No generic params provided for type');
            }

            if ($genericTypeValue === 'array') {
                return new TArray($genericParams);
            }

            return new TGenericObject($genericTypeValue, $genericParams);
        }

        if ($parseTree instanceof ParseTree\UnionTree) {
            $hasNull = false;

            $atomicTypes = [];

            foreach ($parseTree->children as $childTree) {
                if ($childTree instanceof ParseTree\NullableTree) {
                    $atomicType = self::getTypeFromTree($childTree->children[0], false, $templateTypeNames);
                    $hasNull = true;
                } else {
                    $atomicType = self::getTypeFromTree($childTree, false, $templateTypeNames);
                }

                if ($atomicType instanceof Union) {
                    foreach ($atomicType->getTypes() as $type) {
                        $atomicTypes[] = $type;
                    }

                    continue;
                }

                $atomicTypes[] = $atomicType;
            }

            if ($hasNull) {
                $atomicTypes[] = new TNull;
            }

            return TypeCombination::combineTypes($atomicTypes);
        }

        if ($parseTree instanceof ParseTree\IntersectionTree) {
            $intersectionTypes = array_map(
                /**
                 * @return Atomic
                 */
                function (ParseTree $childTree) use ($templateTypeNames) {
                    $atomicType = self::getTypeFromTree($childTree, false, $templateTypeNames);

                    if (!$atomicType instanceof Atomic) {
                        throw new TypeParseTreeException(
                            'Intersection types cannot contain unions'
                        );
                    }

                    return $atomicType;
                },
                $parseTree->children
            );

            foreach ($intersectionTypes as $intersectionType) {
                if (!$intersectionType instanceof TNamedObject) {
                    throw new TypeParseTreeException('Intersection types must all be objects');
                }
            }

            /** @var TNamedObject[] $intersectionTypes */
            $firstType = array_shift($intersectionTypes);

            $firstType->extraTypes = $intersectionTypes;

            return $firstType;
        }

        if ($parseTree instanceof ParseTree\ObjectLikeTree) {
            $properties = [];

            $type = $parseTree->value;

            foreach ($parseTree->children as $i => $propertyBranch) {
                if (!$propertyBranch instanceof ParseTree\ObjectLikePropertyTree) {
                    $propertyType = self::getTypeFromTree($propertyBranch, false, $templateTypeNames);
                    $propertyMaybeUndefined = false;
                    $propertyKey = (string)$i;
                } elseif (count($propertyBranch->children) === 1) {
                    $propertyType = self::getTypeFromTree($propertyBranch->children[0], false, $templateTypeNames);
                    $propertyMaybeUndefined = $propertyBranch->possiblyUndefined;
                    $propertyKey = $propertyBranch->value;
                } else {
                    throw new \InvalidArgumentException(
                        'Unexpected number of property parts (' . count($propertyBranch->children) . ')'
                    );
                }

                if (!$propertyType instanceof Union) {
                    $propertyType = new Union([$propertyType]);
                }

                if ($propertyMaybeUndefined) {
                    $propertyType->possiblyUndefined = true;
                }

                $properties[$propertyKey] = $propertyType;
            }

            if ($type !== 'array') {
                throw new \InvalidArgumentException('Object-like type must be array');
            }

            if (!$properties) {
                throw new \InvalidArgumentException('No properties supplied for ObjectLike');
            }

            return new ObjectLike($properties);
        }

        if ($parseTree instanceof ParseTree\CallableWithReturnTypeTree) {
            $callableType = self::getTypeFromTree($parseTree->children[0], false, $templateTypeNames);

            if (!$callableType instanceof TCallable && !$callableType instanceof Type\Atomic\Fn) {
                throw new \InvalidArgumentException('Parsing callable tree node should return TCallable');
            }

            if (!isset($parseTree->children[1])) {
                throw new TypeParseTreeException('Invalid return type');
            }

            $returnType = self::getTypeFromTree($parseTree->children[1], false, $templateTypeNames);

            $callableType->returnType = $returnType instanceof Union ? $returnType : new Union([$returnType]);

            return $callableType;
        }

        if ($parseTree instanceof ParseTree\CallableTree) {
            $params = array_map(
                /**
                 * @return FunctionLikeParameter
                 */
                function (ParseTree $childTree) use ($templateTypeNames) {
                    $isVariadic = false;
                    $isOptional = false;

                    if ($childTree instanceof ParseTree\CallableParamTree) {
                        $treeType = self::getTypeFromTree($childTree->children[0], false, $templateTypeNames);
                        $isVariadic = $childTree->variadic;
                        $isOptional = $childTree->hasDefault;
                    } else {
                        $treeType = self::getTypeFromTree($childTree, false, $templateTypeNames);
                    }

                    $treeType = $treeType instanceof Union ? $treeType : new Union([$treeType]);

                    return new FunctionLikeParameter(
                        '',
                        false,
                        $treeType,
                        null,
                        null,
                        $isOptional,
                        false,
                        $isVariadic
                    );
                },
                $parseTree->children
            );

            if (in_array(strtolower($parseTree->value), ['closure', '\closure'], true)) {
                return new Type\Atomic\Fn('Closure', $params);
            }

            return new TCallable($parseTree->value, $params);
        }

        if ($parseTree instanceof ParseTree\EncapsulationTree) {
            return self::getTypeFromTree($parseTree->children[0], false, $templateTypeNames);
        }

        if ($parseTree instanceof ParseTree\NullableTree) {
            $atomicType = self::getTypeFromTree($parseTree->children[0], false, $templateTypeNames);

            if (!$atomicType instanceof Atomic) {
                throw new \UnexpectedValueException(
                    'Was expecting an atomic type, got ' . get_class($atomicType)
                );
            }

            return TypeCombination::combineTypes([
                new TNull,
                $atomicType
            ]);
        }

        if (!$parseTree instanceof ParseTree\Value) {
            throw new \InvalidArgumentException('Unrecognised parse tree type ' . get_class($parseTree));
        }

        if ($parseTree->value[0] === '"' || $parseTree->value[0] === '\'') {
            return new TLiteralString(substr($parseTree->value, 1, -1));
        }

        if (strpos($parseTree->value, '::')) {
            list($fqClasslikeName, $constName) = explode('::', $parseTree->value);
            return new Atomic\TScalarClassConstant($fqClasslikeName, $constName);
        }

        if (preg_match('/^\-?(0|[1-9][0-9]*)$/', $parseTree->value)) {
            return new TLiteralInt((int) $parseTree->value);
        }

        if (!preg_match('@^(\$this|\\\\?[a-zA-Z_\x7f-\xff][\\\\\-0-9a-zA-Z_\x7f-\xff]*)$@', $parseTree->value)) {
            throw new TypeParseTreeException('Invalid type \'' . $parseTree->value . '\'');
        }

        $atomicType = self::fixScalarTerms($parseTree->value, $phpCompatible);

        return Atomic::create($atomicType, $phpCompatible, $templateTypeNames);
    }

    /**
     * @param  string $stringType
     * @param  bool   $ignoreSpace
     *
     * @return array<int,string>
     */
    public static function tokenize($stringType, $ignoreSpace = true)
    {
        $typeTokens = [''];
        $wasChar = false;
        $quoteChar = null;
        $escaped = false;

        if (isset(self::$memoizedTokens[$stringType])) {
            return self::$memoizedTokens[$stringType];
        }

        // index of last type token
        $rtc = 0;

        $chars = str_split($stringType);
        for ($i = 0, $c = count($chars); $i < $c; ++$i) {
            $char = $chars[$i];

            if (!$quoteChar && $char === ' ' && $ignoreSpace) {
                continue;
            }

            if ($wasChar) {
                $typeTokens[++$rtc] = '';
            }

            if ($quoteChar) {
                if ($char === $quoteChar && $i > 1 && !$escaped) {
                    $quoteChar = null;

                    $typeTokens[$rtc] .= $char;
                    $wasChar = true;

                    continue;
                }

                $wasChar = false;

                if ($char === '\\'
                    && !$escaped
                    && $i < $c - 1
                    && ($chars[$i + 1] === $quoteChar || $chars[$i + 1] === '\\')
                ) {
                    $escaped = true;
                    continue;
                }

                $escaped = false;

                $typeTokens[$rtc] .= $char;

                continue;
            }

            if ($char === '"' || $char === '\'') {
                if ($typeTokens[$rtc] === '') {
                    $typeTokens[$rtc] = $char;
                } else {
                    $typeTokens[++$rtc] = $char;
                }

                $quoteChar = $char;

                $wasChar = false;
                continue;
            }

            if ($char === '<'
                || $char === '>'
                || $char === '|'
                || $char === '?'
                || $char === ','
                || $char === '{'
                || $char === '}'
                || $char === '['
                || $char === ']'
                || $char === '('
                || $char === ')'
                || $char === ' '
                || $char === '&'
                || $char === '='
            ) {
                if ($typeTokens[$rtc] === '') {
                    $typeTokens[$rtc] = $char;
                } else {
                    $typeTokens[++$rtc] = $char;
                }

                $wasChar = true;

                continue;
            }

            if ($char === ':') {
                if ($i + 1 < $c && $chars[$i + 1] === ':') {
                    if ($typeTokens[$rtc] === '') {
                        $typeTokens[$rtc] = '::';
                    } else {
                        $typeTokens[++$rtc] = '::';
                    }

                    $wasChar = true;

                    $i++;

                    continue;
                }

                if ($typeTokens[$rtc] === '') {
                    $typeTokens[$rtc] = ':';
                } else {
                    $typeTokens[++$rtc] = ':';
                }

                $wasChar = true;

                continue;
            }

            if ($char === '.') {
                if ($i + 2 > $c || $chars[$i + 1] !== '.' || $chars[$i + 2] !== '.') {
                    throw new TypeParseTreeException('Unexpected token ' . $char);
                }

                if ($typeTokens[$rtc] === '') {
                    $typeTokens[$rtc] = '...';
                } else {
                    $typeTokens[++$rtc] = '...';
                }

                $wasChar = true;

                $i += 2;

                continue;
            }

            $typeTokens[$rtc] .= $char;
            $wasChar = false;
        }

        self::$memoizedTokens[$stringType] = $typeTokens;

        return $typeTokens;
    }

    /**
     * @param  string                       $stringType
     * @param  Aliases                      $aliases
     * @param  array<string, string>|null   $templateTypeNames
     * @param  array<string, array<int, string>>|null   $typeAliases
     *
     * @return array<int, string>
     */
    public static function fixUpLocalType(
        $stringType,
        Aliases $aliases,
        array $templateTypeNames = null,
        array $typeAliases = null
    ) {
        $typeTokens = self::tokenize($stringType);

        for ($i = 0, $l = count($typeTokens); $i < $l; $i++) {
            $stringTypeToken = $typeTokens[$i];

            if (in_array(
                $stringTypeToken,
                ['<', '>', '|', '?', ',', '{', '}', ':', '::', '[', ']', '(', ')', '&'],
                true
            )) {
                continue;
            }

            if ($stringTypeToken[0] === '"'
                || $stringTypeToken[0] === '\''
                || $stringTypeToken === '0'
                || preg_match('/[1-9]/', $stringTypeToken[0])
            ) {
                continue;
            }

            if (isset($typeTokens[$i + 1]) && $typeTokens[$i + 1] === ':') {
                continue;
            }

            if ($i > 0 && $typeTokens[$i - 1] === '::') {
                continue;
            }

            $typeTokens[$i] = $stringTypeToken = self::fixScalarTerms($stringTypeToken);

            if (isset(self::$PSALMRESERVEDWORDS[$stringTypeToken])) {
                continue;
            }

            if (isset($templateTypeNames[$stringTypeToken])) {
                continue;
            }

            if (isset($typeTokens[$i + 1])) {
                $nextChar = $typeTokens[$i + 1];
                if ($nextChar === ':') {
                    continue;
                }

                if ($nextChar === '?' && isset($typeTokens[$i + 2]) && $typeTokens[$i + 2] === ':') {
                    continue;
                }
            }

            if ($stringTypeToken[0] === '$') {
                continue;
            }

            if (isset($typeAliases[$stringTypeToken])) {
                $replacementTokens = $typeAliases[$stringTypeToken];

                array_unshift($replacementTokens, '(');
                array_push($replacementTokens, ')');

                $diff = count($replacementTokens) - 1;

                array_splice($typeTokens, $i, 1, $replacementTokens);

                $i += $diff;
                $l += $diff;
            } else {
                $typeTokens[$i] = self::getFQCLNFromString(
                    $stringTypeToken,
                    $aliases
                );
            }
        }

        return $typeTokens;
    }

    /**
     * @param  string                   $class
     * @param  Aliases                  $aliases
     *
     * @return string
     */
    public static function getFQCLNFromString($class, Aliases $aliases)
    {
        if ($class === '') {
            throw new \InvalidArgumentException('$class cannot be empty');
        }

        if ($class[0] === '\\') {
            return substr($class, 1);
        }

        $importedNamespaces = $aliases->uses;

        if (strpos($class, '\\') !== false) {
            $classParts = explode('\\', $class);
            $firstNamespace = array_shift($classParts);

            if (isset($importedNamespaces[strtolower($firstNamespace)])) {
                return $importedNamespaces[strtolower($firstNamespace)] . '\\' . implode('\\', $classParts);
            }
        } elseif (isset($importedNamespaces[strtolower($class)])) {
            return $importedNamespaces[strtolower($class)];
        }

        $namespace = $aliases->namespace;

        return ($namespace ? $namespace . '\\' : '') . $class;
    }

    /**
     * @param bool $fromCalculation
     * @param int|null $value
     *
     * @return Type\Union
     */
    public static function getInt($fromCalculation = false, $value = null)
    {
        if ($value !== null) {
            $union = new Union([new TLiteralInt($value)]);
        } else {
            $union = new Union([new TInt()]);
        }

        $union->fromCalculation = $fromCalculation;

        return $union;
    }

    /**
     * @return Type\Union
     */
    public static function getNumeric()
    {
        $type = new TNumeric;

        return new Union([$type]);
    }

    /**
     * @param string|null $value
     *
     * @return Type\Union
     */
    public static function getString($value = null)
    {
        if ($value !== null) {
            $type = new TLiteralString($value);
        } else {
            $type = new TString();
        }

        return new Union([$type]);
    }

    /**
     * @return Type\Union
     */
    public static function getSingleLetter()
    {
        $type = new TSingleLetter;

        return new Union([$type]);
    }

    /**
     * @param string $classType
     *
     * @return Type\Union
     */
    public static function getClassString($classType = null)
    {
        if (!$classType) {
            return new Union([new TClassString()]);
        }

        $type = new TLiteralClassString($classType);

        return new Union([$type]);
    }

    /**
     * @return Type\Union
     */
    public static function getNull()
    {
        $type = new TNull;

        return new Union([$type]);
    }

    /**
     * @param bool $fromIsset
     *
     * @return Type\Union
     */
    public static function getMixed($fromIsset = false)
    {
        $type = new TMixed($fromIsset);

        return new Union([$type]);
    }

    /**
     * @return Type\Union
     */
    public static function getEmpty()
    {
        $type = new TEmpty();

        return new Union([$type]);
    }

    /**
     * @return Type\Union
     */
    public static function getBool()
    {
        $type = new TBool;

        return new Union([$type]);
    }

    /**
     * @param float|null $value
     *
     * @return Type\Union
     */
    public static function getFloat($value = null)
    {
        if ($value !== null) {
            $type = new TLiteralFloat($value);
        } else {
            $type = new TFloat();
        }

        return new Union([$type]);
    }

    /**
     * @return Type\Union
     */
    public static function getObject()
    {
        $type = new TObject;

        return new Union([$type]);
    }

    /**
     * @return Type\Union
     */
    public static function getClosure()
    {
        $type = new TNamedObject('Closure');

        return new Union([$type]);
    }

    /**
     * @return Type\Union
     */
    public static function getArray()
    {
        $type = new TArray(
            [
                new Type\Union([new TMixed]),
                new Type\Union([new TMixed]),
            ]
        );

        return new Union([$type]);
    }

    /**
     * @return Type\Union
     */
    public static function getEmptyArray()
    {
        $arrayType = new TArray(
            [
                new Type\Union([new TEmpty]),
                new Type\Union([new TEmpty]),
            ]
        );

        $arrayType->count = 0;

        return new Type\Union([
            $arrayType,
        ]);
    }

    /**
     * @return Type\Union
     */
    public static function getVoid()
    {
        $type = new TVoid;

        return new Union([$type]);
    }

    /**
     * @return Type\Union
     */
    public static function getFalse()
    {
        $type = new TFalse;

        return new Union([$type]);
    }

    /**
     * @return Type\Union
     */
    public static function getTrue()
    {
        $type = new TTrue;

        return new Union([$type]);
    }

    /**
     * @return Type\Union
     */
    public static function getResource()
    {
        return new Union([new TResource]);
    }

    /**
     * Combines two union types into one
     *
     * @param  Union  $type1
     * @param  Union  $type2
     *
     * @return Union
     */
    public static function combineUnionTypes(Union $type1, Union $type2)
    {
        if ($type1->isVanillaMixed() || $type2->isVanillaMixed()) {
            $combinedType = Type::getMixed();
        } else {
             $bothFailedReconciliation = false;

            if ($type1->failedReconciliation) {
                if ($type2->failedReconciliation) {
                    $bothFailedReconciliation = true;
                } else {
                    return $type2;
                }
            } elseif ($type2->failedReconciliation) {
                return $type1;
            }

            $combinedType = TypeCombination::combineTypes(
                array_merge(
                    array_values($type1->getTypes()),
                    array_values($type2->getTypes())
                )
            );

            if (!$type1->initialized || !$type2->initialized) {
                $combinedType->initialized = false;
            }

            if ($type1->possiblyUndefinedFromTry || $type2->possiblyUndefinedFromTry) {
                $combinedType->possiblyUndefinedFromTry = true;
            }

            if ($type1->fromDocblock || $type2->fromDocblock) {
                $combinedType->fromDocblock = true;
            }

            if ($type1->fromCalculation || $type2->fromCalculation) {
                $combinedType->fromCalculation = true;
            }

            if ($type1->ignoreNullableIssues || $type2->ignoreNullableIssues) {
                $combinedType->ignoreNullableIssues = true;
            }

            if ($type1->ignoreFalsableIssues || $type2->ignoreFalsableIssues) {
                $combinedType->ignoreFalsableIssues = true;
            }

            if ($bothFailedReconciliation) {
                $combinedType->failedReconciliation = true;
            }
        }

        if ($type1->possiblyUndefined || $type2->possiblyUndefined) {
            $combinedType->possiblyUndefined = true;
        }

        return $combinedType;
    }
}
