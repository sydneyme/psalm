<?php
namespace Psalm\Type;

use PhpParser;
use Psalm\Checker\Statements\Expression\AssertionFinder;
use Psalm\Clause;
use Psalm\CodeLocation;
use Psalm\FileSource;
use Psalm\IssueBuffer;
use Psalm\Type\Algebra;

class Algebra
{
    /**
     * @param  array<string, array<int, array<int, string>>>  $allTypes
     *
     * @return array<string, array<int, array<int, string>>>
     */
    public static function negateTypes(array $allTypes)
    {
        return array_map(
            /**
             * @param  array<int, array<int, string>> $andedTypes
             *
             * @return  array<int, array<int, string>>
             */
            function (array $andedTypes) {
                if (count($andedTypes) > 1) {
                    $newAndedTypes = [];

                    foreach ($andedTypes as $orredTypes) {
                        if (count($orredTypes) > 1) {
                            return [];
                        }

                        $newAndedTypes[] = self::negateType($orredTypes[0]);
                    }

                    return [$newAndedTypes];
                }

                $newOrredTypes = [];

                foreach ($andedTypes[0] as $orredType) {
                    $newOrredTypes[] = [self::negateType($orredType)];
                }

                return $newOrredTypes;
            },
            $allTypes
        );
    }

    /**
     * @param  string $type
     *
     * @return  string
     */
    private static function negateType($type)
    {
        if ($type === 'mixed') {
            return $type;
        }

        return $type[0] === '!' ? substr($type, 1) : '!' . $type;
    }

    /**
     * @param  PhpParser\Node\Expr      $conditional
     * @param  string|null              $thisClassName
     * @param  FileSource         $source
     *
     * @return array<int, Clause>
     */
    public static function getFormula(
        PhpParser\Node\Expr $conditional,
        $thisClassName,
        FileSource $source
    ) {
        if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\BooleanAnd ||
            $conditional instanceof PhpParser\Node\Expr\BinaryOp\LogicalAnd
        ) {
            $leftAssertions = self::getFormula(
                $conditional->left,
                $thisClassName,
                $source
            );

            $rightAssertions = self::getFormula(
                $conditional->right,
                $thisClassName,
                $source
            );

            return array_merge(
                $leftAssertions,
                $rightAssertions
            );
        }

