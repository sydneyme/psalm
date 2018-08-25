<?php
namespace Psalm\Checker\Statements\Expression;

use PhpParser;
use Psalm\Checker\AlgebraChecker;
use Psalm\Checker\FunctionLikeChecker;
use Psalm\Checker\Statements\Expression\Assignment\ArrayAssignmentChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Checker\TypeChecker;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\Context;
use Psalm\Issue\FalseOperand;
use Psalm\Issue\ImplicitToStringCast;
use Psalm\Issue\InvalidOperand;
use Psalm\Issue\MixedOperand;
use Psalm\Issue\NullOperand;
use Psalm\Issue\PossiblyFalseOperand;
use Psalm\Issue\PossiblyInvalidOperand;
use Psalm\Issue\PossiblyNullOperand;
use Psalm\IssueBuffer;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Algebra;
use Psalm\Type\Atomic\ObjectLike;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TGenericParam;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TNumeric;
use Psalm\Type\Reconciler;
use Psalm\Type\TypeCombination;
use Psalm\Type\Union;

class BinaryOpChecker
{
    /**
     * @param   StatementsChecker               $statementsChecker
     * @param   PhpParser\Node\Expr\BinaryOp    $stmt
     * @param   Context                         $context
     * @param   int                             $nesting
     *
     * @return  false|null
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\BinaryOp $stmt,
        Context $context,
        $nesting = 0
    ) {
        if ($stmt instanceof PhpParser\Node\Expr\BinaryOp\Concat && $nesting > 20) {
            // ignore deeply-nested string concatenation
        } elseif ($stmt instanceof PhpParser\Node\Expr\BinaryOp\BooleanAnd ||
            $stmt instanceof PhpParser\Node\Expr\BinaryOp\LogicalAnd
        ) {
            $leftClauses = Algebra::getFormula(
                $stmt->left,
                $statementsChecker->getFQCLN(),
                $statementsChecker
            );

            $preReferencedVarIds = $context->referencedVarIds;
            $context->referencedVarIds = [];
            $originalVarsInScope = $context->varsInScope;

            $preAssignedVarIds = $context->assignedVarIds;

            if (ExpressionChecker::analyze($statementsChecker, $stmt->left, $context) === false) {
                return false;
            }

            $newReferencedVarIds = $context->referencedVarIds;
            $context->referencedVarIds = array_merge($preReferencedVarIds, $newReferencedVarIds);

            $newAssignedVarIds = array_diff_key($context->assignedVarIds, $preAssignedVarIds);

            $newReferencedVarIds = array_diff_key($newReferencedVarIds, $newAssignedVarIds);

            // remove all newly-asserted var ids too
            $newReferencedVarIds = array_filter(
                $newReferencedVarIds,
                /**
                 * @param string $varId
                 *
                 * @return bool
                 */
                function ($varId) use ($originalVarsInScope) {
                    return isset($originalVarsInScope[$varId]);
                },
                ARRAY_FILTER_USE_KEY
            );

            $simplifiedClauses = Algebra::simplifyCNF(array_merge($context->clauses, $leftClauses));

            $leftTypeAssertions = Algebra::getTruthsFromFormula($simplifiedClauses);

            $changedVarIds = [];

            // while in an and, we allow scope to boil over to support
            // statements of the form if ($x && $x->foo())
            $opVarsInScope = Reconciler::reconcileKeyedTypes(
                $leftTypeAssertions,
                $context->varsInScope,
                $changedVarIds,
                $newReferencedVarIds,
                $statementsChecker,
                new CodeLocation($statementsChecker->getSource(), $stmt),
                $statementsChecker->getSuppressedIssues()
            );

            $opContext = clone $context;
            $opContext->varsInScope = $opVarsInScope;

            $opContext->removeReconciledClauses($changedVarIds);

            if (ExpressionChecker::analyze($statementsChecker, $stmt->right, $opContext) === false) {
                return false;
            }

            $context->referencedVarIds = array_merge(
                $opContext->referencedVarIds,
                $context->referencedVarIds
            );

            if ($context->collectReferences) {
                $context->unreferencedVars = $opContext->unreferencedVars;
            }

            foreach ($opContext->varsInScope as $varId => $type) {
                if (isset($context->varsInScope[$varId])) {
                    $context->varsInScope[$varId] = Type::combineUnionTypes($context->varsInScope[$varId], $type);
                }
            }

            if ($context->insideConditional) {
                foreach ($opContext->varsInScope as $var => $type) {
                    if (!isset($context->varsInScope[$var])) {
                        $context->varsInScope[$var] = $type;
                        continue;
                    }
                }

                $context->updateChecks($opContext);

                $context->varsPossiblyInScope = array_merge(
                    $opContext->varsPossiblyInScope,
                    $context->varsPossiblyInScope
                );

                $context->assignedVarIds = array_merge(
                    $context->assignedVarIds,
                    $opContext->assignedVarIds
                );
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\BinaryOp\BooleanOr ||
            $stmt instanceof PhpParser\Node\Expr\BinaryOp\LogicalOr
        ) {
            $preReferencedVarIds = $context->referencedVarIds;
            $context->referencedVarIds = [];

            $preAssignedVarIds = $context->assignedVarIds;

            if (ExpressionChecker::analyze($statementsChecker, $stmt->left, $context) === false) {
                return false;
            }

            $newReferencedVarIds = $context->referencedVarIds;
            $context->referencedVarIds = array_merge($preReferencedVarIds, $newReferencedVarIds);

            $newAssignedVarIds = array_diff_key($context->assignedVarIds, $preAssignedVarIds);

            $newReferencedVarIds = array_diff_key($newReferencedVarIds, $newAssignedVarIds);

            $leftClauses = Algebra::getFormula(
                $stmt->left,
                $statementsChecker->getFQCLN(),
                $statementsChecker
            );

            $negatedLeftClauses = Algebra::negateFormula($leftClauses);

            $clausesForRightAnalysis = Algebra::simplifyCNF(
                array_merge(
                    $context->clauses,
                    $negatedLeftClauses
                )
            );

            $negatedTypeAssertions = Algebra::getTruthsFromFormula($clausesForRightAnalysis);

            $changedVarIds = [];

            // while in an or, we allow scope to boil over to support
            // statements of the form if ($x === null || $x->foo())
            $opVarsInScope = Reconciler::reconcileKeyedTypes(
                $negatedTypeAssertions,
                $context->varsInScope,
                $changedVarIds,
                $newReferencedVarIds,
                $statementsChecker,
                new CodeLocation($statementsChecker->getSource(), $stmt),
                $statementsChecker->getSuppressedIssues()
            );

            $opContext = clone $context;
            $opContext->clauses = $clausesForRightAnalysis;
            $opContext->varsInScope = $opVarsInScope;

            $opContext->removeReconciledClauses($changedVarIds);

            if (ExpressionChecker::analyze($statementsChecker, $stmt->right, $opContext) === false) {
                return false;
            }

            if (!($stmt->right instanceof PhpParser\Node\Expr\Exit_)) {
                foreach ($opContext->varsInScope as $varId => $type) {
                    if (isset($context->varsInScope[$varId])) {
                        $context->varsInScope[$varId] = Type::combineUnionTypes(
                            $context->varsInScope[$varId],
                            $type
                        );
                    }
                }
            } elseif ($stmt->left instanceof PhpParser\Node\Expr\Assign) {
                $varId = ExpressionChecker::getVarId($stmt->left->var, $context->self);

                if ($varId && isset($context->varsInScope[$varId])) {
                    $leftInferredReconciled = Reconciler::reconcileTypes(
                        '!falsy',
                        $context->varsInScope[$varId],
                        '',
                        $statementsChecker,
                        new CodeLocation($statementsChecker->getSource(), $stmt->left),
                        $statementsChecker->getSuppressedIssues()
                    );

                    $context->varsInScope[$varId] = $leftInferredReconciled;
                }
            }

            if ($context->insideConditional) {
                $context->updateChecks($opContext);
            }

            $context->referencedVarIds = array_merge(
                $opContext->referencedVarIds,
                $context->referencedVarIds
            );

            $context->assignedVarIds = array_merge(
                $context->assignedVarIds,
                $opContext->assignedVarIds
            );

            if ($context->collectReferences) {
                $context->unreferencedVars = array_intersect_key(
                    $opContext->unreferencedVars,
                    $context->unreferencedVars
                );
            }

            $context->varsPossiblyInScope = array_merge(
                $opContext->varsPossiblyInScope,
                $context->varsPossiblyInScope
            );
        } elseif ($stmt instanceof PhpParser\Node\Expr\BinaryOp\Concat) {
            $stmt->inferredType = Type::getString();

            if (ExpressionChecker::analyze($statementsChecker, $stmt->left, $context) === false) {
                return false;
            }

            if (ExpressionChecker::analyze($statementsChecker, $stmt->right, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\BinaryOp\Coalesce) {
            $tIfContext = clone $context;

            $ifClauses = Algebra::getFormula(
                $stmt,
                $statementsChecker->getFQCLN(),
                $statementsChecker
            );

            $mixedVarIds = [];

            foreach ($context->varsInScope as $varId => $type) {
                if ($type->isMixed()) {
                    $mixedVarIds[] = $varId;
                }
            }

            foreach ($context->varsPossiblyInScope as $varId => $_) {
                if (!isset($context->varsInScope[$varId])) {
                    $mixedVarIds[] = $varId;
                }
            }

            $ifClauses = array_values(
                array_map(
                    /**
                     * @return \Psalm\Clause
                     */
                    function (\Psalm\Clause $c) use ($mixedVarIds) {
                        $keys = array_keys($c->possibilities);

                        foreach ($keys as $key) {
                            foreach ($mixedVarIds as $mixedVarId) {
                                if (preg_match('/^' . preg_quote($mixedVarId, '/') . '(\[|-)/', $key)) {
                                    return new \Psalm\Clause([], true);
                                }
                            }
                        }

                        return $c;
                    },
                    $ifClauses
                )
            );

            $ternaryClauses = Algebra::simplifyCNF(array_merge($context->clauses, $ifClauses));

            $negatedClauses = Algebra::negateFormula($ifClauses);

            $negatedIfTypes = Algebra::getTruthsFromFormula($negatedClauses);

            $reconcilableIfTypes = Algebra::getTruthsFromFormula($ternaryClauses);

            $changedVarIds = [];

            $tIfVarsInScopeReconciled = Reconciler::reconcileKeyedTypes(
                $reconcilableIfTypes,
                $tIfContext->varsInScope,
                $changedVarIds,
                [],
                $statementsChecker,
                new CodeLocation($statementsChecker->getSource(), $stmt->left),
                $statementsChecker->getSuppressedIssues()
            );

            $tIfContext->varsInScope = $tIfVarsInScopeReconciled;
            $tIfContext->insideIsset = true;

            if (ExpressionChecker::analyze($statementsChecker, $stmt->left, $tIfContext) === false) {
                return false;
            }

            $tIfContext->insideIsset = false;

            foreach ($tIfContext->varsInScope as $varId => $type) {
                if (isset($context->varsInScope[$varId])) {
                    $context->varsInScope[$varId] = Type::combineUnionTypes($context->varsInScope[$varId], $type);
                } else {
                    $context->varsInScope[$varId] = $type;
                }
            }

            $context->referencedVarIds = array_merge(
                $context->referencedVarIds,
                $tIfContext->referencedVarIds
            );

            if ($context->collectReferences) {
                $context->unreferencedVars = array_intersect_key(
                    $tIfContext->unreferencedVars,
                    $context->unreferencedVars
                );
            }

            $tElseContext = clone $context;

            if ($negatedIfTypes) {
                $tElseVarsInScopeReconciled = Reconciler::reconcileKeyedTypes(
                    $negatedIfTypes,
                    $tElseContext->varsInScope,
                    $changedVarIds,
                    [],
                    $statementsChecker,
                    new CodeLocation($statementsChecker->getSource(), $stmt->right),
                    $statementsChecker->getSuppressedIssues()
                );

                $tElseContext->varsInScope = $tElseVarsInScopeReconciled;
            }

            if (ExpressionChecker::analyze($statementsChecker, $stmt->right, $tElseContext) === false) {
                return false;
            }

            $context->referencedVarIds = array_merge(
                $context->referencedVarIds,
                $tElseContext->referencedVarIds
            );

            if ($context->collectReferences) {
                $context->unreferencedVars = array_intersect_key(
                    $tElseContext->unreferencedVars,
                    $context->unreferencedVars
                );
            }

            $lhsType = null;

            if (isset($stmt->left->inferredType)) {
                $ifReturnTypeReconciled = Reconciler::reconcileTypes(
                    '!null',
                    $stmt->left->inferredType,
                    '',
                    $statementsChecker,
                    new CodeLocation($statementsChecker->getSource(), $stmt),
                    $statementsChecker->getSuppressedIssues()
                );

                $lhsType = $ifReturnTypeReconciled;
            }

            if (!$lhsType || !isset($stmt->right->inferredType)) {
                $stmt->inferredType = Type::getMixed();
            } else {
                $stmt->inferredType = Type::combineUnionTypes($lhsType, $stmt->right->inferredType);
            }
        } else {
            if ($stmt->left instanceof PhpParser\Node\Expr\BinaryOp) {
                if (self::analyze($statementsChecker, $stmt->left, $context, ++$nesting) === false) {
                    return false;
                }
            } else {
                if (ExpressionChecker::analyze($statementsChecker, $stmt->left, $context) === false) {
                    return false;
                }
            }

            if ($stmt->right instanceof PhpParser\Node\Expr\BinaryOp) {
                if (self::analyze($statementsChecker, $stmt->right, $context, ++$nesting) === false) {
                    return false;
                }
            } else {
                if (ExpressionChecker::analyze($statementsChecker, $stmt->right, $context) === false) {
                    return false;
                }
            }
        }

        // let's do some fun type assignment
        if (isset($stmt->left->inferredType) && isset($stmt->right->inferredType)) {
            if ($stmt->left->inferredType->hasString()
                && $stmt->right->inferredType->hasString()
                && ($stmt instanceof PhpParser\Node\Expr\BinaryOp\BitwiseOr
                    || $stmt instanceof PhpParser\Node\Expr\BinaryOp\BitwiseXor
                    || $stmt instanceof PhpParser\Node\Expr\BinaryOp\BitwiseAnd
                )
            ) {
                $stmt->inferredType = Type::getString();
            } elseif ($stmt instanceof PhpParser\Node\Expr\BinaryOp\Plus
                || $stmt instanceof PhpParser\Node\Expr\BinaryOp\Minus
                || $stmt instanceof PhpParser\Node\Expr\BinaryOp\Mod
                || $stmt instanceof PhpParser\Node\Expr\BinaryOp\Mul
                || $stmt instanceof PhpParser\Node\Expr\BinaryOp\Pow
                || (($stmt->left->inferredType->hasInt() || $stmt->right->inferredType->hasInt())
                    && ($stmt instanceof PhpParser\Node\Expr\BinaryOp\BitwiseOr
                        || $stmt instanceof PhpParser\Node\Expr\BinaryOp\BitwiseXor
                        || $stmt instanceof PhpParser\Node\Expr\BinaryOp\BitwiseAnd
                        || $stmt instanceof PhpParser\Node\Expr\BinaryOp\ShiftLeft
                        || $stmt instanceof PhpParser\Node\Expr\BinaryOp\ShiftRight
                    )
                )
            ) {
                self::analyzeNonDivArithmenticOp(
                    $statementsChecker,
                    $stmt->left,
                    $stmt->right,
                    $stmt,
                    $resultType,
                    $context
                );

                if ($resultType) {
                    $stmt->inferredType = $resultType;
                }
            } elseif ($stmt instanceof PhpParser\Node\Expr\BinaryOp\BitwiseXor
                && ($stmt->left->inferredType->hasBool() || $stmt->right->inferredType->hasBool())
            ) {
                $stmt->inferredType = Type::getInt();
            } elseif ($stmt instanceof PhpParser\Node\Expr\BinaryOp\LogicalXor
                && ($stmt->left->inferredType->hasBool() || $stmt->right->inferredType->hasBool())
            ) {
                $stmt->inferredType = Type::getBool();
            } elseif ($stmt instanceof PhpParser\Node\Expr\BinaryOp\Div) {
                $projectChecker = $statementsChecker->getFileChecker()->projectChecker;

                if ($projectChecker->inferTypesFromUsage
                    && isset($stmt->left->inferredType)
                    && isset($stmt->right->inferredType)
                    && ($stmt->left->inferredType->isMixed() || $stmt->right->inferredType->isMixed())
                ) {
                    $sourceChecker = $statementsChecker->getSource();

                    if ($sourceChecker instanceof FunctionLikeChecker) {
                        $functionStorage = $sourceChecker->getFunctionLikeStorage($statementsChecker);

                        $context->inferType($stmt->left, $functionStorage, new Type\Union([new TInt, new TFloat]));
                        $context->inferType($stmt->right, $functionStorage, new Type\Union([new TInt, new TFloat]));
                    }
                }

                self::analyzeNonDivArithmenticOp(
                    $statementsChecker,
                    $stmt->left,
                    $stmt->right,
                    $stmt,
                    $resultType,
                    $context
                );

                if ($resultType) {
                    if ($resultType->hasInt()) {
                        $resultType->addType(new TFloat);
                    }

                    $stmt->inferredType = $resultType;
                }
            } elseif ($stmt instanceof PhpParser\Node\Expr\BinaryOp\Concat) {
                self::analyzeConcatOp(
                    $statementsChecker,
                    $stmt->left,
                    $stmt->right,
                    $context,
                    $resultType
                );

                if ($resultType) {
                    $stmt->inferredType = $resultType;
                }
            }
        }

        if ($stmt instanceof PhpParser\Node\Expr\BinaryOp\BooleanAnd
            || $stmt instanceof PhpParser\Node\Expr\BinaryOp\BooleanOr
            || $stmt instanceof PhpParser\Node\Expr\BinaryOp\LogicalAnd
            || $stmt instanceof PhpParser\Node\Expr\BinaryOp\LogicalOr
            || $stmt instanceof PhpParser\Node\Expr\BinaryOp\Equal
            || $stmt instanceof PhpParser\Node\Expr\BinaryOp\NotEqual
            || $stmt instanceof PhpParser\Node\Expr\BinaryOp\Identical
            || $stmt instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical
            || $stmt instanceof PhpParser\Node\Expr\BinaryOp\Greater
            || $stmt instanceof PhpParser\Node\Expr\BinaryOp\GreaterOrEqual
            || $stmt instanceof PhpParser\Node\Expr\BinaryOp\Smaller
            || $stmt instanceof PhpParser\Node\Expr\BinaryOp\SmallerOrEqual
        ) {
            $stmt->inferredType = Type::getBool();
        }

        if ($stmt instanceof PhpParser\Node\Expr\BinaryOp\Spaceship) {
            $stmt->inferredType = Type::getInt();
        }

        return null;
    }

    /**
     * @param  StatementsSource|null $statementsSource
     * @param  PhpParser\Node\Expr   $left
     * @param  PhpParser\Node\Expr   $right
     * @param  PhpParser\Node        $parent
     * @param  Type\Union|null   &$resultType
     *
     * @return void
     */
    public static function analyzeNonDivArithmenticOp(
        $statementsSource,
        PhpParser\Node\Expr $left,
        PhpParser\Node\Expr $right,
        PhpParser\Node $parent,
        Type\Union &$resultType = null,
        Context $context = null
    ) {
        $projectChecker = $statementsSource
            ? $statementsSource->getFileChecker()->projectChecker
            : null;

        $codebase = $projectChecker ? $projectChecker->codebase : null;

        $leftType = isset($left->inferredType) ? $left->inferredType : null;
        $rightType = isset($right->inferredType) ? $right->inferredType : null;
        $config = Config::getInstance();

        if ($projectChecker
            && $projectChecker->inferTypesFromUsage
            && $statementsSource
            && $context
            && $leftType
            && $rightType
            && ($leftType->isVanillaMixed() || $rightType->isVanillaMixed())
            && ($leftType->hasDefinitelyNumericType() || $rightType->hasDefinitelyNumericType())
        ) {
            $sourceChecker = $statementsSource->getSource();
            if ($sourceChecker instanceof FunctionLikeChecker
                && $statementsSource instanceof StatementsChecker
            ) {
                $functionStorage = $sourceChecker->getFunctionLikeStorage($statementsSource);

                $context->inferType($left, $functionStorage, new Type\Union([new TInt, new TFloat]));
                $context->inferType($right, $functionStorage, new Type\Union([new TInt, new TFloat]));
            }
        }

        if ($leftType && $rightType) {
            if ($leftType->isNull()) {
                if ($statementsSource && IssueBuffer::accepts(
                    new NullOperand(
                        'Left operand cannot be null',
                        new CodeLocation($statementsSource, $left)
                    ),
                    $statementsSource->getSuppressedIssues()
                )) {
                    // fall through
                }

                return;
            }

            if ($leftType->isNullable() && !$leftType->ignoreNullableIssues) {
                if ($statementsSource && IssueBuffer::accepts(
                    new PossiblyNullOperand(
                        'Left operand cannot be nullable, got ' . $leftType,
                        new CodeLocation($statementsSource, $left)
                    ),
                    $statementsSource->getSuppressedIssues()
                )) {
                    // fall through
                }
            }

            if ($rightType->isNull()) {
                if ($statementsSource && IssueBuffer::accepts(
                    new NullOperand(
                        'Right operand cannot be null',
                        new CodeLocation($statementsSource, $right)
                    ),
                    $statementsSource->getSuppressedIssues()
                )) {
                    // fall through
                }

                return;
            }

            if ($rightType->isNullable() && !$rightType->ignoreNullableIssues) {
                if ($statementsSource && IssueBuffer::accepts(
                    new PossiblyNullOperand(
                        'Right operand cannot be nullable, got ' . $rightType,
                        new CodeLocation($statementsSource, $right)
                    ),
                    $statementsSource->getSuppressedIssues()
                )) {
                    // fall through
                }
            }

            if ($leftType->isFalse()) {
                if ($statementsSource && IssueBuffer::accepts(
                    new FalseOperand(
                        'Left operand cannot be null',
                        new CodeLocation($statementsSource, $left)
                    ),
                    $statementsSource->getSuppressedIssues()
                )) {
                    // fall through
                }

                return;
            }

            if ($leftType->isFalsable() && !$leftType->ignoreFalsableIssues) {
                if ($statementsSource && IssueBuffer::accepts(
                    new PossiblyFalseOperand(
                        'Left operand cannot be falsable, got ' . $leftType,
                        new CodeLocation($statementsSource, $left)
                    ),
                    $statementsSource->getSuppressedIssues()
                )) {
                    // fall through
                }
            }

            if ($rightType->isFalse()) {
                if ($statementsSource && IssueBuffer::accepts(
                    new FalseOperand(
                        'Right operand cannot be false',
                        new CodeLocation($statementsSource, $right)
                    ),
                    $statementsSource->getSuppressedIssues()
                )) {
                    // fall through
                }

                return;
            }

            if ($rightType->isFalsable() && !$rightType->ignoreFalsableIssues) {
                if ($statementsSource && IssueBuffer::accepts(
                    new PossiblyFalseOperand(
                        'Right operand cannot be falsable, got ' . $rightType,
                        new CodeLocation($statementsSource, $right)
                    ),
                    $statementsSource->getSuppressedIssues()
                )) {
                    // fall through
                }
            }

            $invalidLeftMessages = [];
            $invalidRightMessages = [];
            $hasValidLeftOperand = false;
            $hasValidRightOperand = false;

            foreach ($leftType->getTypes() as $leftTypePart) {
                foreach ($rightType->getTypes() as $rightTypePart) {
                    $candidateResultType = self::analyzeNonDivOperands(
                        $statementsSource,
                        $codebase,
                        $config,
                        $context,
                        $left,
                        $right,
                        $parent,
                        $leftTypePart,
                        $rightTypePart,
                        $invalidLeftMessages,
                        $invalidRightMessages,
                        $hasValidLeftOperand,
                        $hasValidRightOperand,
                        $resultType
                    );

                    if ($candidateResultType) {
                        $resultType = $candidateResultType;
                        return;
                    }
                }
            }

            if ($invalidLeftMessages && $statementsSource) {
                $firstLeftMessage = $invalidLeftMessages[0];

                if ($hasValidLeftOperand) {
                    if (IssueBuffer::accepts(
                        new PossiblyInvalidOperand(
                            $firstLeftMessage,
                            new CodeLocation($statementsSource, $left)
                        ),
                        $statementsSource->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                } else {
                    if (IssueBuffer::accepts(
                        new InvalidOperand(
                            $firstLeftMessage,
                            new CodeLocation($statementsSource, $left)
                        ),
                        $statementsSource->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }
            }

            if ($invalidRightMessages && $statementsSource) {
                $firstRightMessage = $invalidRightMessages[0];

                if ($hasValidRightOperand) {
                    if (IssueBuffer::accepts(
                        new PossiblyInvalidOperand(
                            $firstRightMessage,
                            new CodeLocation($statementsSource, $right)
                        ),
                        $statementsSource->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                } else {
                    if (IssueBuffer::accepts(
                        new InvalidOperand(
                            $firstRightMessage,
                            new CodeLocation($statementsSource, $right)
                        ),
                        $statementsSource->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }
            }
        }
    }

    /**
     * @param  StatementsSource|null $statementsSource
     * @param  \Psalm\Codebase|null  $codebase
     * @param  Context|null $context
     * @param  string[]        &$invalidLeftMessages
     * @param  string[]        &$invalidRightMessages
     * @param  bool            &$hasValidLeftOperand
     * @param  bool            &$hasValidRightOperand
     *
     * @return Type\Union|null
     */
    public static function analyzeNonDivOperands(
        $statementsSource,
        $codebase,
        Config $config,
        $context,
        PhpParser\Node\Expr $left,
        PhpParser\Node\Expr $right,
        PhpParser\Node $parent,
        Type\Atomic $leftTypePart,
        Type\Atomic $rightTypePart,
        array &$invalidLeftMessages,
        array &$invalidRightMessages,
        &$hasValidLeftOperand,
        &$hasValidRightOperand,
        Type\Union &$resultType = null
    ) {
        if ($leftTypePart instanceof TNull || $rightTypePart instanceof TNull) {
            // null case is handled above
            return;
        }

        if ($leftTypePart instanceof TFalse || $rightTypePart instanceof TFalse) {
            // null case is handled above
            return;
        }

        if ($leftTypePart instanceof TMixed
            || $rightTypePart instanceof TMixed
            || $leftTypePart instanceof TGenericParam
            || $rightTypePart instanceof TGenericParam
        ) {
            if ($statementsSource && $codebase) {
                $codebase->analyzer->incrementMixedCount($statementsSource->getFilePath());
            }

            if ($leftTypePart instanceof TMixed || $leftTypePart instanceof TGenericParam) {
                if ($statementsSource && IssueBuffer::accepts(
                    new MixedOperand(
                        'Left operand cannot be mixed',
                        new CodeLocation($statementsSource, $left)
                    ),
                    $statementsSource->getSuppressedIssues()
                )) {
                    // fall through
                }
            } else {
                if ($statementsSource && IssueBuffer::accepts(
                    new MixedOperand(
                        'Right operand cannot be mixed',
                        new CodeLocation($statementsSource, $right)
                    ),
                    $statementsSource->getSuppressedIssues()
                )) {
                    // fall through
                }
            }

            if ($leftTypePart instanceof TMixed
                && $leftTypePart->fromIsset
                && $parent instanceof PhpParser\Node\Expr\AssignOp\Plus
                && !$rightTypePart instanceof TMixed
            ) {
                $resultTypeMember = new Type\Union([$rightTypePart]);

                if (!$resultType) {
                    $resultType = $resultTypeMember;
                } else {
                    $resultType = Type::combineUnionTypes($resultTypeMember, $resultType);
                }

                return;
            }

            $fromIsset = (!($leftTypePart instanceof TMixed) || $leftTypePart->fromIsset)
                && (!($rightTypePart instanceof TMixed) || $rightTypePart->fromIsset);

            $resultType = Type::getMixed($fromIsset);

            return $resultType;
        }

        if ($statementsSource && $codebase) {
            $codebase->analyzer->incrementNonMixedCount($statementsSource->getFilePath());
        }

        if ($leftTypePart instanceof TArray
            || $rightTypePart instanceof TArray
            || $leftTypePart instanceof ObjectLike
            || $rightTypePart instanceof ObjectLike
        ) {
            if ((!$rightTypePart instanceof TArray && !$rightTypePart instanceof ObjectLike)
                || (!$leftTypePart instanceof TArray && !$leftTypePart instanceof ObjectLike)
            ) {
                if (!$leftTypePart instanceof TArray && !$leftTypePart instanceof ObjectLike) {
                    $invalidLeftMessages[] = 'Cannot add an array to a non-array ' . $leftTypePart;
                } else {
                    $invalidRightMessages[] = 'Cannot add an array to a non-array ' . $rightTypePart;
                }

                if ($leftTypePart instanceof TArray || $leftTypePart instanceof ObjectLike) {
                    $hasValidLeftOperand = true;
                } elseif ($rightTypePart instanceof TArray || $rightTypePart instanceof ObjectLike) {
                    $hasValidRightOperand = true;
                }

                $resultType = Type::getArray();

                return;
            }

            $hasValidRightOperand = true;
            $hasValidLeftOperand = true;

            if ($leftTypePart instanceof ObjectLike && $rightTypePart instanceof ObjectLike) {
                $properties = $leftTypePart->properties + $rightTypePart->properties;

                $resultTypeMember = new Type\Union([new ObjectLike($properties)]);
            } else {
                $resultTypeMember = TypeCombination::combineTypes([$leftTypePart, $rightTypePart]);
            }

            if (!$resultType) {
                $resultType = $resultTypeMember;
            } else {
                $resultType = Type::combineUnionTypes($resultTypeMember, $resultType);
            }

            if ($left instanceof PhpParser\Node\Expr\ArrayDimFetch
                && $context
                && $statementsSource instanceof StatementsChecker
            ) {
                ArrayAssignmentChecker::updateArrayType(
                    $statementsSource,
                    $left,
                    $resultType,
                    $context
                );
            }

            return;
        }

        if (($leftTypePart instanceof TNamedObject && strtolower($leftTypePart->value) === 'gmp')
            || ($rightTypePart instanceof TNamedObject && strtolower($rightTypePart->value) === 'gmp')
        ) {
            if ((($leftTypePart instanceof TNamedObject
                        && strtolower($leftTypePart->value) === 'gmp')
                    && (($rightTypePart instanceof TNamedObject
                            && strtolower($rightTypePart->value) === 'gmp')
                        || ($rightTypePart->isNumericType() || $rightTypePart instanceof TMixed)))
                || (($rightTypePart instanceof TNamedObject
                        && strtolower($rightTypePart->value) === 'gmp')
                    && (($leftTypePart instanceof TNamedObject
                            && strtolower($leftTypePart->value) === 'gmp')
                        || ($leftTypePart->isNumericType() || $leftTypePart instanceof TMixed)))
            ) {
                if (!$resultType) {
                    $resultType = new Type\Union([new TNamedObject('GMP')]);
                } else {
                    $resultType = Type::combineUnionTypes(
                        new Type\Union([new TNamedObject('GMP')]),
                        $resultType
                    );
                }
            } else {
                if ($statementsSource && IssueBuffer::accepts(
                    new InvalidOperand(
                        'Cannot add GMP to non-numeric type',
                        new CodeLocation($statementsSource, $parent)
                    ),
                    $statementsSource->getSuppressedIssues()
                )) {
                    // fall through
                }
            }

            return;
        }

        if ($leftTypePart->isNumericType() || $rightTypePart->isNumericType()) {
            if (($leftTypePart instanceof TNumeric || $rightTypePart instanceof TNumeric)
                && ($leftTypePart->isNumericType() && $rightTypePart->isNumericType())
            ) {
                if (!$resultType) {
                    $resultType = Type::getNumeric();
                } else {
                    $resultType = Type::combineUnionTypes(Type::getNumeric(), $resultType);
                }

                $hasValidRightOperand = true;
                $hasValidLeftOperand = true;

                return;
            }

            if ($leftTypePart instanceof TInt && $rightTypePart instanceof TInt) {
                if (!$resultType) {
                    $resultType = Type::getInt(true);
                } else {
                    $resultType = Type::combineUnionTypes(Type::getInt(true), $resultType);
                }

                $hasValidRightOperand = true;
                $hasValidLeftOperand = true;

                return;
            }

            if ($leftTypePart instanceof TFloat && $rightTypePart instanceof TFloat) {
                if (!$resultType) {
                    $resultType = Type::getFloat();
                } else {
                    $resultType = Type::combineUnionTypes(Type::getFloat(), $resultType);
                }

                $hasValidRightOperand = true;
                $hasValidLeftOperand = true;

                return;
            }

            if (($leftTypePart instanceof TFloat && $rightTypePart instanceof TInt)
                || ($leftTypePart instanceof TInt && $rightTypePart instanceof TFloat)
            ) {
                if ($config->strictBinaryOperands) {
                    if ($statementsSource && IssueBuffer::accepts(
                        new InvalidOperand(
                            'Cannot add ints to floats',
                            new CodeLocation($statementsSource, $parent)
                        ),
                        $statementsSource->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }

                if (!$resultType) {
                    $resultType = Type::getFloat();
                } else {
                    $resultType = Type::combineUnionTypes(Type::getFloat(), $resultType);
                }

                $hasValidRightOperand = true;
                $hasValidLeftOperand = true;

                return;
            }

            if ($leftTypePart->isNumericType() && $rightTypePart->isNumericType()) {
                if ($config->strictBinaryOperands) {
                    if ($statementsSource && IssueBuffer::accepts(
                        new InvalidOperand(
                            'Cannot add numeric types together, please cast explicitly',
                            new CodeLocation($statementsSource, $parent)
                        ),
                        $statementsSource->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }

                if (!$resultType) {
                    $resultType = Type::getFloat();
                } else {
                    $resultType = Type::combineUnionTypes(Type::getFloat(), $resultType);
                }

                $hasValidRightOperand = true;
                $hasValidLeftOperand = true;

                return;
            }

            if (!$leftTypePart->isNumericType()) {
                $invalidLeftMessages[] = 'Cannot perform a numeric operation with a non-numeric type '
                    . $leftTypePart;
                $hasValidRightOperand = true;
            } else {
                $invalidRightMessages[] = 'Cannot perform a numeric operation with a non-numeric type '
                    . $rightTypePart;
                $hasValidLeftOperand = true;
            }
        } else {
            $invalidLeftMessages[] =
                'Cannot perform a numeric operation with non-numeric types ' . $leftTypePart
                    . ' and ' . $rightTypePart;
        }
    }

    /**
     * @param  StatementsChecker     $statementsChecker
     * @param  PhpParser\Node\Expr   $left
     * @param  PhpParser\Node\Expr   $right
     * @param  Type\Union|null       &$resultType
     *
     * @return void
     */
    public static function analyzeConcatOp(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr $left,
        PhpParser\Node\Expr $right,
        Context $context,
        Type\Union &$resultType = null
    ) {
        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;

        $leftType = isset($left->inferredType) ? $left->inferredType : null;
        $rightType = isset($right->inferredType) ? $right->inferredType : null;
        $config = Config::getInstance();

        if ($projectChecker->inferTypesFromUsage
            && $leftType
            && $rightType
            && ($leftType->isMixed() || $rightType->isMixed())
        ) {
            $sourceChecker = $statementsChecker->getSource();

            if ($sourceChecker instanceof FunctionLikeChecker) {
                $functionStorage = $sourceChecker->getFunctionLikeStorage($statementsChecker);

                $context->inferType($left, $functionStorage, Type::getString());
                $context->inferType($right, $functionStorage, Type::getString());
            }
        }

        if ($leftType && $rightType) {
            $resultType = Type::getString();

            if ($leftType->isMixed() || $rightType->isMixed()) {
                $codebase->analyzer->incrementMixedCount($statementsChecker->getFilePath());

                if ($leftType->isMixed()) {
                    if (IssueBuffer::accepts(
                        new MixedOperand(
                            'Left operand cannot be mixed',
                            new CodeLocation($statementsChecker->getSource(), $left)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                } else {
                    if (IssueBuffer::accepts(
                        new MixedOperand(
                            'Right operand cannot be mixed',
                            new CodeLocation($statementsChecker->getSource(), $right)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }

                return;
            }

            $codebase->analyzer->incrementNonMixedCount($statementsChecker->getFilePath());

            if ($leftType->isNull()) {
                if (IssueBuffer::accepts(
                    new NullOperand(
                        'Cannot concatenate with a ' . $leftType,
                        new CodeLocation($statementsChecker->getSource(), $left)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    // fall through
                }

                return;
            }

            if ($rightType->isNull()) {
                if (IssueBuffer::accepts(
                    new NullOperand(
                        'Cannot concatenate with a ' . $rightType,
                        new CodeLocation($statementsChecker->getSource(), $right)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    // fall through
                }

                return;
            }

            if ($leftType->isFalse()) {
                if (IssueBuffer::accepts(
                    new FalseOperand(
                        'Cannot concatenate with a ' . $leftType,
                        new CodeLocation($statementsChecker->getSource(), $left)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    // fall through
                }

                return;
            }

            if ($rightType->isFalse()) {
                if (IssueBuffer::accepts(
                    new FalseOperand(
                        'Cannot concatenate with a ' . $rightType,
                        new CodeLocation($statementsChecker->getSource(), $right)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    // fall through
                }

                return;
            }

            if ($leftType->isNullable() && !$leftType->ignoreNullableIssues) {
                if (IssueBuffer::accepts(
                    new PossiblyNullOperand(
                        'Cannot concatenate with a possibly null ' . $leftType,
                        new CodeLocation($statementsChecker->getSource(), $left)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    // fall through
                }
            }

            if ($rightType->isNullable() && !$rightType->ignoreNullableIssues) {
                if (IssueBuffer::accepts(
                    new PossiblyNullOperand(
                        'Cannot concatenate with a possibly null ' . $rightType,
                        new CodeLocation($statementsChecker->getSource(), $right)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    // fall through
                }
            }

            if ($leftType->isFalsable() && !$leftType->ignoreFalsableIssues) {
                if (IssueBuffer::accepts(
                    new PossiblyFalseOperand(
                        'Cannot concatenate with a possibly false ' . $leftType,
                        new CodeLocation($statementsChecker->getSource(), $left)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    // fall through
                }
            }

            if ($rightType->isFalsable() && !$rightType->ignoreFalsableIssues) {
                if (IssueBuffer::accepts(
                    new PossiblyFalseOperand(
                        'Cannot concatenate with a possibly false ' . $rightType,
                        new CodeLocation($statementsChecker->getSource(), $right)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    // fall through
                }
            }

            $projectChecker = $statementsChecker->getFileChecker()->projectChecker;

            $leftTypeMatch = true;
            $rightTypeMatch = true;

            $leftHasScalarMatch = false;
            $rightHasScalarMatch = false;

            $hasValidLeftOperand = false;
            $hasValidRightOperand = false;

            foreach ($leftType->getTypes() as $leftTypePart) {
                if ($leftTypePart instanceof Type\Atomic\TGenericParam) {
                    if (IssueBuffer::accepts(
                        new MixedOperand(
                            'Left operand cannot be mixed',
                            new CodeLocation($statementsChecker->getSource(), $left)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }

                    return;
                }

                if ($leftTypePart instanceof Type\Atomic\TNull || $leftTypePart instanceof Type\Atomic\TFalse) {
                    continue;
                }

                $leftTypePartMatch = TypeChecker::isAtomicContainedBy(
                    $projectChecker->codebase,
                    $leftTypePart,
                    new Type\Atomic\TString,
                    $leftHasScalarMatch,
                    $leftTypeCoerced,
                    $leftTypeCoercedFromMixed,
                    $leftToStringCast
                );

                $leftTypeMatch = $leftTypeMatch && $leftTypePartMatch;

                $hasValidLeftOperand = $hasValidLeftOperand || $leftTypePartMatch;

                if ($leftToStringCast && $config->strictBinaryOperands) {
                    if (IssueBuffer::accepts(
                        new ImplicitToStringCast(
                            'Left side of concat op expects string, '
                                . '\'' . $leftType . '\' provided with a __toString method',
                            new CodeLocation($statementsChecker->getSource(), $left)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }
            }

            foreach ($rightType->getTypes() as $rightTypePart) {
                if ($rightTypePart instanceof Type\Atomic\TGenericParam) {
                    if (IssueBuffer::accepts(
                        new MixedOperand(
                            'Right operand cannot be a template param',
                            new CodeLocation($statementsChecker->getSource(), $right)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }

                    return;
                }

                if ($rightTypePart instanceof Type\Atomic\TNull || $rightTypePart instanceof Type\Atomic\TFalse) {
                    continue;
                }

                $rightTypePartMatch = TypeChecker::isAtomicContainedBy(
                    $projectChecker->codebase,
                    $rightTypePart,
                    new Type\Atomic\TString,
                    $rightHasScalarMatch,
                    $rightTypeCoerced,
                    $rightTypeCoercedFromMixed,
                    $rightToStringCast
                );

                $rightTypeMatch = $rightTypeMatch && $rightTypePartMatch;

                $hasValidRightOperand = $hasValidRightOperand || $rightTypePartMatch;

                if ($rightToStringCast && $config->strictBinaryOperands) {
                    if (IssueBuffer::accepts(
                        new ImplicitToStringCast(
                            'Right side of concat op expects string, '
                                . '\'' . $rightType . '\' provided with a __toString method',
                            new CodeLocation($statementsChecker->getSource(), $right)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }
            }

            if (!$leftTypeMatch && (!$leftHasScalarMatch || $config->strictBinaryOperands)) {
                if ($hasValidLeftOperand) {
                    if (IssueBuffer::accepts(
                        new PossiblyInvalidOperand(
                            'Cannot concatenate with a ' . $leftType,
                            new CodeLocation($statementsChecker->getSource(), $left)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                } else {
                    if (IssueBuffer::accepts(
                        new InvalidOperand(
                            'Cannot concatenate with a ' . $leftType,
                            new CodeLocation($statementsChecker->getSource(), $left)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }
            }

            if (!$rightTypeMatch && (!$rightHasScalarMatch || $config->strictBinaryOperands)) {
                if ($hasValidRightOperand) {
                    if (IssueBuffer::accepts(
                        new PossiblyInvalidOperand(
                            'Cannot concatenate with a ' . $rightType,
                            new CodeLocation($statementsChecker->getSource(), $right)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                } else {
                    if (IssueBuffer::accepts(
                        new InvalidOperand(
                            'Cannot concatenate with a ' . $rightType,
                            new CodeLocation($statementsChecker->getSource(), $right)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }
            }
        }
        // When concatenating two known string literals (with only one possibility),
        // put the concatenated string into $resultType
        if ($leftType && $rightType && $leftType->isSingleStringLiteral() && $rightType->isSingleStringLiteral()) {
            $literal = $leftType->getSingleStringLiteral()->value . $rightType->getSingleStringLiteral()->value;
            if (strlen($literal) <= 10000) {
                // Limit these to 10000 bytes to avoid extremely large union types from repeated concatenations, etc
                $resultType = new Union([new TLiteralString($literal)]);
            }
        }
    }
}
