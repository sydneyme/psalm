<?php
namespace Psalm\Checker\Statements\Expression;

use PhpParser;
use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\TypeChecker;
use Psalm\CodeLocation;
use Psalm\FileSource;
use Psalm\Issue\DocblockTypeContradiction;
use Psalm\Issue\RedundantCondition;
use Psalm\Issue\RedundantConditionGivenDocblockType;
use Psalm\Issue\TypeDoesNotContainNull;
use Psalm\Issue\TypeDoesNotContainType;
use Psalm\Issue\UnevaluatedCode;
use Psalm\IssueBuffer;
use Psalm\StatementsSource;
use Psalm\Type;

class AssertionFinder
{
    const ASSIGNMENT_TO_RIGHT = 1;
    const ASSIGNMENT_TO_LEFT = -1;

    /**
     * Gets all the type assertions in a conditional
     *
     * @param string|null $thisClassName
     *
     * @return void
     */
    public static function scrapeAssertions(
        PhpParser\Node\Expr $conditional,
        $thisClassName,
        FileSource $source
    ) {
        if (isset($conditional->assertions)) {
            return;
        }

        $ifTypes = [];

        if ($conditional instanceof PhpParser\Node\Expr\Instanceof_) {
            $instanceofType = self::getInstanceOfTypes($conditional, $thisClassName, $source);

            if ($instanceofType) {
                $varName = ExpressionChecker::getArrayVarId(
                    $conditional->expr,
                    $thisClassName,
                    $source
                );

                if ($varName) {
                    $ifTypes[$varName] = [[$instanceofType]];
                }
            }

            $conditional->assertions = $ifTypes;
            return;
        }

        $varName = ExpressionChecker::getArrayVarId(
            $conditional,
            $thisClassName,
            $source
        );

        if ($varName) {
            $ifTypes[$varName] = [['!falsy']];

            $conditional->assertions = $ifTypes;
            return;
        }

        if ($conditional instanceof PhpParser\Node\Expr\Assign) {
            $varName = ExpressionChecker::getArrayVarId(
                $conditional->var,
                $thisClassName,
                $source
            );

            if ($varName) {
                $ifTypes[$varName] = [['!falsy']];
            }

            $conditional->assertions = $ifTypes;
            return;
        }

        if ($conditional instanceof PhpParser\Node\Expr\BooleanNot) {
            self::scrapeAssertions(
                $conditional->expr,
                $thisClassName,
                $source
            );

            if (!isset($conditional->expr->assertions)) {
                throw new \UnexpectedValueException('Assertions should be set');
            }

            $conditional->assertions = \Psalm\Type\Algebra::negateTypes($conditional->expr->assertions);
            return;
        }

        if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical ||
            $conditional instanceof PhpParser\Node\Expr\BinaryOp\Equal
        ) {
            self::scrapeEqualityAssertions($conditional, $thisClassName, $source);
            return;
        }