        if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\BooleanOr ||
            $conditional instanceof PhpParser\Node\Expr\BinaryOp\LogicalOr
        ) {
            // at the moment we only support formulae in CNF

            $leftClauses = self::getFormula(
                $conditional->left,
                $thisClassName,
                $source
            );

            $rightClauses = self::getFormula(
                $conditional->right,
                $thisClassName,
                $source
            );

            return self::combineOredClauses($leftClauses, $rightClauses);
        }

        AssertionFinder::scrapeAssertions(
            $conditional,
            $thisClassName,
            $source
        );

        if (isset($conditional->assertions) && $conditional->assertions) {
            $assertions = $conditional->assertions;

            $clauses = [];

            foreach ($assertions as $var => $andedTypes) {
                foreach ($andedTypes as $orredTypes) {
                    $clauses[] = new Clause(
                        [$var => $orredTypes],
                        false,
                        true,
                        $orredTypes[0][0] === '^'
                            || $orredTypes[0][0] === '~'
                            || (strlen($orredTypes[0]) > 1
                                && ($orredTypes[0][1] === '^'
                                    || $orredTypes[0][1] === '~'))
                    );
                }
            }

            return $clauses;
        }

        return [new Clause([], true)];
    }

    /**
     * This is a very simple simplification heuristic
     * for CNF formulae.
     *
     * It simplifies formulae:
     *     ($a) && ($a || $b) => $a
     *     (!$a) && (!$b) && ($a || $b || $c) => $c
     *
     * @param  array<int, Clause>  $clauses
     *
     * @return array<int, Clause>
     */
    public static function simplifyCNF(array $clauses)
    {
        $clonedClauses = [];

        // avoid strict duplicates
        foreach ($clauses as $clause) {
            $uniqueClause = clone $clause;
            foreach ($uniqueClause->possibilities as $varId => $possibilities) {
                if (count($possibilities)) {
                    $uniqueClause->possibilities[$varId] = array_unique($possibilities);
                }
            }
            $clonedClauses[$clause->getHash()] = $uniqueClause;
        }

        // remove impossible types
        foreach ($clonedClauses as $clauseA) {
            if (count($clauseA->possibilities) !== 1 || count(array_values($clauseA->possibilities)[0]) !== 1) {
                continue;
            }

            if (!$clauseA->reconcilable || $clauseA->wedge) {
                continue;
            }

            $clauseVar = array_keys($clauseA->possibilities)[0];
            $onlyType = array_pop(array_values($clauseA->possibilities)[0]);
            $negatedClauseType = self::negateType($onlyType);

            foreach ($clonedClauses as $clauseB) {
                if ($clauseA === $clauseB || !$clauseB->reconcilable || $clauseB->wedge) {
                    continue;
                }

                if (isset($clauseB->possibilities[$clauseVar]) &&
                    in_array($negatedClauseType, $clauseB->possibilities[$clauseVar], true)
                ) {
                    $clauseB->possibilities[$clauseVar] = array_filter(
                        $clauseB->possibilities[$clauseVar],
                        /**
                         * @param string $possibleType
                         *
                         * @return bool
                         */
                        function ($possibleType) use ($negatedClauseType) {
                            return $possibleType !== $negatedClauseType;
                        }
                    );

                    if (count($clauseB->possibilities[$clauseVar]) === 0) {
                        unset($clauseB->possibilities[$clauseVar]);
                        $clauseB->impossibilities = null;
                    }
                }
            }
        }

        $dedupedClauses = [];

        // avoid strict duplicates
        foreach ($clonedClauses as $clause) {
            $dedupedClauses[$clause->getHash()] = clone $clause;
        }

        $dedupedClauses = array_filter(
            $dedupedClauses,
            /**
             * @return bool
             */
            function (Clause $clause) {
                return count($clause->possibilities) || $clause->wedge;
            }
        );

        $simplifiedClauses = [];

        foreach ($dedupedClauses as $clauseA) {
            $isRedundant = false;

            foreach ($dedupedClauses as $clauseB) {
                if ($clauseA === $clauseB
                    || !$clauseB->reconcilable
                    || $clauseB->wedge
                    || $clauseA->wedge
                ) {
                    continue;
                }

                if ($clauseA->contains($clauseB)) {
                    $isRedundant = true;
                    break;
                }
            }

            if (!$isRedundant) {
                $simplifiedClauses[] = $clauseA;
            }
        }

        return $simplifiedClauses;
    }

    /**
     * Look for clauses with only one possible value
     *
     * @param  array<int, Clause>  $clauses
     * @param  array<string, bool> $condReferencedVarIds
     *
     * @return array<string, array<int, array<int, string>>>
     */
    public static function getTruthsFromFormula(
        array $clauses,
        array &$condReferencedVarIds = []
    ) {
        $truths = [];

        if (empty($clauses)) {
            return [];
        }

        foreach ($clauses as $clause) {
            if (!$clause->reconcilable) {
                continue;
            }

            foreach ($clause->possibilities as $var => $possibleTypes) {
                // if there's only one possible type, return it
                if (count($clause->possibilities) === 1 && count($possibleTypes) === 1) {
                    if (isset($truths[$var])) {
                        $truths[$var][] = [array_pop($possibleTypes)];
                    } else {
                        $truths[$var] = [[array_pop($possibleTypes)]];
                    }
                } elseif (count($clause->possibilities) === 1) {
                    // if there's only one active clause, return all the non-negation clause members ORed together
                    $thingsThatCanBeSaid = array_filter(
                        $possibleTypes,
                        /**
                         * @param  string $possibleType
                         *
                         * @return bool
                         *
                         * @psalm-suppress MixedOperand
                         */
                        function ($possibleType) {
                            return $possibleType[0] !== '!';
                        }
                    );

                    if ($thingsThatCanBeSaid && count($thingsThatCanBeSaid) === count($possibleTypes)) {
                        $thingsThatCanBeSaid = array_unique($thingsThatCanBeSaid);

                        if ($clause->generated && count($possibleTypes) > 1) {
                            unset($condReferencedVarIds[$var]);
                        }

                        /** @var array<int, string> $thingsThatCanBeSaid */
                        $truths[$var] = [$thingsThatCanBeSaid];
                    }
                }
            }
        }

        return $truths;
    }

    /**
     * @param  array<int, Clause>  $clauses
     *
     * @return array<int, Clause>
     */
    private static function groupImpossibilities(array $clauses)
    {
        if (count($clauses) > 5000) {
            return [];
        }

        $clause = array_shift($clauses);

        $newClauses = [];

        if ($clauses) {
            $groupedClauses = self::groupImpossibilities($clauses);

            if (count($groupedClauses) > 5000) {
                return [];
            }

            foreach ($groupedClauses as $groupedClause) {
                if ($clause->impossibilities === null) {
                    throw new \UnexpectedValueException('$clause->impossibilities should not be null');
                }

                foreach ($clause->impossibilities as $var => $impossibleTypes) {
                    foreach ($impossibleTypes as $impossibleType) {
                        $newClausePossibilities = $groupedClause->possibilities;

                        if (isset($groupedClause->possibilities[$var])) {
                            $newClausePossibilities[$var][] = $impossibleType;
                        } else {
                            $newClausePossibilities[$var] = [$impossibleType];
                        }

                        $newClause = new Clause($newClausePossibilities, false, true, true);

                        $newClauses[] = $newClause;
                    }
                }
            }
        } elseif ($clause && !$clause->wedge) {
            if ($clause->impossibilities === null) {
                throw new \UnexpectedValueException('$clause->impossibilities should not be null');
            }

            foreach ($clause->impossibilities as $var => $impossibleTypes) {
                foreach ($impossibleTypes as $impossibleType) {
                    $newClause = new Clause([$var => [$impossibleType]]);

                    $newClauses[] = $newClause;
                }
            }
        }

        return $newClauses;
    }

    /**
     * @param  array<int, Clause>  $leftClauses
     * @param  array<int, Clause>  $rightClauses
     *
     * @return array<int, Clause>
     */
    public static function combineOredClauses(array $leftClauses, array $rightClauses)
    {
        $clauses = [];

        $allWedges = true;
        $hasWedge = false;

        foreach ($leftClauses as $leftClause) {
            foreach ($rightClauses as $rightClause) {
                $allWedges = $allWedges && ($leftClause->wedge && $rightClause->wedge);
                $hasWedge = $hasWedge || ($leftClause->wedge && $rightClause->wedge);
            }
        }

        if ($allWedges) {
            return [new Clause([], true)];
        }

        foreach ($leftClauses as $leftClause) {
            foreach ($rightClauses as $rightClause) {
                if ($leftClause->wedge && $rightClause->wedge) {
                    // handled below
                    continue;
                }

                $possibilities = [];

                $canReconcile = true;

                if ($leftClause->wedge ||
                    $rightClause->wedge ||
                    !$leftClause->reconcilable ||
                    !$rightClause->reconcilable
                ) {
                    $canReconcile = false;
                }

                foreach ($leftClause->possibilities as $var => $possibleTypes) {
                    if (isset($possibilities[$var])) {
                        $possibilities[$var] = array_merge($possibilities[$var], $possibleTypes);
                    } else {
                        $possibilities[$var] = $possibleTypes;
                    }
                }

                foreach ($rightClause->possibilities as $var => $possibleTypes) {
                    if (isset($possibilities[$var])) {
                        $possibilities[$var] = array_merge($possibilities[$var], $possibleTypes);
                    } else {
                        $possibilities[$var] = $possibleTypes;
                    }
                }

                if (count($leftClauses) > 1 || count($rightClauses) > 1) {
                    foreach ($possibilities as $var => $p) {
                        $possibilities[$var] = array_unique($p);
                    }
                }

                $clauses[] = new Clause(
                    $possibilities,
                    false,
                    $canReconcile,
                    $rightClause->generated
                        || $leftClause->generated
                        || count($leftClauses) > 1
                        || count($rightClauses) > 1
                );
            }
        }

        if ($hasWedge) {
            $clauses[] = new Clause([], true);
        }

        return $clauses;
    }

    /**
     * Negates a set of clauses
     * negateClauses([$a || $b]) => !$a && !$b
     * negateClauses([$a, $b]) => !$a || !$b
     * negateClauses([$a, $b || $c]) =>
     *   (!$a || !$b) &&
     *   (!$a || !$c)
     * negateClauses([$a, $b || $c, $d || $e || $f]) =>
     *   (!$a || !$b || !$d) &&
     *   (!$a || !$b || !$e) &&
     *   (!$a || !$b || !$f) &&
     *   (!$a || !$c || !$d) &&
     *   (!$a || !$c || !$e) &&
     *   (!$a || !$c || !$f)
     *
     * @param  array<int, Clause>  $clauses
     *
     * @return array<int, Clause>
     */
    public static function negateFormula(array $clauses)
    {
        foreach ($clauses as $clause) {
            self::calculateNegation($clause);
        }

        $negated = self::simplifyCNF(self::groupImpossibilities($clauses));
        return $negated;
    }

    /**
     * @param  Clause $clause
     *
     * @return void
     */
    public static function calculateNegation(Clause $clause)
    {
        if ($clause->impossibilities !== null) {
            return;
        }

        $impossibilities = [];

        foreach ($clause->possibilities as $varId => $possiblity) {
            $impossibility = [];

            foreach ($possiblity as $type) {
                if (($type[0] !== '^' && $type[0] !== '~'
                        && (!isset($type[1]) || ($type[1] !== '^' && $type[1] !== '~')))
                    || strpos($type, '(')
                    || strpos($type, 'getclass-')
                ) {
                    $impossibility[] = self::negateType($type);
                }
            }

            if ($impossibility) {
                $impossibilities[$varId] = $impossibility;
            }
        }

        $clause->impossibilities = $impossibilities;
    }
}
