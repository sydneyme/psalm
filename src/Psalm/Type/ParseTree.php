<?php
namespace Psalm\Type;

use Psalm\Exception\TypeParseTreeException;

class ParseTree
{
    /**
     * @var array<int, ParseTree>
     */
    public $children = [];

    /**
     * @var null|ParseTree
     */
    public $parent;

    /**
     * @var bool
     */
    public $possiblyUndefined = false;

    /**
     * @param ParseTree|null $parent
     */
    public function __construct(ParseTree $parent = null)
    {
        $this->parent = $parent;
    }

    /**
     * Create a parse tree from a tokenised type
     *
     * @param  array<int, string>  $typeTokens
     *
     * @return self
     */
    public static function createFromTokens(array $typeTokens)
    {
        // We construct a parse tree corresponding to the type
        $parseTree = new ParseTree\Root();

        $currentLeaf = $parseTree;

        for ($i = 0, $c = count($typeTokens); $i < $c; ++$i) {
            $lastToken = $i > 0 ? $typeTokens[$i - 1] : null;
            $typeToken = $typeTokens[$i];
            $nextToken = $i + 1 < $c ? $typeTokens[$i + 1] : null;

            switch ($typeToken) {
                case '<':
                case '{':
                case ']':
                    throw new TypeParseTreeException('Unexpected token ' . $typeToken);

                case '[':
                    if ($currentLeaf instanceof ParseTree\Root) {
                        throw new TypeParseTreeException('Unexpected token ' . $typeToken);
                    }

                    if ($nextToken !== ']') {
                        throw new TypeParseTreeException('Unexpected token ' . $typeToken);
                    }

                    $currentParent = $currentLeaf->parent;

                    $newParentLeaf = new ParseTree\GenericTree('array', $currentParent);
                    $currentLeaf->parent = $newParentLeaf;
                    $newParentLeaf->children = [$currentLeaf];

                    if ($currentParent) {
                        array_pop($currentParent->children);
                        $currentParent->children[] = $newParentLeaf;
                    } else {
                        $parseTree = $newParentLeaf;
                    }

                    $currentLeaf = $newParentLeaf;
                    ++$i;
                    break;

                case '(':
                    if ($currentLeaf instanceof ParseTree\Value) {
                        throw new TypeParseTreeException('Unrecognised token (');
                    }

                    $newParent = !$currentLeaf instanceof ParseTree\Root ? $currentLeaf : null;

                    $newLeaf = new ParseTree\EncapsulationTree(
                        $newParent
                    );

                    if ($currentLeaf instanceof ParseTree\Root) {
                        $currentLeaf = $parseTree = $newLeaf;
                        break;
                    }

                    if ($newLeaf->parent) {
                        $newLeaf->parent->children[] = $newLeaf;
                    }

                    $currentLeaf = $newLeaf;
                    break;

                case ')':
                    if ($lastToken === '(' && $currentLeaf instanceof ParseTree\CallableTree) {
                        break;
                    }

                    do {
                        if ($currentLeaf->parent === null
                            || $currentLeaf->parent instanceof ParseTree\CallableWithReturnTypeTree
                            || $currentLeaf->parent instanceof ParseTree\MethodWithReturnTypeTree
                        ) {
                            break;
                        }

                        $currentLeaf = $currentLeaf->parent;
                    } while (!$currentLeaf instanceof ParseTree\EncapsulationTree
                        && !$currentLeaf instanceof ParseTree\CallableTree
                        && !$currentLeaf instanceof ParseTree\MethodTree);

                    break;

                case '>':
                    do {
                        if ($currentLeaf->parent === null) {
                            throw new TypeParseTreeException('Cannot parse generic type');
                        }

                        $currentLeaf = $currentLeaf->parent;
                    } while (!$currentLeaf instanceof ParseTree\GenericTree);

                    break;

                case '}':
                    do {
                        if ($currentLeaf->parent === null) {
                            throw new TypeParseTreeException('Cannot parse array type');
                        }

                        $currentLeaf = $currentLeaf->parent;
                    } while (!$currentLeaf instanceof ParseTree\ObjectLikeTree);

                    break;

                case ',':
                    if ($currentLeaf instanceof ParseTree\Root) {
                        throw new TypeParseTreeException('Unexpected token ' . $typeToken);
                    }

                    if (!$currentLeaf->parent) {
                        throw new TypeParseTreeException('Cannot parse comma without a parent node');
                    }

                    $currentParent = $currentLeaf->parent;

                    $contextNode = $currentLeaf;

                    if ($contextNode instanceof ParseTree\GenericTree
                        || $contextNode instanceof ParseTree\ObjectLikeTree
                        || $contextNode instanceof ParseTree\CallableTree
                        || $contextNode instanceof ParseTree\MethodTree
                    ) {
                        $contextNode = $contextNode->parent;
                    }

                    while ($contextNode
                        && !$contextNode instanceof ParseTree\GenericTree
                        && !$contextNode instanceof ParseTree\ObjectLikeTree
                        && !$contextNode instanceof ParseTree\CallableTree
                        && !$contextNode instanceof ParseTree\MethodTree
                    ) {
                        $contextNode = $contextNode->parent;
                    }

                    if (!$contextNode) {
                        throw new TypeParseTreeException('Cannot parse comma in non-generic/array type');
                    }

                    $currentLeaf = $contextNode;

                    break;

                case '...':
                case '=':
                    if ($lastToken === '...' || $lastToken === '=') {
                        throw new TypeParseTreeException('Cannot have duplicate tokens');
                    }

                    $currentParent = $currentLeaf->parent;

                    if ($currentLeaf instanceof ParseTree\MethodTree && $typeToken === '...') {
                        self::createMethodParam($currentLeaf, $currentLeaf, $typeTokens, $typeToken, $i);
                        break;
                    }

                    while ($currentParent
                        && !$currentParent instanceof ParseTree\CallableTree
                        && !$currentParent instanceof ParseTree\CallableParamTree
                    ) {
                        $currentLeaf = $currentParent;
                        $currentParent = $currentParent->parent;
                    }

                    if (!$currentParent || !$currentLeaf) {
                        throw new TypeParseTreeException('Unexpected token ' . $typeToken);
                    }

                    if ($currentParent instanceof ParseTree\CallableParamTree) {
                        throw new TypeParseTreeException('Cannot have variadic param with a default');
                    }

                    $newLeaf = new ParseTree\CallableParamTree($currentParent);
                    $newLeaf->hasDefault = $typeToken === '=';
                    $newLeaf->variadic = $typeToken === '...';
                    $newLeaf->children = [$currentLeaf];

                    $currentLeaf->parent = $newLeaf;

                    array_pop($currentParent->children);
                    $currentParent->children[] = $newLeaf;

                    $currentLeaf = $newLeaf;

                    break;

                case ':':
                    if ($currentLeaf instanceof ParseTree\Root) {
                        throw new TypeParseTreeException('Unexpected token ' . $typeToken);
                    }

                    $currentParent = $currentLeaf->parent;

                    if ($currentLeaf instanceof ParseTree\CallableTree) {
                        $newParentLeaf = new ParseTree\CallableWithReturnTypeTree($currentParent);
                        $currentLeaf->parent = $newParentLeaf;
                        $newParentLeaf->children = [$currentLeaf];

                        if ($currentParent) {
                            array_pop($currentParent->children);
                            $currentParent->children[] = $newParentLeaf;
                        } else {
                            $parseTree = $newParentLeaf;
                        }

                        $currentLeaf = $newParentLeaf;
                        break;
                    }

                    if ($currentLeaf instanceof ParseTree\MethodTree) {
                        $newParentLeaf = new ParseTree\MethodWithReturnTypeTree($currentParent);
                        $currentLeaf->parent = $newParentLeaf;
                        $newParentLeaf->children = [$currentLeaf];

                        if ($currentParent) {
                            array_pop($currentParent->children);
                            $currentParent->children[] = $newParentLeaf;
                        } else {
                            $parseTree = $newParentLeaf;
                        }

                        $currentLeaf = $newParentLeaf;
                        break;
                    }

                    if ($currentParent && $currentParent instanceof ParseTree\ObjectLikePropertyTree) {
                        break;
                    }

                    if (!$currentParent) {
                        throw new TypeParseTreeException('Cannot process colon without parent');
                    }

                    if (!$currentLeaf instanceof ParseTree\Value) {
                        throw new TypeParseTreeException('Unexpected LHS of property');
                    }

                    if (!$currentParent instanceof ParseTree\ObjectLikeTree) {
                        throw new TypeParseTreeException('Saw : outside of object-like array');
                    }

                    $newParentLeaf = new ParseTree\ObjectLikePropertyTree($currentLeaf->value, $currentParent);
                    $newParentLeaf->possiblyUndefined = $lastToken === '?';
                    $currentLeaf->parent = $newParentLeaf;

                    array_pop($currentParent->children);
                    $currentParent->children[] = $newParentLeaf;

                    $currentLeaf = $newParentLeaf;

                    break;

                case ' ':
                    if ($currentLeaf instanceof ParseTree\Root) {
                        throw new TypeParseTreeException('Unexpected space');
                    }

                    $currentParent = $currentLeaf->parent;

                    while ($currentParent && !$currentParent instanceof ParseTree\MethodTree) {
                        $currentLeaf = $currentParent;
                        $currentParent = $currentParent->parent;
                    }

                    if (!$currentParent instanceof ParseTree\MethodTree || !$nextToken) {
                        throw new TypeParseTreeException('Unexpected space');
                    }

                    ++$i;

                    self::createMethodParam($currentLeaf, $currentParent, $typeTokens, $nextToken, $i);

                    break;

                case '?':
                    if ($nextToken !== ':') {
                        $newParent = !$currentLeaf instanceof ParseTree\Root ? $currentLeaf : null;

                        $newLeaf = new ParseTree\NullableTree(
                            $newParent
                        );

                        if ($currentLeaf instanceof ParseTree\Root) {
                            $currentLeaf = $parseTree = $newLeaf;
                            break;
                        }

                        if ($newLeaf->parent) {
                            $newLeaf->parent->children[] = $newLeaf;
                        }

                        $currentLeaf = $newLeaf;
                    }

                    break;

                case '|':
                    if ($currentLeaf instanceof ParseTree\Root) {
                        throw new TypeParseTreeException('Unexpected token ' . $typeToken);
                    }

                    $currentParent = $currentLeaf->parent;

                    if ($currentParent instanceof ParseTree\CallableWithReturnTypeTree) {
                        $currentLeaf = $currentParent;
                        $currentParent = $currentParent->parent;
                    }

                    if ($currentParent instanceof ParseTree\NullableTree) {
                        $currentLeaf = $currentParent;
                        $currentParent = $currentParent->parent;
                    }

                    if ($currentLeaf instanceof ParseTree\UnionTree) {
                        throw new TypeParseTreeException('Unexpected token ' . $typeToken);
                    }

                    if ($currentParent && $currentParent instanceof ParseTree\UnionTree) {
                        $currentLeaf = $currentParent;
                        break;
                    }

                    if ($currentParent && $currentParent instanceof ParseTree\IntersectionTree) {
                        $currentLeaf = $currentParent;
                        $currentParent = $currentLeaf->parent;
                    }

                    $newParentLeaf = new ParseTree\UnionTree($currentParent);
                    $newParentLeaf->children = [$currentLeaf];
                    $currentLeaf->parent = $newParentLeaf;

                    if ($currentParent) {
                        array_pop($currentParent->children);
                        $currentParent->children[] = $newParentLeaf;
                    } else {
                        $parseTree = $newParentLeaf;
                    }

                    $currentLeaf = $newParentLeaf;

                    break;

                case '&':
                    if ($currentLeaf instanceof ParseTree\Root) {
                        throw new TypeParseTreeException(
                            'Unexpected &'
                        );
                    }

                    $currentParent = $currentLeaf->parent;

                    if ($currentLeaf instanceof ParseTree\MethodTree) {
                        self::createMethodParam($currentLeaf, $currentLeaf, $typeTokens, $typeToken, $i);
                        break;
                    }

                    if ($currentParent && $currentParent instanceof ParseTree\IntersectionTree) {
                        break;
                    }

                    $newParentLeaf = new ParseTree\IntersectionTree($currentParent);
                    $newParentLeaf->children = [$currentLeaf];
                    $currentLeaf->parent = $newParentLeaf;

                    if ($currentParent) {
                        array_pop($currentParent->children);
                        $currentParent->children[] = $newParentLeaf;
                    } else {
                        $parseTree = $newParentLeaf;
                    }

                    $currentLeaf = $newParentLeaf;

                    break;

                default:
                    $newParent = !$currentLeaf instanceof ParseTree\Root ? $currentLeaf : null;

                    if ($currentLeaf instanceof ParseTree\MethodTree && $typeToken[0] === '$') {
                        self::createMethodParam($currentLeaf, $currentLeaf, $typeTokens, $typeToken, $i);
                        break;
                    }

                    switch ($nextToken) {
                        case '<':
                            $newLeaf = new ParseTree\GenericTree(
                                $typeToken,
                                $newParent
                            );
                            ++$i;
                            break;

                        case '{':
                            $newLeaf = new ParseTree\ObjectLikeTree(
                                $typeToken,
                                $newParent
                            );
                            ++$i;
                            break;

                        case '(':
                            if (in_array(strtolower($typeToken), ['closure', 'callable', '\closure'])) {
                                $newLeaf = new ParseTree\CallableTree(
                                    $typeToken,
                                    $newParent
                                );
                            } elseif ($typeToken !== 'array'
                                && $typeToken[0] !== '\\'
                                && $currentLeaf instanceof ParseTree\Root
                            ) {
                                $newLeaf = new ParseTree\MethodTree(
                                    $typeToken,
                                    $newParent
                                );
                            } else {
                                throw new TypeParseTreeException(
                                    'Bracket must be preceded by “Closure”, “callable” or a valid @method name'
                                );
                            }

                            ++$i;
                            break;

                        case '::':
                            $nexterToken = $i + 2 < $c ? $typeTokens[$i + 2] : null;

                            if (!$nexterToken
                                || (!preg_match('/^[A-Z_][A-Z_0-9]*$/', $nexterToken)
                                    && strtolower($nexterToken) !== 'class')
                            ) {
                                throw new TypeParseTreeException(
                                    'Invalid class constant ' . $nexterToken
                                );
                            }

                            $newLeaf = new ParseTree\Value(
                                $typeToken . '::' . $nexterToken,
                                $newParent
                            );

                            $i += 2;

                            break;

                        default:
                            if ($typeToken === '$this') {
                                $typeToken = 'static';
                            }

                            $newLeaf = new ParseTree\Value(
                                $typeToken,
                                $newParent
                            );
                            break;
                    }

                    if ($currentLeaf instanceof ParseTree\Root) {
                        $currentLeaf = $parseTree = $newLeaf;
                        break;
                    }

                    if ($newLeaf->parent) {
                        $newLeaf->parent->children[] = $newLeaf;
                    }

                    $currentLeaf = $newLeaf;
                    break;
            }
        }

        return $parseTree;
    }

