<?php
namespace Psalm\Checker\FunctionLike;

use PhpParser;
use Psalm\Type;
use Psalm\Type\Atomic;

/**
 * A class for analysing a given method call's effects in relation to $this/self and also looking at return types
 */
class ReturnTypeCollector
{
    /**
     * Gets the return types from a list of statements
     *
     * @param  array<PhpParser\Node>     $stmts
     * @param  array<int,Type\Atomic>    $yieldTypes
     * @param  bool                      $ignoreNullableIssues
     * @param  bool                      $ignoreFalsableIssues
     * @param  bool                      $collapseTypes
     *
     * @return array<int,Type\Atomic>    a list of return types
     */
    public static function getReturnTypes(
        array $stmts,
        array &$yieldTypes,
        &$ignoreNullableIssues = false,
        &$ignoreFalsableIssues = false,
        $collapseTypes = false
    ) {
        $returnTypes = [];

        foreach ($stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\Return_) {
                if ($stmt->expr instanceof PhpParser\Node\Expr\Yield_ ||
                    $stmt->expr instanceof PhpParser\Node\Expr\YieldFrom) {
                    $yieldTypes = array_merge($yieldTypes, self::getYieldTypeFromExpression($stmt->expr));
                }

                if (!$stmt->expr) {
                    $returnTypes[] = new Atomic\TVoid();
                } elseif (isset($stmt->inferredType)) {
                    $returnTypes = array_merge(array_values($stmt->inferredType->getTypes()), $returnTypes);

                    if ($stmt->inferredType->ignoreNullableIssues) {
                        $ignoreNullableIssues = true;
                    }

                    if ($stmt->inferredType->ignoreFalsableIssues) {
                        $ignoreFalsableIssues = true;
                    }
                } else {
                    $returnTypes[] = new Atomic\TMixed();
                }

                break;
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Throw_
                || $stmt instanceof PhpParser\Node\Stmt\Break_
                || $stmt instanceof PhpParser\Node\Stmt\Continue_
            ) {
                break;
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Expression
                && ($stmt->expr instanceof PhpParser\Node\Expr\Yield_
                    || $stmt->expr instanceof PhpParser\Node\Expr\YieldFrom)
            ) {
                $yieldTypes = array_merge($yieldTypes, self::getYieldTypeFromExpression($stmt->expr));
            } elseif ($stmt instanceof PhpParser\Node\Expr\Yield_
                || $stmt instanceof PhpParser\Node\Expr\YieldFrom
            ) {
                $yieldTypes = array_merge($yieldTypes, self::getYieldTypeFromExpression($stmt));
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Expression
                && $stmt->expr instanceof PhpParser\Node\Expr\Assign
            ) {
                $returnTypes = array_merge(
                    $returnTypes,
                    self::getReturnTypes(
                        [$stmt->expr->expr],
                        $yieldTypes,
                        $ignoreNullableIssues,
                        $ignoreFalsableIssues
                    )
                );
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Expression
                && ($stmt->expr instanceof PhpParser\Node\Expr\MethodCall
                    || $stmt->expr instanceof PhpParser\Node\Expr\FuncCall
                    || $stmt->expr instanceof PhpParser\Node\Expr\StaticCall
                )
            ) {
                foreach ($stmt->expr->args as $arg) {
                    $yieldTypes = array_merge($yieldTypes, self::getYieldTypeFromExpression($arg->value));
                }
            } elseif ($stmt instanceof PhpParser\Node\Stmt\If_) {
                $returnTypes = array_merge(
                    $returnTypes,
                    self::getReturnTypes(
                        $stmt->stmts,
                        $yieldTypes,
                        $ignoreNullableIssues,
                        $ignoreFalsableIssues
                    )
                );

                foreach ($stmt->elseifs as $elseif) {
                    $returnTypes = array_merge(
                        $returnTypes,
                        self::getReturnTypes(
                            $elseif->stmts,
                            $yieldTypes,
                            $ignoreNullableIssues,
                            $ignoreFalsableIssues
                        )
                    );
                }

                if ($stmt->else) {
                    $returnTypes = array_merge(
                        $returnTypes,
                        self::getReturnTypes(
                            $stmt->else->stmts,
                            $yieldTypes,
                            $ignoreNullableIssues,
                            $ignoreFalsableIssues
                        )
                    );
                }
            } elseif ($stmt instanceof PhpParser\Node\Stmt\TryCatch) {
                $returnTypes = array_merge(
                    $returnTypes,
                    self::getReturnTypes(
                        $stmt->stmts,
                        $yieldTypes,
                        $ignoreNullableIssues,
                        $ignoreFalsableIssues
                    )
                );

                foreach ($stmt->catches as $catch) {
                    $returnTypes = array_merge(
                        $returnTypes,
                        self::getReturnTypes(
                            $catch->stmts,
                            $yieldTypes,
                            $ignoreNullableIssues,
                            $ignoreFalsableIssues
                        )
                    );
                }

                if ($stmt->finally) {
                    $returnTypes = array_merge(
                        $returnTypes,
                        self::getReturnTypes(
                            $stmt->finally->stmts,
                            $yieldTypes,
                            $ignoreNullableIssues,
                            $ignoreFalsableIssues
                        )
                    );
                }
            } elseif ($stmt instanceof PhpParser\Node\Stmt\For_) {
                $returnTypes = array_merge(
                    $returnTypes,
                    self::getReturnTypes(
                        $stmt->stmts,
                        $yieldTypes,
                        $ignoreNullableIssues,
                        $ignoreFalsableIssues
                    )
                );
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Foreach_) {
                $returnTypes = array_merge(
                    $returnTypes,
                    self::getReturnTypes(
                        $stmt->stmts,
                        $yieldTypes,
                        $ignoreNullableIssues,
                        $ignoreFalsableIssues
                    )
                );
            } elseif ($stmt instanceof PhpParser\Node\Stmt\While_) {
                $returnTypes = array_merge(
                    $returnTypes,
                    self::getReturnTypes(
                        $stmt->stmts,
                        $yieldTypes,
                        $ignoreNullableIssues,
                        $ignoreFalsableIssues
                    )
                );
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Do_) {
                $returnTypes = array_merge(
                    $returnTypes,
                    self::getReturnTypes(
                        $stmt->stmts,
                        $yieldTypes,
                        $ignoreNullableIssues,
                        $ignoreFalsableIssues
                    )
                );
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Switch_) {
                foreach ($stmt->cases as $case) {
                    $returnTypes = array_merge(
                        $returnTypes,
                        self::getReturnTypes(
                            $case->stmts,
                            $yieldTypes,
                            $ignoreNullableIssues,
                            $ignoreFalsableIssues
                        )
                    );
                }
            }
        }

