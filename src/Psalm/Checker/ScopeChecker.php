<?php
namespace Psalm\Checker;

use PhpParser;

class ScopeChecker
{
    const ACTION_END = 'END';
    const ACTION_BREAK = 'BREAK';
    const ACTION_CONTINUE = 'CONTINUE';
    const ACTION_LEAVE_SWITCH = 'LEAVE_SWITCH';
    const ACTION_NONE = 'NONE';
    const ACTION_RETURN = 'RETURN';

    /**
     * @param   array<PhpParser\Node\Stmt>   $stmts
     *
     * @return  bool
     */
    public static function doesEverBreak(array $stmts)
    {
        if (empty($stmts)) {
            return false;
        }

        for ($i = count($stmts) - 1; $i >= 0; --$i) {
            $stmt = $stmts[$i];

            if ($stmt instanceof PhpParser\Node\Stmt\Break_) {
                return true;
            }

            if ($stmt instanceof PhpParser\Node\Stmt\If_) {
                if (self::doesEverBreak($stmt->stmts)) {
                    return true;
                }

                if ($stmt->else && self::doesEverBreak($stmt->else->stmts)) {
                    return true;
                }

                foreach ($stmt->elseifs as $elseif) {
                    if (self::doesEverBreak($elseif->stmts)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param   array<PhpParser\Node> $stmts
     * @param   bool $inSwitch when checking inside a switch statement, continue is an alias of break
     * @param   bool $returnIsExit Exit and Throw statements are treated differently from return if this is false
     *
     * @return  string[] one or more of 'LEAVE', 'CONTINUE', 'BREAK' (or empty if no single action is found)
     */
    public static function getFinalControlActions(
        array $stmts,
        array $exitFunctions,
        $inSwitch = false,
        $returnIsExit = true
    ) {
        if (empty($stmts)) {
            return [self::ACTION_NONE];
        }

        $controlActions = [];

        for ($i = 0, $c = count($stmts); $i < $c; ++$i) {
            $stmt = $stmts[$i];

            if ($stmt instanceof PhpParser\Node\Stmt\Return_ ||
                $stmt instanceof PhpParser\Node\Stmt\Throw_ ||
                ($stmt instanceof PhpParser\Node\Stmt\Expression && $stmt->expr instanceof PhpParser\Node\Expr\Exit_)
            ) {
                if (!$returnIsExit && $stmt instanceof PhpParser\Node\Stmt\Return_) {
                    return [self::ACTION_RETURN];
                }

                return [self::ACTION_END];
            }

            if ($stmt instanceof PhpParser\Node\Stmt\Expression) {
                if ($stmt->expr instanceof PhpParser\Node\Expr\FuncCall
                    && $stmt->expr->name instanceof PhpParser\Node\Name
                    && $stmt->expr->name->parts === ['trigger_error']
                    && isset($stmt->expr->args[1])
                    && $stmt->expr->args[1]->value instanceof PhpParser\Node\Expr\ConstFetch
                    && in_array(
                        end($stmt->expr->args[1]->value->name->parts),
                        ['E_ERROR', 'E_PARSE', 'E_CORE_ERROR', 'E_COMPILE_ERROR', 'E_USER_ERROR']
                    )
                ) {
                    return [self::ACTION_END];
                }

                if ($exitFunctions) {
                    if ($stmt->expr instanceof PhpParser\Node\Expr\FuncCall
                        || $stmt->expr instanceof PhpParser\Node\Expr\StaticCall
                    ) {
                        if ($stmt->expr instanceof PhpParser\Node\Expr\FuncCall) {
                            /** @var string|null */
                            $resolvedName = $stmt->expr->name->getAttribute('resolvedName');

                            if ($resolvedName && isset($exitFunctions[strtolower($resolvedName)])) {
                                return [self::ACTION_END];
                            }
                        } elseif ($stmt->expr->class instanceof PhpParser\Node\Name
                            && $stmt->expr->name instanceof PhpParser\Node\Identifier
                        ) {
                            /** @var string|null */
                            $resolvedClassName = $stmt->expr->class->getAttribute('resolvedName');

                            if ($resolvedClassName
                                && isset($exitFunctions[strtolower($resolvedClassName . '::' . $stmt->expr->name)])
                            ) {
                                return [self::ACTION_END];
                            }
                        }
                    }
                }

                continue;
            }

            if ($stmt instanceof PhpParser\Node\Stmt\Continue_) {
                if ($inSwitch
                    && (!$stmt->num || !$stmt->num instanceof PhpParser\Node\Scalar\LNumber || $stmt->num->value < 2)
                ) {
                    return [self::ACTION_LEAVE_SWITCH];
                }

                return [self::ACTION_CONTINUE];
            }

            if ($stmt instanceof PhpParser\Node\Stmt\Break_) {
                if ($inSwitch
                    && (!$stmt->num || !$stmt->num instanceof PhpParser\Node\Scalar\LNumber || $stmt->num->value < 2)
                ) {
                    return [self::ACTION_LEAVE_SWITCH];
                }

                return [self::ACTION_BREAK];
            }

            if ($stmt instanceof PhpParser\Node\Stmt\If_) {
                $ifStatementActions = self::getFinalControlActions($stmt->stmts, $exitFunctions, $inSwitch);
                $elseStatementActions = $stmt->else
                    ? self::getFinalControlActions($stmt->else->stmts, $exitFunctions, $inSwitch)
                    : [];

                $allSame = count($ifStatementActions) === 1
                    && $ifStatementActions == $elseStatementActions
                    && $ifStatementActions !== [self::ACTION_NONE];

                $allElseifActions = [];

                if ($stmt->elseifs) {
                    foreach ($stmt->elseifs as $elseif) {
                        $elseifControlActions = self::getFinalControlActions(
                            $elseif->stmts,
                            $exitFunctions,
                            $inSwitch
                        );

                        $allSame = $allSame && $elseifControlActions == $ifStatementActions;

                        if (!$allSame) {
                            $allElseifActions = array_merge($elseifControlActions, $allElseifActions);
                        }
                    }
                }

                if ($allSame) {
                    return $ifStatementActions;
                }

                $controlActions = array_merge(
                    $controlActions,
                    $ifStatementActions,
                    $elseStatementActions,
                    $allElseifActions
                );
            }

            if ($stmt instanceof PhpParser\Node\Stmt\Switch_) {
                $hasEnded = false;
                $hasNonBreakingDefault = false;
                $hasDefaultTerminator = false;

                // iterate backwards in a case statement
                for ($d = count($stmt->cases) - 1; $d >= 0; --$d) {
                    $case = $stmt->cases[$d];

                    $caseActions = self::getFinalControlActions($case->stmts, $exitFunctions, true);

                    if (array_intersect([
                        self::ACTION_LEAVE_SWITCH,
                        self::ACTION_BREAK,
                        self::ACTION_CONTINUE
                    ], $caseActions)
                    ) {
                        continue 2;
                    }

                    if (!$case->cond) {
                        $hasNonBreakingDefault = true;
                    }

                    $caseDoesEnd = $caseActions == [self::ACTION_END];

                    if ($caseDoesEnd) {
                        $hasEnded = true;
                    }

                    if (!$caseDoesEnd && !$hasEnded) {
                        continue 2;
                    }

                    if ($hasNonBreakingDefault && $caseDoesEnd) {
                        $hasDefaultTerminator = true;
                    }
                }

                if ($hasDefaultTerminator || isset($stmt->allMatched)) {
                    return [self::ACTION_END];
                }
            }

            if ($stmt instanceof PhpParser\Node\Stmt\While_) {
                $controlActions = array_merge(
                    self::getFinalControlActions($stmt->stmts, $exitFunctions),
                    $controlActions
                );
            }

            if ($stmt instanceof PhpParser\Node\Stmt\Do_) {
                $doActions = self::getFinalControlActions($stmt->stmts, $exitFunctions);

                if (count($doActions) && !in_array(self::ACTION_NONE, $doActions, true)) {
                    return $doActions;
                }

                $controlActions = array_merge($controlActions, $doActions);
            }

            if ($stmt instanceof PhpParser\Node\Stmt\TryCatch) {
                $tryStatementActions = self::getFinalControlActions($stmt->stmts, $exitFunctions, $inSwitch);

                if ($stmt->catches) {
                    $allSame = count($tryStatementActions) === 1;

                    foreach ($stmt->catches as $catch) {
                        $catchActions = self::getFinalControlActions($catch->stmts, $exitFunctions, $inSwitch);

                        $allSame = $allSame && $tryStatementActions == $catchActions;

                        if (!$allSame) {
                            $controlActions = array_merge($controlActions, $catchActions);
                        }
                    }

                    if ($allSame && $tryStatementActions !== [self::ACTION_NONE]) {
                        return $tryStatementActions;
                    }
                }

                if ($stmt->finally) {
                    if ($stmt->finally->stmts) {
                        $finallyStatementActions = self::getFinalControlActions(
                            $stmt->finally->stmts,
                            $exitFunctions,
                            $inSwitch
                        );

                        if (!in_array(self::ACTION_NONE, $finallyStatementActions, true)) {
                            return $finallyStatementActions;
                        }
                    }

                    if (!$stmt->catches && !in_array(self::ACTION_NONE, $tryStatementActions, true)) {
                        return $tryStatementActions;
                    }
                }

                $controlActions = array_merge($controlActions, $tryStatementActions);
            }
        }

        $controlActions[] = self::ACTION_NONE;

        return array_unique($controlActions);
    }

    /**
     * @param   array<PhpParser\Node> $stmts
     *
     * @return  bool
     */
    public static function onlyThrows(array $stmts)
    {
        if (empty($stmts)) {
            return false;
        }

        for ($i = count($stmts) - 1; $i >= 0; --$i) {
            $stmt = $stmts[$i];

            if ($stmt instanceof PhpParser\Node\Stmt\Throw_) {
                return true;
            }

            if ($stmt instanceof PhpParser\Node\Stmt\Nop) {
                continue;
            }

            return false;
        }

        return false;
    }
}
