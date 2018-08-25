<?php
namespace Psalm\Checker;

use Psalm\Aliases;
use Psalm\Exception\DocblockParseException;
use Psalm\Exception\IncorrectDocblockException;
use Psalm\Exception\TypeParseTreeException;
use Psalm\FileSource;
use Psalm\Scanner\ClassLikeDocblockComment;
use Psalm\Scanner\FunctionDocblockComment;
use Psalm\Scanner\VarDocblockComment;
use Psalm\Type;

class CommentChecker
{
    const TYPE_REGEX = '(\??\\\?[\(\)A-Za-z0-9_&\<\.=,\>\[\]\-\{\}:|?\\\\]*|\$[a-zA-Z_0-9_]+)';

    /**
     * @param  string           $comment
     * @param  Aliases          $aliases
     * @param  array<string, string>|null   $templateTypeNames
     * @param  int|null         $varLineNumber
     * @param  int|null         $cameFromLineNumber what line number in $source that $comment came from
     * @param  array<string, array<int, string>> $typeAliases
     *
     * @throws DocblockParseException if there was a problem parsing the docblock
     *
     * @return VarDocblockComment[]
     * @psalm-suppress MixedArrayAccess
     */
    public static function getTypeFromComment(
        $comment,
        FileSource $source,
        Aliases $aliases,
        array $templateTypeNames = null,
        $varLineNumber = null,
        $cameFromLineNumber = null,
        array $typeAliases = null
    ) {
        $varId = null;

        $varTypeTokens = null;
        $originalType = null;

        $varComments = [];
        $comments = self::parseDocComment($comment, $varLineNumber);

        if (!isset($comments['specials']['var']) && !isset($comments['specials']['psalm-var'])) {
            return [];
        }

        if ($comments) {
            $allVars = (isset($comments['specials']['var']) ? $comments['specials']['var'] : [])
                + (isset($comments['specials']['psalm-var']) ? $comments['specials']['psalm-var'] : []);

            /** @var int $lineNumber */
            foreach ($allVars as $lineNumber => $varLine) {
                $varLine = trim($varLine);

                if (!$varLine) {
                    continue;
                }

                try {
                    $lineParts = self::splitDocLine($varLine);
                } catch (DocblockParseException $e) {
                    throw $e;
                }

                if ($lineParts && $lineParts[0]) {
                    if ($lineParts[0][0] === '$' && $lineParts[0] !== '$this') {
                        throw new IncorrectDocblockException('Misplaced variable');
                    }

                    try {
                        $varTypeTokens = Type::fixUpLocalType(
                            $lineParts[0],
                            $aliases,
                            $templateTypeNames,
                            $typeAliases
                        );
                    } catch (TypeParseTreeException $e) {
                        throw new DocblockParseException($lineParts[0] . ' is not a valid type');
                    }

                    $originalType = $lineParts[0];

                    $varLineNumber = $lineNumber;

                    if (count($lineParts) > 1 && $lineParts[1][0] === '$') {
                        $varId = $lineParts[1];
                    }
                }

                if (!$varTypeTokens || !$originalType) {
                    continue;
                }

                try {
                    $definedType = Type::parseTokens($varTypeTokens, false, $templateTypeNames ?: []);
                } catch (TypeParseTreeException $e) {
                    if (is_int($cameFromLineNumber)) {
                        throw new DocblockParseException(
                            implode('', $varTypeTokens) .
                            ' is not a valid type' .
                            ' (from ' .
                            $source->getFilePath() .
                            ':' .
                            $cameFromLineNumber .
                            ')'
                        );
                    }

                    throw new DocblockParseException(implode('', $varTypeTokens) . ' is not a valid type');
                }

                $definedType->setFromDocblock();

                $varComment = new VarDocblockComment();
                $varComment->type = $definedType;
                $varComment->originalType = $originalType;
                $varComment->varId = $varId;
                $varComment->lineNumber = $varLineNumber;
                $varComment->deprecated = isset($comments['specials']['deprecated']);

                $varComments[] = $varComment;
            }
        }

        return $varComments;
    }

