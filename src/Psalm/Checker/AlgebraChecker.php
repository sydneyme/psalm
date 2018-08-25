<?php
namespace Psalm\Checker;

use PhpParser;
use Psalm\Checker\Statements\Expression\AssertionFinder;
use Psalm\Clause;
use Psalm\CodeLocation;
use Psalm\FileSource;
use Psalm\Issue\ParadoxicalCondition;
use Psalm\Issue\RedundantCondition;
use Psalm\IssueBuffer;
use Psalm\Type\Algebra;

class AlgebraChecker
{
    /**
     * This looks to see if there are any clauses in one formula that contradict
     * clauses in another formula, or clauses that duplicate previous clauses
     *
     * e.g.
     * if ($a) { }
     * elseif ($a) { }
     *
     * @param  array<int, Clause>   $formula1
     * @param  array<int, Clause>   $formula2
     * @param  StatementsChecker    $statementsChecker,
     * @param  PhpParser\Node       $stmt
     * @param  array<string, bool>  $newAssignedVarIds
     *
     * @return void
     */
    public static function checkForParadox(
        array $formula1,
        array $formula2,
        StatementsChecker $statementsChecker,
        PhpParser\Node $stmt,
        array $newAssignedVarIds
    ) {
        $negatedFormula2 = Algebra::negateFormula($formula2);

        $formula1Hashes = [];

        foreach ($formula1 as $formula1Clause) {
            $formula1Hashes[$formula1Clause->getHash()] = true;
        }

        $formula2Hashes = [];

        foreach ($formula2 as $formula2Clause) {
            $hash = $formula2Clause->getHash();

            if (!$formula2Clause->generated
                && (isset($formula1Hashes[$hash]) || isset($formula2Hashes[$hash]))
                && !array_intersect_key($newAssignedVarIds, $formula2Clause->possibilities)
            ) {
                if (IssueBuffer::accepts(
                    new RedundantCondition(
                        $formula2Clause . ' has already been asserted',
                        new CodeLocation($statementsChecker, $stmt)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    // fall through
                }
            }

            foreach ($formula2Clause->possibilities as $key => $values) {
                if (!$formula2Clause->generated
                    && count($values) > 1
                    && !isset($newAssignedVarIds[$key])
                    && count(array_unique($values)) < count($values)
                ) {
                    if (IssueBuffer::accepts(
                        new ParadoxicalCondition(
                            'Found a redundant condition when evaluating assertion (' . $formula2Clause . ')',
                            new CodeLocation($statementsChecker, $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }
            }

            $formula2Hashes[$hash] = true;
        }

        // remove impossible types
        foreach ($negatedFormula2 as $clauseA) {
            if (count($negatedFormula2) === 1) {
                foreach ($clauseA->possibilities as $key => $values) {
                    if (count($values) > 1
                        && !isset($newAssignedVarIds[$key])
                        && count(array_unique($values)) < count($values)
                    ) {
                        if (IssueBuffer::accepts(
                            new RedundantCondition(
                                'Found a redundant condition when evaluating ' . $key,
                                new CodeLocation($statementsChecker, $stmt)
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    }
                }
            }

            if (!$clauseA->reconcilable || $clauseA->wedge) {
                continue;
            }

            foreach ($formula1 as $clauseB) {
                if ($clauseA === $clauseB || !$clauseB->reconcilable || $clauseB->wedge) {
                    continue;
                }

                $clauseAContainsBPossibilities = true;

                foreach ($clauseB->possibilities as $key => $keyedPossibilities) {
                    if (!isset($clauseA->possibilities[$key])) {
                        $clauseAContainsBPossibilities = false;
                        break;
                    }

                    if ($clauseA->possibilities[$key] != $keyedPossibilities) {
                        $clauseAContainsBPossibilities = false;
                        break;
                    }
                }

                if ($clauseAContainsBPossibilities) {
                    if (IssueBuffer::accepts(
                        new ParadoxicalCondition(
                            'Encountered a paradox when evaluating the conditionals ('
                                . $clauseA . ') and (' . $clauseB . ')',
                            new CodeLocation($statementsChecker, $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }

                    return;
                }
            }
        }
    }
}