        if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical ||
            $conditional instanceof PhpParser\Node\Expr\BinaryOp\NotEqual
        ) {
            self::scrapeInequalityAssertions($conditional, $thisClassName, $source);
            return;
        }

        if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Greater) {
            $typedValuePosition = self::hasTypedValueComparison($conditional);

            if ($typedValuePosition) {
                if ($typedValuePosition === self::ASSIGNMENT_TO_RIGHT) {
                    /** @var PhpParser\Node\Expr $conditional->right */
                    $varName = ExpressionChecker::getArrayVarId(
                        $conditional->left,
                        $thisClassName,
                        $source
                    );
                } elseif ($typedValuePosition === self::ASSIGNMENT_TO_LEFT) {
                    $varName = null;
                } else {
                    throw new \UnexpectedValueException('$typedValuePosition value');
                }

                if ($varName) {
                    $ifTypes[$varName] = [['^isset']];
                }

                $conditional->assertions = $ifTypes;
                return;
            }

            $conditional->assertions = [];
            return;
        }

        if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Smaller) {
            $typedValuePosition = self::hasTypedValueComparison($conditional);

            if ($typedValuePosition) {
                if ($typedValuePosition === self::ASSIGNMENT_TO_RIGHT) {
                    $varName = null;
                } elseif ($typedValuePosition === self::ASSIGNMENT_TO_LEFT) {
                    /** @var PhpParser\Node\Expr $conditional->left */
                    $varName = ExpressionChecker::getArrayVarId(
                        $conditional->right,
                        $thisClassName,
                        $source
                    );
                } else {
                    throw new \UnexpectedValueException('$typedValuePosition value');
                }

                if ($varName) {
                    $ifTypes[$varName] = [['^isset']];
                }

                $conditional->assertions = $ifTypes;
                return;
            }

            $conditional->assertions = [];
            return;
        }

        if ($conditional instanceof PhpParser\Node\Expr\FuncCall) {
            $conditional->assertions = self::processFunctionCall($conditional, $thisClassName, $source, false);
            return;
        }

        if ($conditional instanceof PhpParser\Node\Expr\MethodCall) {
            $conditional->assertions = self::processCustomAssertion($conditional, $thisClassName, $source, false);
            return;
        }

        if ($conditional instanceof PhpParser\Node\Expr\Empty_) {
            $varName = ExpressionChecker::getArrayVarId(
                $conditional->expr,
                $thisClassName,
                $source
            );

            if ($varName) {
                $ifTypes[$varName] = [['empty']];
            } else {
                // look for any variables we *can* use for an isset assertion
                $arrayRoot = $conditional->expr;

                while ($arrayRoot instanceof PhpParser\Node\Expr\ArrayDimFetch && !$varName) {
                    $arrayRoot = $arrayRoot->var;

                    $varName = ExpressionChecker::getArrayVarId(
                        $arrayRoot,
                        $thisClassName,
                        $source
                    );
                }

                if ($varName) {
                    $ifTypes[$varName] = [['^empty']];
                }
            }

            $conditional->assertions = $ifTypes;
            return;
        }

        if ($conditional instanceof PhpParser\Node\Expr\Isset_) {
            foreach ($conditional->vars as $issetVar) {
                $varName = ExpressionChecker::getArrayVarId(
                    $issetVar,
                    $thisClassName,
                    $source
                );

                if ($varName) {
                    $ifTypes[$varName] = [['isset']];
                } else {
                    // look for any variables we *can* use for an isset assertion
                    $arrayRoot = $issetVar;

                    while ($arrayRoot instanceof PhpParser\Node\Expr\ArrayDimFetch && !$varName) {
                        $arrayRoot = $arrayRoot->var;

                        $varName = ExpressionChecker::getArrayVarId(
                            $arrayRoot,
                            $thisClassName,
                            $source
                        );
                    }

                    if ($varName) {
                        $ifTypes[$varName] = [['^isset']];
                    }
                }
            }

            $conditional->assertions = $ifTypes;
            return;
        }

        if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Coalesce) {
            $varName = ExpressionChecker::getArrayVarId(
                $conditional->left,
                $thisClassName,
                $source
            );

            if ($varName) {
                $ifTypes[$varName] = [['isset']];
            } else {
                // look for any variables we *can* use for an isset assertion
                $arrayRoot = $conditional->left;

                while ($arrayRoot instanceof PhpParser\Node\Expr\ArrayDimFetch && !$varName) {
                    $arrayRoot = $arrayRoot->var;

                    $varName = ExpressionChecker::getArrayVarId(
                        $arrayRoot,
                        $thisClassName,
                        $source
                    );
                }

                if ($varName) {
                    $ifTypes[$varName] = [['^isset']];
                }
            }

            $conditional->assertions = $ifTypes;
            return;
        }

        $conditional->assertions = [];
        return;
    }

    /**
     * @param PhpParser\Node\Expr\BinaryOp\Identical|PhpParser\Node\Expr\BinaryOp\Equal $conditional
     * @param string|null $thisClassName
     *
     * @return void
     */
    private static function scrapeEqualityAssertions(
        PhpParser\Node\Expr\BinaryOp $conditional,
        $thisClassName,
        FileSource $source
    ) {
        $projectChecker = $source instanceof StatementsSource
            ? $source->getFileChecker()->projectChecker
            : null;

        $ifTypes = [];

        $nullPosition = self::hasNullVariable($conditional);
        $falsePosition = self::hasFalseVariable($conditional);
        $truePosition = self::hasTrueVariable($conditional);
        $gettypePosition = self::hasGetTypeCheck($conditional);
        $getclassPosition = self::hasGetClassCheck($conditional);
        $typedValuePosition = self::hasTypedValueComparison($conditional);

        if ($nullPosition !== null) {
            if ($nullPosition === self::ASSIGNMENT_TO_RIGHT) {
                $baseConditional = $conditional->left;
            } elseif ($nullPosition === self::ASSIGNMENT_TO_LEFT) {
                $baseConditional = $conditional->right;
            } else {
                throw new \UnexpectedValueException('$nullPosition value');
            }

            $varName = ExpressionChecker::getArrayVarId(
                $baseConditional,
                $thisClassName,
                $source
            );

            $varType = isset($baseConditional->inferredType) ? $baseConditional->inferredType : null;

            if ($varName) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical) {
                    $ifTypes[$varName] = [['null']];
                } else {
                    $ifTypes[$varName] = [['falsy']];
                }
            }

            if ($varType
                && $conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical
                && $source instanceof StatementsSource
                && $projectChecker
            ) {
                $nullType = Type::getNull();

                if (!TypeChecker::isContainedBy(
                    $projectChecker->codebase,
                    $varType,
                    $nullType
                ) && !TypeChecker::isContainedBy(
                    $projectChecker->codebase,
                    $nullType,
                    $varType
                )) {
                    if ($varType->fromDocblock) {
                        if (IssueBuffer::accepts(
                            new DocblockTypeContradiction(
                                $varType . ' does not contain null',
                                new CodeLocation($source, $conditional)
                            ),
                            $source->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    } else {
                        if (IssueBuffer::accepts(
                            new TypeDoesNotContainNull(
                                $varType . ' does not contain null',
                                new CodeLocation($source, $conditional)
                            ),
                            $source->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    }
                }
            }

            $conditional->assertions = $ifTypes;
            return;
        }

        if ($truePosition) {
            if ($truePosition === self::ASSIGNMENT_TO_RIGHT) {
                $baseConditional = $conditional->left;
            } elseif ($truePosition === self::ASSIGNMENT_TO_LEFT) {
                $baseConditional = $conditional->right;
            } else {
                throw new \UnexpectedValueException('Unrecognised position');
            }

            if ($baseConditional instanceof PhpParser\Node\Expr\FuncCall) {
                $conditional->assertions = self::processFunctionCall(
                    $baseConditional,
                    $thisClassName,
                    $source,
                    false
                );
                return;
            }

            $varName = ExpressionChecker::getArrayVarId(
                $baseConditional,
                $thisClassName,
                $source
            );

            $varType = isset($baseConditional->inferredType) ? $baseConditional->inferredType : null;

            if ($varName) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical) {
                    $ifTypes[$varName] = [['true']];
                } else {
                    $ifTypes[$varName] = [['!falsy']];
                }
            } else {
                self::scrapeAssertions($baseConditional, $thisClassName, $source);
                $ifTypes = $baseConditional->assertions;
            }

            if ($varType) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical
                    && $source instanceof StatementsSource
                    && $projectChecker
                ) {
                    $trueType = Type::getTrue();

                    if (!TypeChecker::isContainedBy(
                        $projectChecker->codebase,
                        $varType,
                        $trueType
                    ) && !TypeChecker::isContainedBy(
                        $projectChecker->codebase,
                        $trueType,
                        $varType
                    )) {
                        if ($varType->fromDocblock) {
                            if (IssueBuffer::accepts(
                                new DocblockTypeContradiction(
                                    $varType . ' does not contain true',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new TypeDoesNotContainType(
                                    $varType . ' does not contain true',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        }
                    }
                }
            }

            $conditional->assertions = $ifTypes;
            return;
        }

        if ($falsePosition) {
            if ($falsePosition === self::ASSIGNMENT_TO_RIGHT) {
                $baseConditional = $conditional->left;
            } elseif ($falsePosition === self::ASSIGNMENT_TO_LEFT) {
                $baseConditional = $conditional->right;
            } else {
                throw new \UnexpectedValueException('$falsePosition value');
            }

            if ($baseConditional instanceof PhpParser\Node\Expr\FuncCall) {
                $conditional->assertions = self::processFunctionCall(
                    $baseConditional,
                    $thisClassName,
                    $source,
                    true
                );
                return;
            }

            $varName = ExpressionChecker::getArrayVarId(
                $baseConditional,
                $thisClassName,
                $source
            );

            $varType = isset($baseConditional->inferredType) ? $baseConditional->inferredType : null;

            if ($varName) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical) {
                    $ifTypes[$varName] = [['false']];
                } else {
                    $ifTypes[$varName] = [['falsy']];
                }
            } elseif ($varType) {
                self::scrapeAssertions($baseConditional, $thisClassName, $source);

                if (!isset($baseConditional->assertions)) {
                    throw new \UnexpectedValueException('Assertions should be set');
                }

                $notifTypes = $baseConditional->assertions;

                if (count($notifTypes) === 1) {
                    $ifTypes = \Psalm\Type\Algebra::negateTypes($notifTypes);
                }
            }

            if ($varType) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical
                    && $source instanceof StatementsSource
                    && $projectChecker
                ) {
                    $falseType = Type::getFalse();

                    if (!TypeChecker::isContainedBy(
                        $projectChecker->codebase,
                        $varType,
                        $falseType
                    ) && !TypeChecker::isContainedBy(
                        $projectChecker->codebase,
                        $falseType,
                        $varType
                    )) {
                        if ($varType->fromDocblock) {
                            if (IssueBuffer::accepts(
                                new DocblockTypeContradiction(
                                    $varType . ' does not contain false',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new TypeDoesNotContainType(
                                    $varType . ' does not contain false',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        }
                    }
                }
            }

            $conditional->assertions = $ifTypes;
            return;
        }

        if ($gettypePosition) {
            if ($gettypePosition === self::ASSIGNMENT_TO_RIGHT) {
                $stringExpr = $conditional->left;
                $gettypeExpr = $conditional->right;
            } elseif ($gettypePosition === self::ASSIGNMENT_TO_LEFT) {
                $stringExpr = $conditional->right;
                $gettypeExpr = $conditional->left;
            } else {
                throw new \UnexpectedValueException('$gettypePosition value');
            }

            /** @var PhpParser\Node\Expr\FuncCall $gettypeExpr */
            $varName = ExpressionChecker::getArrayVarId(
                $gettypeExpr->args[0]->value,
                $thisClassName,
                $source
            );

            /** @var PhpParser\Node\Scalar\String_ $stringExpr */
            $varType = $stringExpr->value;

            if (!isset(ClassLikeChecker::$GETTYPETYPES[$varType])
                && $source instanceof StatementsSource
            ) {
                if (IssueBuffer::accepts(
                    new UnevaluatedCode(
                        'gettype cannot return this value',
                        new CodeLocation($source, $stringExpr)
                    )
                )) {
                    // fall through
                }
            } else {
                if ($varName && $varType) {
                    $ifTypes[$varName] = [[$varType]];
                }
            }

            $conditional->assertions = $ifTypes;
            return;
        }

        if ($getclassPosition) {
            if ($getclassPosition === self::ASSIGNMENT_TO_RIGHT) {
                $whichclassExpr = $conditional->left;
                $getclassExpr = $conditional->right;
            } elseif ($getclassPosition === self::ASSIGNMENT_TO_LEFT) {
                $whichclassExpr = $conditional->right;
                $getclassExpr = $conditional->left;
            } else {
                throw new \UnexpectedValueException('$getclassPosition value');
            }

            if ($getclassExpr instanceof PhpParser\Node\Expr\FuncCall) {
                $varName = ExpressionChecker::getArrayVarId(
                    $getclassExpr->args[0]->value,
                    $thisClassName,
                    $source
                );
            } else {
                $varName = '$this';
            }

            if ($whichclassExpr instanceof PhpParser\Node\Scalar\String_) {
                $varType = $whichclassExpr->value;
            } elseif ($whichclassExpr instanceof PhpParser\Node\Expr\ClassConstFetch
                && $whichclassExpr->class instanceof PhpParser\Node\Name
            ) {
                $varType = ClassLikeChecker::getFQCLNFromNameObject(
                    $whichclassExpr->class,
                    $source->getAliases()
                );

                if ($varType === 'self') {
                    $varType = $thisClassName;
                } elseif ($varType === 'parent' || $varType === 'static') {
                    $varType = null;
                }
            } else {
                throw new \UnexpectedValueException('Shouldn’t get here');
            }

            if ($source instanceof StatementsSource
                && $varType
                && ClassLikeChecker::checkFullyQualifiedClassLikeName(
                    $source,
                    $varType,
                    new CodeLocation($source, $whichclassExpr),
                    $source->getSuppressedIssues(),
                    false
                ) === false
            ) {
                // fall through
            } else {
                if ($varName && $varType) {
                    $ifTypes[$varName] = [['^getclass-' . $varType]];
                }
            }

            $conditional->assertions = $ifTypes;
            return;
        }

        if ($typedValuePosition) {
            if ($typedValuePosition === self::ASSIGNMENT_TO_RIGHT) {
                /** @var PhpParser\Node\Expr $conditional->right */
                $varName = ExpressionChecker::getArrayVarId(
                    $conditional->left,
                    $thisClassName,
                    $source
                );

                $otherType = isset($conditional->left->inferredType) ? $conditional->left->inferredType : null;
                $varType = $conditional->right->inferredType;
            } elseif ($typedValuePosition === self::ASSIGNMENT_TO_LEFT) {
                /** @var PhpParser\Node\Expr $conditional->left */
                $varName = ExpressionChecker::getArrayVarId(
                    $conditional->right,
                    $thisClassName,
                    $source
                );

                $varType = $conditional->left->inferredType;
                $otherType = isset($conditional->right->inferredType) ? $conditional->right->inferredType : null;
            } else {
                throw new \UnexpectedValueException('$typedValuePosition value');
            }

            if ($varName && $varType) {
                $identical = $conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical
                    || ($otherType
                        && (($varType->isString() && $otherType->isString())
                            || ($varType->isInt() && $otherType->isInt())
                            || ($varType->isFloat() && $otherType->isFloat())
                        )
                    );

                if ($identical) {
                    $ifTypes[$varName] = [['^' . $varType->getId()]];
                } else {
                    $ifTypes[$varName] = [['~' . $varType->getId()]];
                }
            }

            if ($otherType
                && $varType
                && $conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical
                && $source instanceof StatementsSource
                && $projectChecker
            ) {
                if (!TypeChecker::isContainedBy(
                    $projectChecker->codebase,
                    $varType,
                    $otherType,
                    true,
                    true
                ) && !TypeChecker::isContainedBy(
                    $projectChecker->codebase,
                    $otherType,
                    $varType,
                    true,
                    true
                )) {
                    if ($varType->fromDocblock || $otherType->fromDocblock) {
                        if (IssueBuffer::accepts(
                            new DocblockTypeContradiction(
                                $varType . ' does not contain ' . $otherType,
                                new CodeLocation($source, $conditional)
                            ),
                            $source->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    } else {
                        if (IssueBuffer::accepts(
                            new TypeDoesNotContainType(
                                $varType->getId() . ' does not contain ' . $otherType->getId(),
                                new CodeLocation($source, $conditional)
                            ),
                            $source->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    }
                }
            }

            $conditional->assertions = $ifTypes;
            return;
        }

        $varType = isset($conditional->left->inferredType) ? $conditional->left->inferredType : null;
        $otherType = isset($conditional->right->inferredType) ? $conditional->right->inferredType : null;

        if ($varType
            && $otherType
            && $conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical
            && $source instanceof StatementsSource
            && $projectChecker
        ) {
            if (!TypeChecker::canBeIdenticalTo($projectChecker->codebase, $varType, $otherType)) {
                if (IssueBuffer::accepts(
                    new TypeDoesNotContainType(
                        $varType . ' does not contain ' . $otherType,
                        new CodeLocation($source, $conditional)
                    ),
                    $source->getSuppressedIssues()
                )) {
                    // fall through
                }
            }
        }

        $conditional->assertions = [];
        return;
    }

    /**
     * @param PhpParser\Node\Expr\BinaryOp\NotIdentical|PhpParser\Node\Expr\BinaryOp\NotEqual $conditional
     * @param string|null $thisClassName
     *
     * @return void
     */
    private static function scrapeInequalityAssertions(
        PhpParser\Node\Expr\BinaryOp $conditional,
        $thisClassName,
        FileSource $source
    ) {
        $ifTypes = [];

        $projectChecker = $source instanceof StatementsSource
            ? $source->getFileChecker()->projectChecker
            : null;

        $nullPosition = self::hasNullVariable($conditional);
        $falsePosition = self::hasFalseVariable($conditional);
        $truePosition = self::hasTrueVariable($conditional);
        $gettypePosition = self::hasGetTypeCheck($conditional);
        $getclassPosition = self::hasGetClassCheck($conditional);
        $typedValuePosition = self::hasTypedValueComparison($conditional);

        if ($nullPosition !== null) {
            if ($nullPosition === self::ASSIGNMENT_TO_RIGHT) {
                $baseConditional = $conditional->left;
            } elseif ($nullPosition === self::ASSIGNMENT_TO_LEFT) {
                $baseConditional = $conditional->right;
            } else {
                throw new \UnexpectedValueException('Bad null variable position');
            }

            $varType = isset($baseConditional->inferredType) ? $baseConditional->inferredType : null;

            $varName = ExpressionChecker::getArrayVarId(
                $baseConditional,
                $thisClassName,
                $source
            );

            if ($varName) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical) {
                    $ifTypes[$varName] = [['!null']];
                } else {
                    $ifTypes[$varName] = [['!falsy']];
                }
            }

            if ($varType) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical
                    && $source instanceof StatementsSource
                    && $projectChecker
                ) {
                    $nullType = Type::getNull();

                    if (!TypeChecker::isContainedBy(
                        $projectChecker->codebase,
                        $varType,
                        $nullType
                    ) && !TypeChecker::isContainedBy(
                        $projectChecker->codebase,
                        $nullType,
                        $varType
                    )) {
                        if ($varType->fromDocblock) {
                            if (IssueBuffer::accepts(
                                new RedundantConditionGivenDocblockType(
                                    'Docblock-asserted type ' . $varType . ' can never contain null',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new RedundantCondition(
                                    $varType . ' can never contain null',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        }
                    }
                }
            }

            $conditional->assertions = $ifTypes;
            return;
        }

        if ($falsePosition) {
            if ($falsePosition === self::ASSIGNMENT_TO_RIGHT) {
                $baseConditional = $conditional->left;
            } elseif ($falsePosition === self::ASSIGNMENT_TO_LEFT) {
                $baseConditional = $conditional->right;
            } else {
                throw new \UnexpectedValueException('Bad false variable position');
            }

            $varName = ExpressionChecker::getArrayVarId(
                $baseConditional,
                $thisClassName,
                $source
            );

            $varType = isset($baseConditional->inferredType) ? $baseConditional->inferredType : null;

            if ($varName) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical) {
                    $ifTypes[$varName] = [['!false']];
                } else {
                    $ifTypes[$varName] = [['!falsy']];
                }
            } elseif ($varType) {
                self::scrapeAssertions($baseConditional, $thisClassName, $source);

                if (!isset($baseConditional->assertions)) {
                    throw new \UnexpectedValueException('Assertions should be set');
                }

                $notifTypes = $baseConditional->assertions;

                if (count($notifTypes) === 1) {
                    $ifTypes = \Psalm\Type\Algebra::negateTypes($notifTypes);
                }
            }

            if ($varType) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical
                    && $source instanceof StatementsSource
                    && $projectChecker
                ) {
                    $falseType = Type::getFalse();

                    if (!TypeChecker::isContainedBy(
                        $projectChecker->codebase,
                        $varType,
                        $falseType
                    ) && !TypeChecker::isContainedBy(
                        $projectChecker->codebase,
                        $falseType,
                        $varType
                    )) {
                        if ($varType->fromDocblock) {
                            if (IssueBuffer::accepts(
                                new RedundantConditionGivenDocblockType(
                                    'Docblock-asserted type ' . $varType . ' can never contain false',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new RedundantCondition(
                                    $varType . ' can never contain false',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        }
                    }
                }
            }

            $conditional->assertions = $ifTypes;
            return;
        }

        if ($truePosition) {
            if ($truePosition === self::ASSIGNMENT_TO_RIGHT) {
                if ($conditional->left instanceof PhpParser\Node\Expr\FuncCall) {
                    $conditional->assertions = self::processFunctionCall(
                        $conditional->left,
                        $thisClassName,
                        $source,
                        true
                    );
                    return;
                }

                $baseConditional = $conditional->left;
            } elseif ($truePosition === self::ASSIGNMENT_TO_LEFT) {
                if ($conditional->right instanceof PhpParser\Node\Expr\FuncCall) {
                    $conditional->assertions = self::processFunctionCall(
                        $conditional->right,
                        $thisClassName,
                        $source,
                        true
                    );
                    return;
                }

                $baseConditional = $conditional->right;
            } else {
                throw new \UnexpectedValueException('Bad null variable position');
            }

            $varName = ExpressionChecker::getArrayVarId(
                $baseConditional,
                $thisClassName,
                $source
            );

            $varType = isset($baseConditional->inferredType) ? $baseConditional->inferredType : null;

            if ($varName) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical) {
                    $ifTypes[$varName] = [['!true']];
                } else {
                    $ifTypes[$varName] = [['falsy']];
                }
            } elseif ($varType) {
                self::scrapeAssertions($baseConditional, $thisClassName, $source);

                if (!isset($baseConditional->assertions)) {
                    throw new \UnexpectedValueException('Assertions should be set');
                }

                $notifTypes = $baseConditional->assertions;

                if (count($notifTypes) === 1) {
                    $ifTypes = \Psalm\Type\Algebra::negateTypes($notifTypes);
                }
            }

            if ($varType) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical
                    && $source instanceof StatementsSource
                    && $projectChecker
                ) {
                    $trueType = Type::getTrue();

                    if (!TypeChecker::isContainedBy(
                        $projectChecker->codebase,
                        $varType,
                        $trueType
                    ) && !TypeChecker::isContainedBy(
                        $projectChecker->codebase,
                        $trueType,
                        $varType
                    )) {
                        if ($varType->fromDocblock) {
                            if (IssueBuffer::accepts(
                                new RedundantConditionGivenDocblockType(
                                    'Docblock-asserted type ' . $varType . ' can never contain true',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new RedundantCondition(
                                    $varType . ' can never contain ' . $trueType,
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        }
                    }
                }
            }

            $conditional->assertions = $ifTypes;
            return;
        }

        if ($gettypePosition) {
            if ($gettypePosition === self::ASSIGNMENT_TO_RIGHT) {
                $whichclassExpr = $conditional->left;
                $gettypeExpr = $conditional->right;
            } elseif ($gettypePosition === self::ASSIGNMENT_TO_LEFT) {
                $whichclassExpr = $conditional->right;
                $gettypeExpr = $conditional->left;
            } else {
                throw new \UnexpectedValueException('$gettypePosition value');
            }

            /** @var PhpParser\Node\Expr\FuncCall $gettypeExpr */
            $varName = ExpressionChecker::getArrayVarId(
                $gettypeExpr->args[0]->value,
                $thisClassName,
                $source
            );

            if ($whichclassExpr instanceof PhpParser\Node\Scalar\String_) {
                $varType = $whichclassExpr->value;
            } elseif ($whichclassExpr instanceof PhpParser\Node\Expr\ClassConstFetch
                && $whichclassExpr->class instanceof PhpParser\Node\Name
            ) {
                $varType = ClassLikeChecker::getFQCLNFromNameObject(
                    $whichclassExpr->class,
                    $source->getAliases()
                );
            } else {
                throw new \UnexpectedValueException('Shouldn’t get here');
            }

            if (!isset(ClassLikeChecker::$GETTYPETYPES[$varType])) {
                if (IssueBuffer::accepts(
                    new UnevaluatedCode(
                        'gettype cannot return this value',
                        new CodeLocation($source, $whichclassExpr)
                    )
                )) {
                    // fall through
                }
            } else {
                if ($varName && $varType) {
                    $ifTypes[$varName] = [['!' . $varType]];
                }
            }

            $conditional->assertions = $ifTypes;
            return;
        }

        if ($getclassPosition) {
            if ($getclassPosition === self::ASSIGNMENT_TO_RIGHT) {
                $whichclassExpr = $conditional->left;
                $getclassExpr = $conditional->right;
            } elseif ($getclassPosition === self::ASSIGNMENT_TO_LEFT) {
                $whichclassExpr = $conditional->right;
                $getclassExpr = $conditional->left;
            } else {
                throw new \UnexpectedValueException('$getclassPosition value');
            }

            if ($getclassExpr instanceof PhpParser\Node\Expr\FuncCall) {
                $varName = ExpressionChecker::getArrayVarId(
                    $getclassExpr->args[0]->value,
                    $thisClassName,
                    $source
                );
            } else {
                $varName = '$this';
            }

            if ($whichclassExpr instanceof PhpParser\Node\Scalar\String_) {
                $varType = $whichclassExpr->value;
            } elseif ($whichclassExpr instanceof PhpParser\Node\Expr\ClassConstFetch
                && $whichclassExpr->class instanceof PhpParser\Node\Name
            ) {
                $varType = ClassLikeChecker::getFQCLNFromNameObject(
                    $whichclassExpr->class,
                    $source->getAliases()
                );

                if ($varType === 'self') {
                    $varType = $thisClassName;
                } elseif ($varType === 'parent' || $varType === 'static') {
                    $varType = null;
                }
            } else {
                throw new \UnexpectedValueException('Shouldn’t get here');
            }

            if ($source instanceof StatementsSource
                && $projectChecker
                && $varType
                && ClassLikeChecker::checkFullyQualifiedClassLikeName(
                    $source,
                    $varType,
                    new CodeLocation($source, $whichclassExpr),
                    $source->getSuppressedIssues(),
                    false
                ) === false
            ) {
                // fall through
            } else {
                if ($varName && $varType) {
                    $ifTypes[$varName] = [['!^getclass-' . $varType]];
                }
            }

            $conditional->assertions = $ifTypes;
            return;
        }

        if ($typedValuePosition) {
            if ($typedValuePosition === self::ASSIGNMENT_TO_RIGHT) {
                /** @var PhpParser\Node\Expr $conditional->right */
                $varName = ExpressionChecker::getArrayVarId(
                    $conditional->left,
                    $thisClassName,
                    $source
                );

                $otherType = isset($conditional->left->inferredType) ? $conditional->left->inferredType : null;
                $varType = isset($conditional->right->inferredType) ? $conditional->right->inferredType : null;
            } elseif ($typedValuePosition === self::ASSIGNMENT_TO_LEFT) {
                /** @var PhpParser\Node\Expr $conditional->left */
                $varName = ExpressionChecker::getArrayVarId(
                    $conditional->right,
                    $thisClassName,
                    $source
                );

                $varType = isset($conditional->left->inferredType) ? $conditional->left->inferredType : null;
                $otherType = isset($conditional->right->inferredType) ? $conditional->right->inferredType : null;
            } else {
                throw new \UnexpectedValueException('$typedValuePosition value');
            }

            if ($varType) {
                if ($varName) {
                    $notIdentical = $conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical
                        || ($otherType
                            && (($varType->isString() && $otherType->isString())
                                || ($varType->isInt() && $otherType->isInt())
                                || ($varType->isFloat() && $otherType->isFloat())
                            )
                        );

                    if ($notIdentical) {
                        $ifTypes[$varName] = [['!^' . $varType->getId()]];
                    } else {
                        $ifTypes[$varName] = [['!~' . $varType->getId()]];
                    }
                }

                if ($otherType
                    && $conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical
                    && $source instanceof StatementsSource
                    && $projectChecker
                ) {
                    if (!TypeChecker::isContainedBy(
                        $projectChecker->codebase,
                        $varType,
                        $otherType,
                        true,
                        true
                    ) && !TypeChecker::isContainedBy(
                        $projectChecker->codebase,
                        $otherType,
                        $varType,
                        true,
                        true
                    )) {
                        if ($varType->fromDocblock || $otherType->fromDocblock) {
                            if (IssueBuffer::accepts(
                                new DocblockTypeContradiction(
                                    $varType . ' can never contain ' . $otherType,
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new RedundantCondition(
                                    $varType->getId() . ' can never contain ' . $otherType->getId(),
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        }
                    }
                }
            }

            $conditional->assertions = $ifTypes;
            return;
        }

        $conditional->assertions = [];
        return;
    }

    /**
     * @param  PhpParser\Node\Expr\FuncCall $expr
     * @param  string|null                  $thisClassName
     * @param  FileSource                   $source
     * @param  bool                         $negate
     *
     * @return array<string, array<int, array<int, string>>>
     */
    protected static function processFunctionCall(
        PhpParser\Node\Expr\FuncCall $expr,
        $thisClassName,
        FileSource $source,
        $negate = false
    ) {
        $prefix = $negate ? '!' : '';

        $firstVarName = isset($expr->args[0]->value)
            ? ExpressionChecker::getArrayVarId(
                $expr->args[0]->value,
                $thisClassName,
                $source
            )
            : null;

        $ifTypes = [];

        if (self::hasNullCheck($expr)) {
            if ($firstVarName) {
                $ifTypes[$firstVarName] = [[$prefix . 'null']];
            }
        } elseif (self::hasIsACheck($expr)) {
            if ($expr->args[0]->value instanceof PhpParser\Node\Expr\ClassConstFetch
                && $expr->args[0]->value->name instanceof PhpParser\Node\Identifier
                && strtolower($expr->args[0]->value->name->name) === 'class'
                && $expr->args[0]->value->class instanceof PhpParser\Node\Name
                && count($expr->args[0]->value->class->parts) === 1
                && strtolower($expr->args[0]->value->class->parts[0]) === 'static'
            ) {
                $firstVarName = '$this';
            }

            if ($firstVarName) {
                $secondArg = $expr->args[1]->value;

                $isAPrefix = '';

                if (isset($expr->args[2]->value)) {
                    $thirdArg = $expr->args[2]->value;

                    if (!$thirdArg instanceof PhpParser\Node\Expr\ConstFetch
                        || !in_array(strtolower($thirdArg->name->parts[0]), ['true', 'false'])
                    ) {
                        return $ifTypes;
                    }

                    $isAPrefix = strtolower($thirdArg->name->parts[0]) === 'true' ? 'isa-' : '';
                }

                if ($secondArg instanceof PhpParser\Node\Scalar\String_) {
                    $ifTypes[$firstVarName] = [[$prefix . $isAPrefix . $secondArg->value]];
                } elseif ($secondArg instanceof PhpParser\Node\Expr\ClassConstFetch
                    && $secondArg->class instanceof PhpParser\Node\Name
                    && $secondArg->name instanceof PhpParser\Node\Identifier
                    && strtolower($secondArg->name->name) === 'class'
                ) {
                    $firstArg = $expr->args[0]->value;

                    if (isset($firstArg->inferredType)
                        && $firstArg->inferredType->isSingleStringLiteral()
                        && $source instanceof StatementsChecker
                        && $source->getSource()->getSource() instanceof \Psalm\Checker\TraitChecker
                        && $firstArg->inferredType->getSingleStringLiteral()->value === $thisClassName
                    ) {
                        // do nothing
                    } else {
                        $classNode = $secondArg->class;

                        if ($classNode->parts === ['static'] || $classNode->parts === ['self']) {
                            if ($thisClassName) {
                                $ifTypes[$firstVarName] = [[$prefix . $isAPrefix . $thisClassName]];
                            }
                        } elseif ($classNode->parts === ['parent']) {
                            // do nothing
                        } else {
                            $ifTypes[$firstVarName] = [[
                                $prefix . $isAPrefix
                                    . ClassLikeChecker::getFQCLNFromNameObject(
                                        $classNode,
                                        $source->getAliases()
                                    )
                            ]];
                        }
                    }
                }
            }
        } elseif (self::hasArrayCheck($expr)) {
            if ($firstVarName) {
                $ifTypes[$firstVarName] = [[$prefix . 'array']];
            }
        } elseif (self::hasBoolCheck($expr)) {
            if ($firstVarName) {
                $ifTypes[$firstVarName] = [[$prefix . 'bool']];
            }
        } elseif (self::hasStringCheck($expr)) {
            if ($firstVarName) {
                $ifTypes[$firstVarName] = [[$prefix . 'string']];
            }
        } elseif (self::hasObjectCheck($expr)) {
            if ($firstVarName) {
                $ifTypes[$firstVarName] = [[$prefix . 'object']];
            }
        } elseif (self::hasNumericCheck($expr)) {
            if ($firstVarName) {
                $ifTypes[$firstVarName] = [[$prefix . 'numeric']];
            }
        } elseif (self::hasIntCheck($expr)) {
            if ($firstVarName) {
                $ifTypes[$firstVarName] = [[$prefix . 'int']];
            }
        } elseif (self::hasFloatCheck($expr)) {
            if ($firstVarName) {
                $ifTypes[$firstVarName] = [[$prefix . 'float']];
            }
        } elseif (self::hasResourceCheck($expr)) {
            if ($firstVarName) {
                $ifTypes[$firstVarName] = [[$prefix . 'resource']];
            }
        } elseif (self::hasScalarCheck($expr)) {
            if ($firstVarName) {
                $ifTypes[$firstVarName] = [[$prefix . 'scalar']];
            }
        } elseif (self::hasCallableCheck($expr)) {
            if ($firstVarName) {
                $ifTypes[$firstVarName] = [[$prefix . 'callable']];
            }
        } elseif (self::hasIterableCheck($expr)) {
            if ($firstVarName) {
                $ifTypes[$firstVarName] = [[$prefix . 'iterable']];
            }
        } elseif (self::hasInArrayCheck($expr)) {
            if ($firstVarName && isset($expr->args[1]->value->inferredType)) {
                foreach ($expr->args[1]->value->inferredType->getTypes() as $atomicType) {
                    if ($atomicType instanceof Type\Atomic\TArray
                        || $atomicType instanceof Type\Atomic\ObjectLike
                    ) {
                        if ($atomicType instanceof Type\Atomic\ObjectLike) {
                            $atomicType = $atomicType->getGenericArrayType();
                        }

                        $arrayLiteralTypes = array_merge(
                            $atomicType->typeParams[1]->getLiteralStrings(),
                            $atomicType->typeParams[1]->getLiteralInts(),
                            $atomicType->typeParams[1]->getLiteralFloats()
                        );

                        if (count($atomicType->typeParams[1]->getTypes()) === count($arrayLiteralTypes)) {
                            $literalAssertions = [];

                            foreach ($arrayLiteralTypes as $arrayLiteralType) {
                                $literalAssertions[] = '^' . $arrayLiteralType->getId();
                            }

                            if ($negate) {
                                $ifTypes = \Psalm\Type\Algebra::negateTypes([
                                    $firstVarName => [$literalAssertions]
                                ]);
                            } else {
                                $ifTypes[$firstVarName] = [$literalAssertions];
                            }
                        }
                    }
                }
            }
        } elseif (self::hasArrayKeyExistsCheck($expr)) {
            $arrayRoot = isset($expr->args[1]->value)
                ? ExpressionChecker::getArrayVarId(
                    $expr->args[1]->value,
                    $thisClassName,
                    $source
                )
                : null;

            if ($firstVarName === null && isset($expr->args[0])) {
                $firstArg = $expr->args[0];

                if ($firstArg->value instanceof PhpParser\Node\Scalar\String_) {
                    $firstVarName = '"' . $firstArg->value->value . '"';
                } elseif ($firstArg->value instanceof PhpParser\Node\Scalar\LNumber) {
                    $firstVarName = (string) $firstArg->value->value;
                }
            }

            if ($firstVarName !== null
                && $arrayRoot
                && !strpos($firstVarName, '->')
                && !strpos($firstVarName, '[')
            ) {
                $ifTypes[$arrayRoot . '[' . $firstVarName . ']'] = [[$prefix . 'array-key-exists']];
            }
        } else {
            $ifTypes = self::processCustomAssertion($expr, $thisClassName, $source, $negate);
        }

        return $ifTypes;
    }

    /**
     * @param  PhpParser\Node\Expr\FuncCall|PhpParser\Node\Expr\MethodCall      $expr
     * @param  string|null  $thisClassName
     * @param  FileSource   $source
     * @param  bool         $negate
     *
     * @return array<string, array<int, array<int, string>>>
     */
    protected static function processCustomAssertion(
        $expr,
        $thisClassName,
        FileSource $source,
        $negate = false
    ) {
        if (!$source instanceof StatementsChecker
            || (!isset($expr->ifTrueAssertions) && !isset($expr->ifFalseAssertions))
        ) {
            return [];
        }

        $prefix = $negate ? '!' : '';

        $firstVarName = isset($expr->args[0]->value)
            ? ExpressionChecker::getArrayVarId(
                $expr->args[0]->value,
                $thisClassName,
                $source
            )
            : null;

        $ifTypes = [];

        if (isset($expr->ifTrueAssertions)) {
            foreach ($expr->ifTrueAssertions as $assertion) {
                if (is_int($assertion->varId) && isset($expr->args[$assertion->varId])) {
                    if ($assertion->varId === 0) {
                        $varName = $firstVarName;
                    } else {
                        $varName = ExpressionChecker::getArrayVarId(
                            $expr->args[$assertion->varId]->value,
                            $thisClassName,
                            $source
                        );
                    }

                    if ($varName) {
                        if ($prefix === $assertion->rule[0][0][0]) {
                            $ifTypes[$varName] = [[substr($assertion->rule[0][0], 1)]];
                        } else {
                            $ifTypes[$varName] = [[$prefix . $assertion->rule[0][0]]];
                        }
                    }
                }
            }
        }

        if (isset($expr->ifFalseAssertions)) {
            $negatedPrefix = !$negate ? '!' : '';

            foreach ($expr->ifFalseAssertions as $assertion) {
                if (is_int($assertion->varId) && isset($expr->args[$assertion->varId])) {
                    if ($assertion->varId === 0) {
                        $varName = $firstVarName;
                    } else {
                        $varName = ExpressionChecker::getArrayVarId(
                            $expr->args[$assertion->varId]->value,
                            $thisClassName,
                            $source
                        );
                    }

                    if ($varName) {
                        if ($negatedPrefix === $assertion->rule[0][0][0]) {
                            $ifTypes[$varName] = [[substr($assertion->rule[0][0], 1)]];
                        } else {
                            $ifTypes[$varName] = [[$negatedPrefix . $assertion->rule[0][0]]];
                        }
                    }
                }
            }
        }

        return $ifTypes;
    }

    /**
     * @param  PhpParser\Node\Expr\Instanceof_ $stmt
     * @param  string|null                     $thisClassName
     * @param  FileSource                $source
     *
     * @return string|null
     */
    protected static function getInstanceOfTypes(
        PhpParser\Node\Expr\Instanceof_ $stmt,
        $thisClassName,
        FileSource $source
    ) {
        if ($stmt->class instanceof PhpParser\Node\Name) {
            if (!in_array(strtolower($stmt->class->parts[0]), ['self', 'static', 'parent'], true)) {
                $instanceofClass = ClassLikeChecker::getFQCLNFromNameObject(
                    $stmt->class,
                    $source->getAliases()
                );

                return $instanceofClass;
            } elseif ($thisClassName
                && (in_array(strtolower($stmt->class->parts[0]), ['self', 'static'], true))
            ) {
                return $thisClassName;
            }
        }

        return null;
    }

    /**
     * @param   PhpParser\Node\Expr\BinaryOp    $conditional
     *
     * @return  int|null
     */
    protected static function hasNullVariable(PhpParser\Node\Expr\BinaryOp $conditional)
    {
        if ($conditional->right instanceof PhpParser\Node\Expr\ConstFetch
            && strtolower($conditional->right->name->parts[0]) === 'null'
        ) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        if ($conditional->left instanceof PhpParser\Node\Expr\ConstFetch
            && strtolower($conditional->left->name->parts[0]) === 'null'
        ) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return null;
    }

    /**
     * @param   PhpParser\Node\Expr\BinaryOp    $conditional
     *
     * @return  int|null
     */
    protected static function hasFalseVariable(PhpParser\Node\Expr\BinaryOp $conditional)
    {
        if ($conditional->right instanceof PhpParser\Node\Expr\ConstFetch
            && strtolower($conditional->right->name->parts[0]) === 'false'
        ) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        if ($conditional->left instanceof PhpParser\Node\Expr\ConstFetch
            && strtolower($conditional->left->name->parts[0]) === 'false'
        ) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return null;
    }

    /**
     * @param   PhpParser\Node\Expr\BinaryOp    $conditional
     *
     * @return  int|null
     */
    protected static function hasTrueVariable(PhpParser\Node\Expr\BinaryOp $conditional)
    {
        if ($conditional->right instanceof PhpParser\Node\Expr\ConstFetch
            && strtolower($conditional->right->name->parts[0]) === 'true'
        ) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        if ($conditional->left instanceof PhpParser\Node\Expr\ConstFetch
            && strtolower($conditional->left->name->parts[0]) === 'true'
        ) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return null;
    }

    /**
     * @param   PhpParser\Node\Expr\BinaryOp    $conditional
     *
     * @return  false|int
     */
    protected static function hasGetTypeCheck(PhpParser\Node\Expr\BinaryOp $conditional)
    {
        if ($conditional->right instanceof PhpParser\Node\Expr\FuncCall &&
            $conditional->right->name instanceof PhpParser\Node\Name &&
            strtolower($conditional->right->name->parts[0]) === 'gettype' &&
            $conditional->left instanceof PhpParser\Node\Scalar\String_) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        if ($conditional->left instanceof PhpParser\Node\Expr\FuncCall &&
            $conditional->left->name instanceof PhpParser\Node\Name &&
            strtolower($conditional->left->name->parts[0]) === 'gettype' &&
            $conditional->right instanceof PhpParser\Node\Scalar\String_) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\BinaryOp    $conditional
     *
     * @return  false|int
     */
    protected static function hasGetClassCheck(PhpParser\Node\Expr\BinaryOp $conditional)
    {
        $rightGetClass = $conditional->right instanceof PhpParser\Node\Expr\FuncCall
            && $conditional->right->name instanceof PhpParser\Node\Name
            && strtolower($conditional->right->name->parts[0]) === 'get_class';

        $rightStaticClass = $conditional->right instanceof PhpParser\Node\Expr\ClassConstFetch
            && $conditional->right->class instanceof PhpParser\Node\Name
            && $conditional->right->class->parts === ['static']
            && $conditional->right->name instanceof PhpParser\Node\Identifier
            && strtolower($conditional->right->name->name) === 'class';

        $leftClassString = $conditional->left instanceof PhpParser\Node\Scalar\String_
            || ($conditional->left instanceof PhpParser\Node\Expr\ClassConstFetch
                && $conditional->left->class instanceof PhpParser\Node\Name
                && $conditional->left->name instanceof PhpParser\Node\Identifier
                && strtolower($conditional->left->name->name) === 'class');

        if (($rightGetClass || $rightStaticClass) && $leftClassString) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        $leftGetClass = $conditional->left instanceof PhpParser\Node\Expr\FuncCall
            && $conditional->left->name instanceof PhpParser\Node\Name
            && strtolower($conditional->left->name->parts[0]) === 'get_class';

        $leftStaticClass = $conditional->left instanceof PhpParser\Node\Expr\ClassConstFetch
            && $conditional->left->class instanceof PhpParser\Node\Name
            && $conditional->left->class->parts === ['static']
            && $conditional->left->name instanceof PhpParser\Node\Identifier
            && strtolower($conditional->left->name->name) === 'class';

        $rightClassString = $conditional->right instanceof PhpParser\Node\Scalar\String_
            || ($conditional->right instanceof PhpParser\Node\Expr\ClassConstFetch
                && $conditional->right->class instanceof PhpParser\Node\Name
                && $conditional->right->name instanceof PhpParser\Node\Identifier
                && strtolower($conditional->right->name->name) === 'class');

        if (($leftGetClass || $leftStaticClass) && $rightClassString) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\BinaryOp    $conditional
     *
     * @return  false|int
     */
    protected static function hasTypedValueComparison(PhpParser\Node\Expr\BinaryOp $conditional)
    {
        if (isset($conditional->right->inferredType)
            && count($conditional->right->inferredType->getTypes()) === 1
        ) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        if (isset($conditional->left->inferredType)
            && count($conditional->left->inferredType->getTypes()) === 1
        ) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasNullCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && strtolower($stmt->name->parts[0]) === 'is_null') {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasIsACheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name
            && strtolower($stmt->name->parts[0]) === 'is_a'
            && isset($stmt->args[1])
        ) {
            $secondArg = $stmt->args[1]->value;

            if ($secondArg instanceof PhpParser\Node\Scalar\String_
                || (
                    $secondArg instanceof PhpParser\Node\Expr\ClassConstFetch
                    && $secondArg->class instanceof PhpParser\Node\Name
                    && $secondArg->name instanceof PhpParser\Node\Identifier
                    && strtolower($secondArg->name->name) === 'class'
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasArrayCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && strtolower($stmt->name->parts[0]) === 'is_array') {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasStringCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && strtolower($stmt->name->parts[0]) === 'is_string') {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasBoolCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && strtolower($stmt->name->parts[0]) === 'is_bool') {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasObjectCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_object']) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasNumericCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_numeric']) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasIterableCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && strtolower($stmt->name->parts[0]) === 'is_iterable') {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasIntCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name &&
            ($stmt->name->parts === ['is_int'] ||
                $stmt->name->parts === ['is_integer'] ||
                $stmt->name->parts === ['is_long'])
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasFloatCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name &&
            ($stmt->name->parts === ['is_float'] ||
                $stmt->name->parts === ['is_real'] ||
                $stmt->name->parts === ['is_double'])
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasResourceCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_resource']) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasScalarCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_scalar']) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasCallableCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_callable']) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasInArrayCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name
            && $stmt->name->parts === ['in_array']
            && isset($stmt->args[2])
        ) {
            $secondArg = $stmt->args[2]->value;

            if ($secondArg instanceof PhpParser\Node\Expr\ConstFetch
                && $secondArg->name instanceof PhpParser\Node\Name
                && strtolower($secondArg->name->parts[0]) === 'true'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasArrayKeyExistsCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['array_key_exists']) {
            return true;
        }

        return false;
    }
}