    /**
     * @param  string           $comment
     * @param  Aliases          $aliases
     * @param  array<string, array<int, string>> $typeAliases
     *
     * @throws DocblockParseException if there was a problem parsing the docblock
     *
     * @return array<string, array<int, string>>
     */
    public static function getTypeAliasesFromComment(
        $comment,
        Aliases $aliases,
        array $typeAliases = null
    ) {
        $comments = self::parseDocComment($comment);

        if (!isset($comments['specials']['psalm-type'])) {
            return [];
        }

        return self::getTypeAliasesFromCommentLines(
            $comments['specials']['psalm-type'],
            $aliases,
            $typeAliases
        );
    }

    /**
     * @param  array<string>    $typeAliasCommentLines
     * @param  Aliases          $aliases
     * @param  array<string, array<int, string>> $typeAliases
     *
     * @throws DocblockParseException if there was a problem parsing the docblock
     *
     * @return array<string, array<int, string>>
     */
    private static function getTypeAliasesFromCommentLines(
        array $typeAliasCommentLines,
        Aliases $aliases,
        array $typeAliases = null
    ) {
        $typeAliasTokens = [];

        foreach ($typeAliasCommentLines as $varLine) {
            $varLine = trim($varLine);

            if (!$varLine) {
                continue;
            }

            $varLine = preg_replace('/[ \t]+/', ' ', $varLine);

            $varLineParts = preg_split('/( |=)/', $varLine, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

            $typeAlias = array_shift($varLineParts);

            if (!isset($varLineParts[0])) {
                continue;
            }

            if ($varLineParts[0] === ' ') {
                array_shift($varLineParts);
            }

            if ($varLineParts[0] === '=') {
                array_shift($varLineParts);
            }

            if (!isset($varLineParts[0])) {
                continue;
            }

            if ($varLineParts[0] === ' ') {
                array_shift($varLineParts);
            }

            $typeString = implode('', $varLineParts);

            try {
                $typeTokens = Type::fixUpLocalType(
                    $typeString,
                    $aliases,
                    null,
                    $typeAliases
                );
            } catch (TypeParseTreeException $e) {
                throw new DocblockParseException($typeString . ' is not a valid type');
            }

            $typeAliasTokens[$typeAlias] = $typeTokens;
        }

        return $typeAliasTokens;
    }

    /**
     * @param  string  $comment
     * @param  int     $lineNumber
     *
     * @throws DocblockParseException if there was a problem parsing the docblock
     *
     * @return FunctionDocblockComment
     * @psalm-suppress MixedArrayAccess
     */
    public static function extractFunctionDocblockInfo($comment, $lineNumber)
    {
        $comments = self::parseDocComment($comment, $lineNumber);

        $info = new FunctionDocblockComment();

        if (isset($comments['specials']['return']) || isset($comments['specials']['psalm-return'])) {
            /** @var array<int, string> */
            $returnSpecials = isset($comments['specials']['psalm-return'])
                ? $comments['specials']['psalm-return']
                : $comments['specials']['return'];

            $returnBlock = trim((string)reset($returnSpecials));

            if (!$returnBlock) {
                throw new DocblockParseException('Missing @return type');
            }

            try {
                $lineParts = self::splitDocLine($returnBlock);
            } catch (DocblockParseException $e) {
                throw $e;
            }

            if (!preg_match('/\[[^\]]+\]/', $lineParts[0])
                && $lineParts[0][0] !== '{'
            ) {
                if ($lineParts[0][0] === '$' && !preg_match('/^\$this(\||$)/', $lineParts[0])) {
                    throw new IncorrectDocblockException('Misplaced variable');
                }

                $info->returnType = $lineParts[0];
                $lineNumber = array_keys($returnSpecials)[0];

                if ($lineNumber) {
                    $info->returnTypeLineNumber = $lineNumber;
                }
            } else {
                throw new DocblockParseException('Badly-formatted @return type');
            }
        }

        if (isset($comments['specials']['param']) || isset($comments['specials']['psalm-param'])) {
            $allParams = (isset($comments['specials']['param']) ? $comments['specials']['param'] : [])
                + (isset($comments['specials']['psalm-param']) ? $comments['specials']['psalm-param'] : []);

            /** @var string $param */
            foreach ($allParams as $lineNumber => $param) {
                try {
                    $lineParts = self::splitDocLine($param);
                } catch (DocblockParseException $e) {
                    throw $e;
                }

                if (count($lineParts) === 1 && isset($lineParts[0][0]) && $lineParts[0][0] === '$') {
                    continue;
                }

                if (count($lineParts) > 1) {
                    if (!preg_match('/\[[^\]]+\]/', $lineParts[0])
                        && preg_match('/^(\.\.\.)?&?\$[A-Za-z0-9_]+,?$/', $lineParts[1])
                        && $lineParts[0][0] !== '{'
                    ) {
                        if ($lineParts[1][0] === '&') {
                            $lineParts[1] = substr($lineParts[1], 1);
                        }

                        if ($lineParts[0][0] === '$' && !preg_match('/^\$this(\||$)/', $lineParts[0])) {
                            throw new IncorrectDocblockException('Misplaced variable');
                        }

                        $lineParts[1] = preg_replace('/,$/', '', $lineParts[1]);

                        $info->params[] = [
                            'name' => $lineParts[1],
                            'type' => $lineParts[0],
                            'line_number' => (int)$lineNumber,
                        ];
                    }
                } else {
                    throw new DocblockParseException('Badly-formatted @param');
                }
            }
        }

        if (isset($comments['specials']['global'])) {
            foreach ($comments['specials']['global'] as $lineNumber => $global) {
                try {
                    $lineParts = self::splitDocLine($global);
                } catch (DocblockParseException $e) {
                    throw $e;
                }

                if (count($lineParts) === 1 && isset($lineParts[0][0]) && $lineParts[0][0] === '$') {
                    continue;
                }

                if (count($lineParts) > 1) {
                    if (!preg_match('/\[[^\]]+\]/', $lineParts[0])
                        && preg_match('/^(\.\.\.)?&?\$[A-Za-z0-9_]+,?$/', $lineParts[1])
                        && $lineParts[0][0] !== '{'
                    ) {
                        if ($lineParts[1][0] === '&') {
                            $lineParts[1] = substr($lineParts[1], 1);
                        }

                        if ($lineParts[0][0] === '$' && !preg_match('/^\$this(\||$)/', $lineParts[0])) {
                            throw new IncorrectDocblockException('Misplaced variable');
                        }

                        $lineParts[1] = preg_replace('/,$/', '', $lineParts[1]);

                        $info->globals[] = [
                            'name' => $lineParts[1],
                            'type' => $lineParts[0],
                            'line_number' => (int)$lineNumber,
                        ];
                    }
                } else {
                    throw new DocblockParseException('Badly-formatted @param');
                }
            }
        }

        if (isset($comments['specials']['deprecated'])) {
            $info->deprecated = true;
        }

        if (isset($comments['specials']['psalm-suppress'])) {
            foreach ($comments['specials']['psalm-suppress'] as $suppressEntry) {
                $info->suppress[] = preg_split('/[\s]+/', $suppressEntry)[0];
            }
        }

        if (isset($comments['specials']['throws'])) {
            foreach ($comments['specials']['throws'] as $throwsEntry) {
                $info->throws[] = preg_split('/[\s]+/', $throwsEntry)[0];
            }
        }

        if (isset($comments['specials']['template']) || isset($comments['specials']['psalm-template'])) {
            $allTemplates = (isset($comments['specials']['template']) ? $comments['specials']['template'] : [])
                + (isset($comments['specials']['psalm-template']) ? $comments['specials']['psalm-template'] : []);

            foreach ($allTemplates as $templateLine) {
                $templateType = preg_split('/[\s]+/', $templateLine);

                if (count($templateType) > 2 && in_array(strtolower($templateType[1]), ['as', 'super'], true)) {
                    $info->templateTypeNames[] = [
                        $templateType[0],
                        strtolower($templateType[1]), $templateType[2]
                    ];
                } else {
                    $info->templateTypeNames[] = [$templateType[0]];
                }
            }
        }

        if (isset($comments['specials']['template-typeof'])) {
            foreach ($comments['specials']['template-typeof'] as $templateTypeof) {
                $typeofParts = preg_split('/[\s]+/', $templateTypeof);

                if (count($typeofParts) < 2 || $typeofParts[1][0] !== '$') {
                    throw new IncorrectDocblockException('Misplaced variable');
                }

                $info->templateTypeofs[] = [
                    'template_type' => $typeofParts[0],
                    'param_name' => substr($typeofParts[1], 1),
                ];
            }
        }

        if (isset($comments['specials']['psalm-assert'])) {
            foreach ($comments['specials']['psalm-assert'] as $assertion) {
                $assertionParts = preg_split('/[\s]+/', $assertion);

                if (count($assertionParts) < 2 || $assertionParts[1][0] !== '$') {
                    throw new IncorrectDocblockException('Misplaced variable');
                }

                $info->assertions[] = [
                    'type' => $assertionParts[0],
                    'param_name' => substr($assertionParts[1], 1),
                ];
            }
        }

        if (isset($comments['specials']['psalm-assert-if-true'])) {
            foreach ($comments['specials']['psalm-assert-if-true'] as $assertion) {
                $assertionParts = preg_split('/[\s]+/', $assertion);

                if (count($assertionParts) < 2 || $assertionParts[1][0] !== '$') {
                    throw new IncorrectDocblockException('Misplaced variable');
                }

                $info->ifTrueAssertions[] = [
                    'type' => $assertionParts[0],
                    'param_name' => substr($assertionParts[1], 1),
                ];
            }
        }

        if (isset($comments['specials']['psalm-assert-if-false'])) {
            foreach ($comments['specials']['psalm-assert-if-false'] as $assertion) {
                $assertionParts = preg_split('/[\s]+/', $assertion);

                if (count($assertionParts) < 2 || $assertionParts[1][0] !== '$') {
                    throw new IncorrectDocblockException('Misplaced variable');
                }

                $info->ifFalseAssertions[] = [
                    'type' => $assertionParts[0],
                    'param_name' => substr($assertionParts[1], 1),
                ];
            }
        }

        $info->variadic = isset($comments['specials']['psalm-variadic']);
        $info->ignoreNullableReturn = isset($comments['specials']['psalm-ignore-nullable-return']);
        $info->ignoreFalsableReturn = isset($comments['specials']['psalm-ignore-falsable-return']);

        return $info;
    }

    /**
     * @param  string  $comment
     * @param  int     $lineNumber
     *
     * @throws DocblockParseException if there was a problem parsing the docblock
     *
     * @return ClassLikeDocblockComment
     * @psalm-suppress MixedArrayAccess
     */
    public static function extractClassLikeDocblockInfo($comment, $lineNumber)
    {
        $comments = self::parseDocComment($comment, $lineNumber);

        $info = new ClassLikeDocblockComment();

        if (isset($comments['specials']['template'])) {
            foreach ($comments['specials']['template'] as $templateLine) {
                $templateType = preg_split('/[\s]+/', $templateLine);

                if (count($templateType) > 2 && in_array(strtolower($templateType[1]), ['as', 'super'], true)) {
                    $info->templateTypeNames[] = [
                        $templateType[0],
                        strtolower($templateType[1]), $templateType[2]
                    ];
                } else {
                    $info->templateTypeNames[] = [$templateType[0]];
                }
            }
        }

        if (isset($comments['specials']['template-extends'])) {
            foreach ($comments['specials']['template-extends'] as $templateLine) {
                $info->templateParents[] = $templateLine;
            }
        }

        if (isset($comments['specials']['deprecated'])) {
            $info->deprecated = true;
        }

        if (isset($comments['specials']['psalm-seal-properties'])) {
            $info->sealedProperties = true;
        }

        if (isset($comments['specials']['psalm-seal-methods'])) {
            $info->sealedMethods = true;
        }

        if (isset($comments['specials']['psalm-suppress'])) {
            foreach ($comments['specials']['psalm-suppress'] as $suppressEntry) {
                $info->suppressedIssues[] = preg_split('/[\s]+/', $suppressEntry)[0];
            }
        }

        if (isset($comments['specials']['method'])) {
            foreach ($comments['specials']['method'] as $methodEntry) {
                $methodEntry = preg_replace('/[ \t]+/', ' ', trim($methodEntry));

                $docblockLines = [];

                if (!preg_match('/^([a-z_A-Z][a-z_0-9A-Z]+) *\(/', $methodEntry, $matches)) {
                    $docLineParts = self::splitDocLine($methodEntry);

                    $docblockLines[] = '@return ' . array_shift($docLineParts);

                    $methodEntry = implode(' ', $docLineParts);
                }

                $methodEntry = trim(preg_replace('/\/\/.*/', '', $methodEntry));

                $endOfMethodRegex = '/(?<!array\()\) ?(\: ?(\??[\\\\a-zA-Z0-9_]+))?/';

                if (preg_match($endOfMethodRegex, $methodEntry, $matches, PREG_OFFSET_CAPTURE)) {
                    $methodEntry = substr($methodEntry, 0, (int) $matches[0][1] + strlen((string) $matches[0][0]));
                }

                $methodEntry = str_replace([', ', '( '], [',', '('], $methodEntry);
                $methodEntry = preg_replace('/ (?!(\$|\.\.\.|&))/', '', trim($methodEntry));

                try {
                    $methodTree = Type\ParseTree::createFromTokens(Type::tokenize($methodEntry, false));
                } catch (TypeParseTreeException $e) {
                    throw new DocblockParseException($methodEntry . ' is not a valid method');
                }

                if (!$methodTree instanceof Type\ParseTree\MethodWithReturnTypeTree
                    && !$methodTree instanceof Type\ParseTree\MethodTree) {
                    throw new DocblockParseException($methodEntry . ' is not a valid method');
                }

                if ($methodTree instanceof Type\ParseTree\MethodWithReturnTypeTree) {
                    $docblockLines[] = '@return ' . Type::getTypeFromTree($methodTree->children[1]);
                    $methodTree = $methodTree->children[0];
                }

                if (!$methodTree instanceof Type\ParseTree\MethodTree) {
                    throw new DocblockParseException($methodEntry . ' is not a valid method');
                }

                $args = [];

                foreach ($methodTree->children as $methodTreeChild) {
                    if (!$methodTreeChild instanceof Type\ParseTree\MethodParamTree) {
                        throw new DocblockParseException($methodEntry . ' is not a valid method');
                    }

                    $args[] = ($methodTreeChild->byref ? '&' : '')
                        . ($methodTreeChild->variadic ? '...' : '')
                        . $methodTreeChild->name
                        . ($methodTreeChild->default != '' ? ' = ' . $methodTreeChild->default : '');


                    if ($methodTreeChild->children) {
                        $paramType = Type::getTypeFromTree($methodTreeChild->children[0]);
                        $docblockLines[] = '@param ' . $paramType . ' '
                            . ($methodTreeChild->variadic ? '...' : '')
                            . $methodTreeChild->name;
                    }
                }

                $functionString = 'function ' . $methodTree->value . '(' . implode(', ', $args) . ')';

                $functionDocblock = $docblockLines ? "/**\n * " . implode("\n * ", $docblockLines) . "\n*/\n" : "";

                $phpString = '<?php class A { ' . $functionDocblock . ' public ' . $functionString . '{} }';

                try {
                    $statements = \Psalm\Provider\StatementsProvider::parseStatements($phpString);
                } catch (\Exception $e) {
                    throw new DocblockParseException('Badly-formatted @method string ' . $methodEntry);
                }

                if (!$statements[0] instanceof \PhpParser\Node\Stmt\Class_
                    || !isset($statements[0]->stmts[0])
                    || !$statements[0]->stmts[0] instanceof \PhpParser\Node\Stmt\ClassMethod
                ) {
                    throw new DocblockParseException('Badly-formatted @method string ' . $methodEntry);
                }



                $info->methods[] = $statements[0]->stmts[0];
            }
        }

        self::addMagicPropertyToInfo($info, $comments['specials'], 'property');
        self::addMagicPropertyToInfo($info, $comments['specials'], 'property-read');
        self::addMagicPropertyToInfo($info, $comments['specials'], 'property-write');

        return $info;
    }

    /**
     * @param ClassLikeDocblockComment $info
     * @param array<string, array<int, string>> $specials
     * @param string $propertyTag ('property', 'property-read', or 'property-write')
     *
     * @throws DocblockParseException
     *
     * @return void
     */
    protected static function addMagicPropertyToInfo(ClassLikeDocblockComment $info, array $specials, $propertyTag)
    {
        $magicPropertyComments = isset($specials[$propertyTag]) ? $specials[$propertyTag] : [];
        foreach ($magicPropertyComments as $lineNumber => $property) {
            try {
                $lineParts = self::splitDocLine($property);
            } catch (DocblockParseException $e) {
                throw $e;
            }

            if (count($lineParts) === 1 && $lineParts[0][0] === '$') {
                array_unshift($lineParts, 'mixed');
            }

            if (count($lineParts) > 1) {
                if (preg_match('/^' . self::TYPE_REGEX . '$/', $lineParts[0])
                    && !preg_match('/\[[^\]]+\]/', $lineParts[0])
                    && preg_match('/^(\.\.\.)?&?\$[A-Za-z0-9_]+,?$/', $lineParts[1])
                    && !strpos($lineParts[0], '::')
                    && $lineParts[0][0] !== '{'
                ) {
                    if ($lineParts[1][0] === '&') {
                        $lineParts[1] = substr($lineParts[1], 1);
                    }

                    if ($lineParts[0][0] === '$' && !preg_match('/^\$this(\||$)/', $lineParts[0])) {
                        throw new IncorrectDocblockException('Misplaced variable');
                    }

                    $lineParts[1] = preg_replace('/,$/', '', $lineParts[1]);

                    $info->properties[] = [
                        'name' => $lineParts[1],
                        'type' => $lineParts[0],
                        'line_number' => $lineNumber,
                        'tag' => $propertyTag,
                    ];
                } else {
                    throw new DocblockParseException('Badly-formatted @property');
                }
            } else {
                throw new DocblockParseException('Badly-formatted @property');
            }
        }
    }

    /**
     * @param  string $returnBlock
     *
     * @throws DocblockParseException if an invalid string is found
     *
     * @return array<string>
     */
    public static function splitDocLine($returnBlock)
    {
        $brackets = '';

        $type = '';

        $expectsCallableReturn = false;

        $returnBlock = preg_replace('/[ \t]+/', ' ', $returnBlock);

        $quoteChar = null;
        $escaped = false;

        for ($i = 0, $l = strlen($returnBlock); $i < $l; ++$i) {
            $char = $returnBlock[$i];
            $nextChar = $i < $l - 1 ? $returnBlock[$i + 1] : null;

            if ($quoteChar) {
                if ($char === $quoteChar && $i > 1 && !$escaped) {
                    $quoteChar = null;

                    $type .= $char;

                    continue;
                }

                if ($char === '\\' && !$escaped && ($nextChar === $quoteChar || $nextChar === '\\')) {
                    $escaped = true;

                    $type .= $char;

                    continue;
                }

                $escaped = false;

                $type .= $char;

                continue;
            }

            if ($char === '"' || $char === '\'') {
                $quoteChar = $char;

                $type .= $char;

                continue;
            }

            if ($char === '[' || $char === '{' || $char === '(' || $char === '<') {
                $brackets .= $char;
            } elseif ($char === ']' || $char === '}' || $char === ')' || $char === '>') {
                $lastBracket = substr($brackets, -1);
                $brackets = substr($brackets, 0, -1);

                if (($char === ']' && $lastBracket !== '[')
                    || ($char === '}' && $lastBracket !== '{')
                    || ($char === ')' && $lastBracket !== '(')
                    || ($char === '>' && $lastBracket !== '<')
                ) {
                    throw new DocblockParseException('Invalid string ' . $returnBlock);
                }
            } elseif ($char === ' ') {
                if ($brackets) {
                    continue;
                }

                if ($nextChar === '|') {
                    ++$i;
                    $type .= $nextChar;
                    continue;
                }

                $lastChar = $i > 0 ? $returnBlock[$i - 1] : null;

                if ($lastChar === '|') {
                    continue;
                }

                if ($nextChar === ':') {
                    ++$i;
                    $type .= ':';
                    $expectsCallableReturn = true;
                    continue;
                }

                if ($expectsCallableReturn) {
                    $expectsCallableReturn = false;
                    continue;
                }

                $remaining = trim(substr($returnBlock, $i + 1));

                if ($remaining) {
                    return array_merge([$type], explode(' ', $remaining));
                }

                return [$type];
            }

            $type .= $char;
        }

        return [$type];
    }

    /**
     * Parse a docblock comment into its parts.
     *
     * Taken from advanced api docmaker, which was taken from
     * https://github.com/facebook/libphutil/blob/master/src/parser/docblock/PhutilDocblockParser.php
     *
     * @param  string  $docblock
     * @param  int     $lineNumber
     * @param  bool    $preserveFormat
     *
     * @return array Array of the main comment and specials
     * @psalm-return array{description:string, specials:array<string, array<int, string>>}
     */
    public static function parseDocComment($docblock, $lineNumber = null, $preserveFormat = false)
    {
        // Strip off comments.
        $docblock = trim($docblock);
        $docblock = preg_replace('@^/\*\*@', '', $docblock);
        $docblock = preg_replace('@\*/$@', '', $docblock);
        $docblock = preg_replace('@^[ \t]*\*@m', '', $docblock);

        // Normalize multi-line @specials.
        $lines = explode("\n", $docblock);

        $lineMap = [];

        $last = false;
        foreach ($lines as $k => $line) {
            if (preg_match('/^\s?@\w/i', $line)) {
                $last = $k;
            } elseif (preg_match('/^\s*$/', $line)) {
                $last = false;
            } elseif ($last !== false) {
                $oldLastLine = $lines[$last];
                $lines[$last] = rtrim($oldLastLine) . ($preserveFormat ? "\n" . $line : ' ' . trim($line));

                if ($lineNumber) {
                    $oldLineNumber = $lineMap[$oldLastLine];
                    unset($lineMap[$oldLastLine]);
                    $lineMap[$lines[$last]] = $oldLineNumber;
                }

                unset($lines[$k]);
            }

            if ($lineNumber) {
                $lineMap[$line] = $lineNumber++;
            }
        }

        $special = [];

        if ($preserveFormat) {
            foreach ($lines as $m => $line) {
                if (preg_match('/^\s?@([\w\-:]+)[\t ]*(.*)$/sm', $line, $matches)) {
                    /** @var string[] $matches */
                    list($fullMatch, $type, $data) = $matches;

                    $docblock = str_replace($fullMatch, '', $docblock);

                    if (empty($special[$type])) {
                        $special[$type] = [];
                    }

                    $lineNumber = $lineMap && isset($lineMap[$fullMatch]) ? $lineMap[$fullMatch] : (int)$m;

                    $special[$type][$lineNumber] = rtrim($data);
                }
            }
        } else {
            $docblock = implode("\n", $lines);

            // Parse @specials.
            if (preg_match_all('/^\s?@([\w\-:]+)[\t ]*([^\n]*)/m', $docblock, $matches, PREG_SET_ORDER)) {
                $docblock = preg_replace('/^\s?@([\w\-:]+)\s*([^\n]*)/m', '', $docblock);
                /** @var string[] $match */
                foreach ($matches as $m => $match) {
                    list($_, $type, $data) = $match;

                    if (empty($special[$type])) {
                        $special[$type] = [];
                    }

                    $lineNumber = $lineMap && isset($lineMap[$_]) ? $lineMap[$_] : (int)$m;

                    $special[$type][$lineNumber] = $data;
                }
            }
        }

        $docblock = str_replace("\t", '  ', $docblock);

        // Smush the whole docblock to the left edge.
        $minIndent = 80;
        $indent = 0;
        foreach (array_filter(explode("\n", $docblock)) as $line) {
            for ($ii = 0; $ii < strlen($line); ++$ii) {
                if ($line[$ii] != ' ') {
                    break;
                }
                ++$indent;
            }

            $minIndent = min($indent, $minIndent);
        }

        $docblock = preg_replace('/^' . str_repeat(' ', $minIndent) . '/m', '', $docblock);
        $docblock = rtrim($docblock);

        // Trim any empty lines off the front, but leave the indent level if there
        // is one.
        $docblock = preg_replace('/^\s*\n/', '', $docblock);

        return [
            'description' => $docblock,
            'specials' => $special,
        ];
    }

    /**
     * @param  array{description:string,specials:array<string,array<string>>} $parsedDocComment
     * @param  string                                                         $leftPadding
     *
     * @return string
     */
    public static function renderDocComment(array $parsedDocComment, $leftPadding)
    {
        $docCommentText = '/**' . "\n";

        $descriptionLines = null;

        $trimmedDescription = trim($parsedDocComment['description']);

        if (!empty($trimmedDescription)) {
            $descriptionLines = explode("\n", $parsedDocComment['description']);

            foreach ($descriptionLines as $line) {
                $docCommentText .= $leftPadding . ' *' . (trim($line) ? ' ' . $line : '') . "\n";
            }
        }

        if ($descriptionLines && $parsedDocComment['specials']) {
            $docCommentText .= $leftPadding . ' *' . "\n";
        }

        if ($parsedDocComment['specials']) {
            $lastType = null;

            foreach ($parsedDocComment['specials'] as $type => $lines) {
                if ($lastType !== null && $lastType !== 'psalm-return') {
                    $docCommentText .= $leftPadding . ' *' . "\n";
                }

                foreach ($lines as $line) {
                    $docCommentText .= $leftPadding . ' * @' . $type . ' '
                        . str_replace("\n", "\n" . $leftPadding . ' *', $line) . "\n";
                }

                $lastType = $type;
            }
        }

        $docCommentText .= $leftPadding . ' */' . "\n" . $leftPadding;

        return $docCommentText;
    }
}