        // if we're at the top level and we're not ending in a return, make sure to add possible null
        if ($collapseTypes) {
            // if it's a generator, boil everything down to a single generator return type
            if ($yieldTypes) {
                $keyType = null;
                $valueType = null;

                foreach ($yieldTypes as $type) {
                    if ($type instanceof Type\Atomic\TArray || $type instanceof Type\Atomic\TGenericObject) {
                        switch (count($type->typeParams)) {
                            case 1:
                                $keyTypeParam = Type::getMixed();
                                $valueTypeParam = $type->typeParams[1];
                                break;

                            default:
                                $keyTypeParam = $type->typeParams[0];
                                $valueTypeParam = $type->typeParams[1];
                        }

                        if (!$keyType) {
                            $keyType = clone $keyTypeParam;
                        } else {
                            $keyType = Type::combineUnionTypes($keyTypeParam, $keyType);
                        }

                        if (!$valueType) {
                            $valueType = clone $valueTypeParam;
                        } else {
                            $valueType = Type::combineUnionTypes($valueTypeParam, $valueType);
                        }
                    }
                }

                $yieldTypes = [
                    new Atomic\TGenericObject(
                        'Generator',
                        [
                            $keyType ?: Type::getMixed(),
                            $valueType ?: Type::getMixed(),
                            Type::getMixed(),
                            $returnTypes ? new Type\Union($returnTypes) : Type::getVoid()
                        ]
                    ),
                ];
            }
        }

        return $returnTypes;
    }

    /**
     * @param   PhpParser\Node\Expr $stmt
     *
     * @return  array<int, Atomic>
     */
    protected static function getYieldTypeFromExpression(PhpParser\Node\Expr $stmt)
    {
        if ($stmt instanceof PhpParser\Node\Expr\Yield_) {
            $keyType = null;

            if (isset($stmt->key->inferredType)) {
                $keyType = $stmt->key->inferredType;
            }

            if (isset($stmt->inferredType)) {
                $generatorType = new Atomic\TGenericObject(
                    'Generator',
                    [
                        $keyType ?: Type::getInt(),
                        $stmt->inferredType,
                    ]
                );

                return [$generatorType];
            }

            return [new Atomic\TMixed()];
        } elseif ($stmt instanceof PhpParser\Node\Expr\YieldFrom) {
            if (isset($stmt->expr->inferredType)) {
                return array_values($stmt->expr->inferredType->getTypes());
            }

            return [new Atomic\TMixed()];
        }

        return [];
    }
}