    /**
     * @param  ParseTree          &$currentLeaf
     * @param  ParseTree          $currentParent
     * @param  array<int, string> $typeTokens
     * @param  string             $currentToken
     * @param  int                &$i
     *
     * @return void
     */
    private static function createMethodParam(
        ParseTree &$currentLeaf,
        ParseTree $currentParent,
        array $typeTokens,
        $currentToken,
        &$i
    ) {
        $byref = false;
        $variadic = false;
        $hasDefault = false;
        $default = '';

        $c = count($typeTokens);

        if ($currentToken === '&') {
            throw new TypeParseTreeException('Magic args cannot be passed by reference');
        }

        if ($currentToken === '...') {
            $variadic = true;

            ++$i;
            $currentToken = $i < $c ? $typeTokens[$i] : null;
        }

        if (!$currentToken || $currentToken[0] !== '$') {
            throw new TypeParseTreeException('Unexpected token after space ' . $currentToken);
        }

        $newParentLeaf = new ParseTree\MethodParamTree(
            $currentToken,
            $byref,
            $variadic,
            $currentParent
        );

        for ($j = $i + 1; $j < $c; ++$j) {
            $aheadTypeToken = $typeTokens[$j];

            if ($aheadTypeToken === ','
                || ($aheadTypeToken === ')' && $typeTokens[$j - 1] !== '(')
            ) {
                $i = $j - 1;
                break;
            }

            if ($hasDefault) {
                $default .= $aheadTypeToken;
            }

            if ($aheadTypeToken === '=') {
                $hasDefault = true;
                continue;
            }

            if ($j === $c - 1) {
                throw new TypeParseTreeException('Unterminated method');
            }
        }

        $newParentLeaf->default = $default;

        if ($currentLeaf !== $currentParent) {
            $newParentLeaf->children = [$currentLeaf];
            $currentLeaf->parent = $newParentLeaf;
            array_pop($currentParent->children);
        }

        $currentParent->children[] = $newParentLeaf;

        $currentLeaf = $newParentLeaf;
    }
}
