<?php
namespace Psalm\Checker\Statements;

use PhpParser;
use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\ClosureChecker;
use Psalm\Checker\CommentChecker;
use Psalm\Checker\FunctionLikeChecker;
use Psalm\Checker\ProjectChecker;
use Psalm\Checker\Statements\Expression\ArrayChecker;
use Psalm\Checker\Statements\Expression\AssertionFinder;
use Psalm\Checker\Statements\Expression\AssignmentChecker;
use Psalm\Checker\Statements\Expression\BinaryOpChecker;
use Psalm\Checker\Statements\Expression\Call\FunctionCallChecker;
use Psalm\Checker\Statements\Expression\Call\MethodCallChecker;
use Psalm\Checker\Statements\Expression\Call\NewChecker;
use Psalm\Checker\Statements\Expression\Call\StaticCallChecker;
use Psalm\Checker\Statements\Expression\Fetch\ArrayFetchChecker;
use Psalm\Checker\Statements\Expression\Fetch\ConstFetchChecker;
use Psalm\Checker\Statements\Expression\Fetch\PropertyFetchChecker;
use Psalm\Checker\Statements\Expression\Fetch\VariableFetchChecker;
use Psalm\Checker\Statements\Expression\IncludeChecker;
use Psalm\Checker\Statements\Expression\TernaryChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Checker\TypeChecker;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\Context;
use Psalm\Exception\DocblockParseException;
use Psalm\FileManipulation\FileManipulationBuffer;
use Psalm\FileSource;
use Psalm\Issue\ForbiddenCode;
use Psalm\Issue\InvalidCast;
use Psalm\Issue\InvalidClone;
use Psalm\Issue\InvalidDocblock;
use Psalm\Issue\PossiblyUndefinedVariable;
use Psalm\Issue\UndefinedVariable;
use Psalm\Issue\UnrecognizedExpression;
use Psalm\IssueBuffer;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Atomic\ObjectLike;
use Psalm\Type\Atomic\Scalar;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TGenericParam;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TObject;
use Psalm\Type\Atomic\TString;
use Psalm\Type\TypeCombination;

class ExpressionChecker
{
    /**
     * @param   StatementsChecker   $statementsChecker
     * @param   PhpParser\Node\Expr $stmt
     * @param   Context             $context
     * @param   bool                $arrayAssignment
     *
     * @return  false|null
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr $stmt,
        Context $context,
        $arrayAssignment = false,
        Context $globalContext = null
    ) {
        if ($stmt instanceof PhpParser\Node\Expr\Variable) {
            if (VariableFetchChecker::analyze(
                $statementsChecker,
                $stmt,
                $context,
                false,
                null,
                $arrayAssignment
            ) === false
            ) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\Assign) {
            $assignmentType = AssignmentChecker::analyze(
                $statementsChecker,
                $stmt->var,
                $stmt->expr,
                null,
                $context,
                (string)$stmt->getDocComment(),
                $stmt->getLine()
            );

            if ($assignmentType === false) {
                return false;
            }

            $stmt->inferredType = $assignmentType;
        } elseif ($stmt instanceof PhpParser\Node\Expr\AssignOp) {
            if (AssignmentChecker::analyzeAssignmentOperation($statementsChecker, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\MethodCall) {
            if (MethodCallChecker::analyze($statementsChecker, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\StaticCall) {
            if (StaticCallChecker::analyze($statementsChecker, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\ConstFetch) {
            ConstFetchChecker::analyze($statementsChecker, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Scalar\String_) {
            $stmt->inferredType = Type::getString(strlen($stmt->value) < 30 ? $stmt->value : null);
        } elseif ($stmt instanceof PhpParser\Node\Scalar\EncapsedStringPart) {
            // do nothing
        } elseif ($stmt instanceof PhpParser\Node\Scalar\MagicConst) {
            switch (strtolower($stmt->getName())) {
                case '__line__':
                    $stmt->inferredType = Type::getInt();
                    break;

                case '__class__':
                    $stmt->inferredType = Type::getClassString();
                    break;

                case '__file__':
                case '__dir__':
                case '__function__':
                case '__trait__':
                case '__method__':
                case '__namespace__':
                    $stmt->inferredType = Type::getString();
                    break;
            }
        } elseif ($stmt instanceof PhpParser\Node\Scalar\LNumber) {
            $stmt->inferredType = Type::getInt(false, $stmt->value);
        } elseif ($stmt instanceof PhpParser\Node\Scalar\DNumber) {
            $stmt->inferredType = Type::getFloat($stmt->value);
        } elseif ($stmt instanceof PhpParser\Node\Expr\UnaryMinus ||
            $stmt instanceof PhpParser\Node\Expr\UnaryPlus
        ) {
            if (self::analyze($statementsChecker, $stmt->expr, $context) === false) {
                return false;
            }

            if (!isset($stmt->expr->inferredType)) {
                $stmt->inferredType = new Type\Union([new TInt, new TFloat]);
            } elseif ($stmt->expr->inferredType->isMixed()) {
                $stmt->inferredType = Type::getMixed();
            } else {
                $acceptableTypes = [];

                foreach ($stmt->expr->inferredType->getTypes() as $typePart) {
                    if ($typePart instanceof TInt || $typePart instanceof TFloat) {
                        if ($typePart instanceof Type\Atomic\TLiteralInt
                            && $stmt instanceof PhpParser\Node\Expr\UnaryMinus
                        ) {
                            $typePart->value = -$typePart->value;
                        } elseif ($typePart instanceof Type\Atomic\TLiteralFloat
                            && $stmt instanceof PhpParser\Node\Expr\UnaryMinus
                        ) {
                            $typePart->value = -$typePart->value;
                        }

                        $acceptableTypes[] = $typePart;
                    } elseif ($typePart instanceof TString) {
                        $acceptableTypes[] = new TInt;
                        $acceptableTypes[] = new TFloat;
                    } else {
                        $acceptableTypes[] = new TInt;
                    }
                }

                $stmt->inferredType = new Type\Union($acceptableTypes);
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\Isset_) {
            self::analyzeIsset($statementsChecker, $stmt, $context);
            $stmt->inferredType = Type::getBool();
        } elseif ($stmt instanceof PhpParser\Node\Expr\ClassConstFetch) {
            if (ConstFetchChecker::analyzeClassConst($statementsChecker, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\PropertyFetch) {
            if (PropertyFetchChecker::analyzeInstance($statementsChecker, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\StaticPropertyFetch) {
            if (PropertyFetchChecker::analyzeStatic($statementsChecker, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\BitwiseNot) {
            if (self::analyze($statementsChecker, $stmt->expr, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\BinaryOp) {
            if (BinaryOpChecker::analyze(
                $statementsChecker,
                $stmt,
                $context
            ) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\PostInc ||
            $stmt instanceof PhpParser\Node\Expr\PostDec ||
            $stmt instanceof PhpParser\Node\Expr\PreInc ||
            $stmt instanceof PhpParser\Node\Expr\PreDec
        ) {
            if (self::analyze($statementsChecker, $stmt->var, $context) === false) {
                return false;
            }

            if (isset($stmt->var->inferredType)) {
                $returnType = null;

                $fakeRightExpr = new PhpParser\Node\Scalar\LNumber(1, $stmt->getAttributes());
                $fakeRightExpr->inferredType = Type::getInt();

                BinaryOpChecker::analyzeNonDivArithmenticOp(
                    $statementsChecker,
                    $stmt->var,
                    $fakeRightExpr,
                    $stmt,
                    $returnType,
                    $context
                );

                $stmt->inferredType = clone $stmt->var->inferredType;
                $stmt->inferredType->fromCalculation = true;

                foreach ($stmt->inferredType->getTypes() as $atomicType) {
                    if ($atomicType instanceof Type\Atomic\TLiteralInt) {
                        $stmt->inferredType->addType(new Type\Atomic\TInt);
                    } elseif ($atomicType instanceof Type\Atomic\TLiteralFloat) {
                        $stmt->inferredType->addType(new Type\Atomic\TFloat);
                    }
                }

                $varId = self::getArrayVarId($stmt->var, null);

                if ($varId && isset($context->varsInScope[$varId])) {
                    $context->varsInScope[$varId] = $stmt->inferredType;

                    if ($context->collectReferences && $stmt->var instanceof PhpParser\Node\Expr\Variable) {
                        $location = new CodeLocation($statementsChecker, $stmt->var);
                        $context->assignedVarIds[$varId] = true;
                        $context->possiblyAssignedVarIds[$varId] = true;
                        $statementsChecker->registerVariableAssignment(
                            $varId,
                            $location
                        );
                        $context->unreferencedVars[$varId] = [$location->getHash() => $location];
                    }
                }
            } else {
                $stmt->inferredType = Type::getMixed();
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\New_) {
            if (NewChecker::analyze($statementsChecker, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\Array_) {
            if (ArrayChecker::analyze($statementsChecker, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Scalar\Encapsed) {
            if (self::analyzeEncapsulatedString($statementsChecker, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\FuncCall) {
            $projectChecker = $statementsChecker->getFileChecker()->projectChecker;
            if (FunctionCallChecker::analyze(
                $projectChecker,
                $statementsChecker,
                $stmt,
                $context
            ) === false
            ) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\Ternary) {
            if (TernaryChecker::analyze($statementsChecker, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\BooleanNot) {
            if (self::analyzeBooleanNot($statementsChecker, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\Empty_) {
            self::analyzeEmpty($statementsChecker, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Expr\Closure) {
            $closureChecker = new ClosureChecker($stmt, $statementsChecker->getSource());

            if (self::analyzeClosureUses($statementsChecker, $stmt, $context) === false) {
                return false;
            }

            $codebase = $statementsChecker->getFileChecker()->projectChecker->codebase;

            $useContext = new Context($context->self);
            $useContext->collectReferences = $codebase->collectReferences;

            if (!$statementsChecker->isStatic()) {
                if ($context->collectMutations &&
                    $context->self &&
                    $codebase->classExtends(
                        $context->self,
                        (string)$statementsChecker->getFQCLN()
                    )
                ) {
                    $useContext->varsInScope['$this'] = clone $context->varsInScope['$this'];
                } elseif ($context->self) {
                    $useContext->varsInScope['$this'] = new Type\Union([new TNamedObject($context->self)]);
                }
            }

            foreach ($context->varsInScope as $var => $type) {
                if (strpos($var, '$this->') === 0) {
                    $useContext->varsInScope[$var] = clone $type;
                }
            }

            foreach ($context->varsPossiblyInScope as $var => $_) {
                if (strpos($var, '$this->') === 0) {
                    $useContext->varsPossiblyInScope[$var] = true;
                }
            }

            foreach ($stmt->uses as $use) {
                if (!is_string($use->var->name)) {
                    continue;
                }

                $useVarId = '$' . $use->var->name;
                // insert the ref into the current context if passed by ref, as whatever we're passing
                // the closure to could execute it straight away.
                if (!$context->hasVariable($useVarId, $statementsChecker) && $use->byRef) {
                    $context->varsInScope[$useVarId] = Type::getMixed();
                }

                $useContext->varsInScope[$useVarId] =
                    $context->hasVariable($useVarId, $statementsChecker) && !$use->byRef
                    ? clone $context->varsInScope[$useVarId]
                    : Type::getMixed();

                $useContext->varsPossiblyInScope[$useVarId] = true;
            }

            $closureChecker->analyze($useContext, $context);

            if (!isset($stmt->inferredType)) {
                $stmt->inferredType = Type::getClosure();
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\ArrayDimFetch) {
            if (ArrayFetchChecker::analyze(
                $statementsChecker,
                $stmt,
                $context
            ) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\Int_) {
            if (self::analyze($statementsChecker, $stmt->expr, $context) === false) {
                return false;
            }

            $stmt->inferredType = Type::getInt();
        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\Double) {
            if (self::analyze($statementsChecker, $stmt->expr, $context) === false) {
                return false;
            }

            $stmt->inferredType = Type::getFloat();
        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\Bool_) {
            if (self::analyze($statementsChecker, $stmt->expr, $context) === false) {
                return false;
            }

            $stmt->inferredType = Type::getBool();
        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\String_) {
            if (self::analyze($statementsChecker, $stmt->expr, $context) === false) {
                return false;
            }

            $containerType = Type::getString();

            if (isset($stmt->expr->inferredType)
                && !$stmt->expr->inferredType->isMixed()
                && !isset($stmt->expr->inferredType->getTypes()['resource'])
                && !TypeChecker::isContainedBy(
                    $statementsChecker->getFileChecker()->projectChecker->codebase,
                    $stmt->expr->inferredType,
                    $containerType,
                    true,
                    false,
                    $hasScalarMatch
                )
                && !$hasScalarMatch
            ) {
                if (IssueBuffer::accepts(
                    new InvalidCast(
                        $stmt->expr->inferredType . ' cannot be cast to ' . $containerType,
                        new CodeLocation($statementsChecker->getSource(), $stmt)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }
            }

            $stmt->inferredType = $containerType;
        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\Object_) {
            if (self::analyze($statementsChecker, $stmt->expr, $context) === false) {
                return false;
            }

            $stmt->inferredType = new Type\Union([new TNamedObject('stdClass')]);
        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\Array_) {
            if (self::analyze($statementsChecker, $stmt->expr, $context) === false) {
                return false;
            }

            $permissibleAtomicTypes = [];
            $allPermissible = false;

            if (isset($stmt->expr->inferredType)) {
                $allPermissible = true;

                foreach ($stmt->expr->inferredType->getTypes() as $type) {
                    if ($type instanceof Scalar) {
                        $permissibleAtomicTypes[] = new ObjectLike([new Type\Union([$type])]);
                    } elseif ($type instanceof TNull) {
                        $permissibleAtomicTypes[] = new TArray([Type::getEmpty(), Type::getEmpty()]);
                    } elseif ($type instanceof TArray) {
                        $permissibleAtomicTypes[] = clone $type;
                    } elseif ($type instanceof ObjectLike) {
                        $permissibleAtomicTypes[] = clone $type;
                    } else {
                        $allPermissible = false;
                        break;
                    }
                }
            }

            if ($allPermissible) {
                $stmt->inferredType = TypeCombination::combineTypes($permissibleAtomicTypes);
            } else {
                $stmt->inferredType = Type::getArray();
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\Unset_) {
            if (self::analyze($statementsChecker, $stmt->expr, $context) === false) {
                return false;
            }

            $stmt->inferredType = Type::getNull();
        } elseif ($stmt instanceof PhpParser\Node\Expr\Clone_) {
            self::analyzeClone($statementsChecker, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Expr\Instanceof_) {
            if (self::analyze($statementsChecker, $stmt->expr, $context) === false) {
                return false;
            }

            if ($stmt->class instanceof PhpParser\Node\Expr) {
                if (self::analyze($statementsChecker, $stmt->class, $context) === false) {
                    return false;
                }
            } elseif (!in_array(strtolower($stmt->class->parts[0]), ['self', 'static', 'parent'], true)
            ) {
                if ($context->checkClasses) {
                    $fqClassName = ClassLikeChecker::getFQCLNFromNameObject(
                        $stmt->class,
                        $statementsChecker->getAliases()
                    );

                    if (ClassLikeChecker::checkFullyQualifiedClassLikeName(
                        $statementsChecker,
                        $fqClassName,
                        new CodeLocation($statementsChecker->getSource(), $stmt->class),
                        $statementsChecker->getSuppressedIssues(),
                        false
                    ) === false) {
                        return false;
                    }
                }
            }

            $stmt->inferredType = Type::getBool();
        } elseif ($stmt instanceof PhpParser\Node\Expr\Exit_) {
            if ($stmt->expr) {
                if (self::analyze($statementsChecker, $stmt->expr, $context) === false) {
                    return false;
                }
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\Include_) {
            IncludeChecker::analyze($statementsChecker, $stmt, $context, $globalContext);
        } elseif ($stmt instanceof PhpParser\Node\Expr\Eval_) {
            $context->checkClasses = false;
            $context->checkVariables = false;

            if (self::analyze($statementsChecker, $stmt->expr, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\AssignRef) {
            if (AssignmentChecker::analyzeAssignmentRef($statementsChecker, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\ErrorSuppress) {
            if (self::analyze($statementsChecker, $stmt->expr, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\ShellExec) {
            if (IssueBuffer::accepts(
                new ForbiddenCode(
                    'Use of shell_exec',
                    new CodeLocation($statementsChecker->getSource(), $stmt)
                ),
                $statementsChecker->getSuppressedIssues()
            )) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\Print_) {
            if (self::analyze($statementsChecker, $stmt->expr, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\Yield_) {
            self::analyzeYield($statementsChecker, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Expr\YieldFrom) {
            self::analyzeYieldFrom($statementsChecker, $stmt, $context);
        } else {
            if (IssueBuffer::accepts(
                new UnrecognizedExpression(
                    'Psalm does not understand ' . get_class($stmt),
                    new CodeLocation($statementsChecker->getSource(), $stmt)
                ),
                $statementsChecker->getSuppressedIssues()
            )) {
                return false;
            }
        }

        if (!$context->insideConditional
            && ($stmt instanceof PhpParser\Node\Expr\BinaryOp
                || $stmt instanceof PhpParser\Node\Expr\Instanceof_
                || $stmt instanceof PhpParser\Node\Expr\Assign
                || $stmt instanceof PhpParser\Node\Expr\BooleanNot
                || $stmt instanceof PhpParser\Node\Expr\Empty_
                || $stmt instanceof PhpParser\Node\Expr\Isset_
                || $stmt instanceof PhpParser\Node\Expr\FuncCall)
        ) {
            AssertionFinder::scrapeAssertions(
                $stmt,
                $context->self,
                $statementsChecker
            );
        }

        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;

        $pluginClasses = $projectChecker->config->afterExpressionChecks;

        if ($pluginClasses) {
            $fileManipulations = [];
            $codeLocation = new CodeLocation($statementsChecker->getSource(), $stmt);

            foreach ($pluginClasses as $pluginFqClassName) {
                if ($pluginFqClassName::afterExpressionCheck(
                    $statementsChecker,
                    $stmt,
                    $context,
                    $codeLocation,
                    $statementsChecker->getSuppressedIssues(),
                    $fileManipulations
                ) === false) {
                    return false;
                }
            }

            if ($fileManipulations) {
                /** @psalm-suppress MixedTypeCoercion */
                FileManipulationBuffer::add($statementsChecker->getFilePath(), $fileManipulations);
            }
        }

        return null;
    }

    /**
     * @param  StatementsChecker    $statementsChecker
     * @param  PhpParser\Node\Expr  $stmt
     * @param  Type\Union           $byRefType
     * @param  Context              $context
     * @param  bool                 $constrainType
     *
     * @return void
     */
    public static function assignByRefParam(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr $stmt,
        Type\Union $byRefType,
        Context $context,
        $constrainType = true
    ) {
        $varId = self::getVarId(
            $stmt,
            $statementsChecker->getFQCLN(),
            $statementsChecker
        );

        if ($varId) {
            if (!$byRefType->isMixed() && $constrainType) {
                $context->byrefConstraints[$varId] = new \Psalm\ReferenceConstraint($byRefType);
            }

            if (!$context->hasVariable($varId, $statementsChecker)) {
                $context->varsPossiblyInScope[$varId] = true;

                if (!$statementsChecker->hasVariable($varId)) {
                    $location = new CodeLocation($statementsChecker, $stmt);
                    $statementsChecker->registerVariable($varId, $location, null);

                    if ($context->collectReferences) {
                        $context->unreferencedVars[$varId] = [$location->getHash() => $location];
                    }

                    $context->hasVariable($varId, $statementsChecker);
                }
            } else {
                $existingType = $context->varsInScope[$varId];

                // removes dependennt vars from $context
                $context->removeDescendents(
                    $varId,
                    $existingType,
                    $byRefType,
                    $statementsChecker
                );

                if ($existingType->getId() !== 'array<empty, empty>') {
                    $context->varsInScope[$varId] = $byRefType;
                    $stmt->inferredType = $context->varsInScope[$varId];

                    return;
                }
            }

            $context->varsInScope[$varId] = $byRefType;
        }

        $stmt->inferredType = $byRefType;
    }

    /**
     * @param  PhpParser\Node\Expr      $stmt
     * @param  string|null              $thisClassName
     * @param  FileSource|null    $source
     * @param  int|null                 &$nesting
     *
     * @return string|null
     */
    public static function getVarId(
        PhpParser\Node\Expr $stmt,
        $thisClassName,
        FileSource $source = null,
        &$nesting = null
    ) {
        if ($stmt instanceof PhpParser\Node\Expr\Variable && is_string($stmt->name)) {
            return '$' . $stmt->name;
        }

        if ($stmt instanceof PhpParser\Node\Expr\StaticPropertyFetch
            && $stmt->name instanceof PhpParser\Node\Identifier
            && $stmt->class instanceof PhpParser\Node\Name
        ) {
            if (count($stmt->class->parts) === 1
                && in_array(strtolower($stmt->class->parts[0]), ['self', 'static', 'parent'], true)
            ) {
                if (!$thisClassName) {
                    $fqClassName = $stmt->class->parts[0];
                } else {
                    $fqClassName = $thisClassName;
                }
            } else {
                $fqClassName = $source
                    ? ClassLikeChecker::getFQCLNFromNameObject(
                        $stmt->class,
                        $source->getAliases()
                    )
                    : implode('\\', $stmt->class->parts);
            }

            return $fqClassName . '::$' . $stmt->name->name;
        }

        if ($stmt instanceof PhpParser\Node\Expr\PropertyFetch && $stmt->name instanceof PhpParser\Node\Identifier) {
            $objectId = self::getVarId($stmt->var, $thisClassName, $source);

            if (!$objectId) {
                return null;
            }

            return $objectId . '->' . $stmt->name->name;
        }

        if ($stmt instanceof PhpParser\Node\Expr\ArrayDimFetch && $nesting !== null) {
            ++$nesting;

            return self::getVarId($stmt->var, $thisClassName, $source, $nesting);
        }

        return null;
    }

    /**
     * @param  PhpParser\Node\Expr      $stmt
     * @param  string|null              $thisClassName
     * @param  FileSource|null    $source
     *
     * @return string|null
     */
    public static function getRootVarId(
        PhpParser\Node\Expr $stmt,
        $thisClassName,
        FileSource $source = null
    ) {
        if ($stmt instanceof PhpParser\Node\Expr\Variable
            || $stmt instanceof PhpParser\Node\Expr\StaticPropertyFetch
        ) {
            return self::getVarId($stmt, $thisClassName, $source);
        }

        if ($stmt instanceof PhpParser\Node\Expr\PropertyFetch && $stmt->name instanceof PhpParser\Node\Identifier) {
            $propertyRoot = self::getRootVarId($stmt->var, $thisClassName, $source);

            if ($propertyRoot) {
                return $propertyRoot . '->' . $stmt->name->name;
            }
        }

        if ($stmt instanceof PhpParser\Node\Expr\ArrayDimFetch) {
            return self::getRootVarId($stmt->var, $thisClassName, $source);
        }

        return null;
    }

    /**
     * @param  PhpParser\Node\Expr      $stmt
     * @param  string|null              $thisClassName
     * @param  FileSource|null    $source
     *
     * @return string|null
     */
    public static function getArrayVarId(
        PhpParser\Node\Expr $stmt,
        $thisClassName,
        FileSource $source = null
    ) {
        if ($stmt instanceof PhpParser\Node\Expr\Assign) {
            return self::getArrayVarId($stmt->var, $thisClassName, $source);
        }

        if ($stmt instanceof PhpParser\Node\Expr\ArrayDimFetch) {
            $rootVarId = self::getArrayVarId($stmt->var, $thisClassName, $source);

            $offset = null;

            if ($rootVarId) {
                if ($stmt->dim instanceof PhpParser\Node\Scalar\String_
                    || $stmt->dim instanceof PhpParser\Node\Scalar\LNumber
                ) {
                    $offset = $stmt->dim instanceof PhpParser\Node\Scalar\String_
                        ? '\'' . $stmt->dim->value . '\''
                        : $stmt->dim->value;
                } elseif ($stmt->dim instanceof PhpParser\Node\Expr\Variable
                    && is_string($stmt->dim->name)
                ) {
                    $offset = '$' . $stmt->dim->name;
                } elseif ($stmt->dim instanceof PhpParser\Node\Expr\ConstFetch) {
                    $offset = implode('\\', $stmt->dim->name->parts);
                }

                return $rootVarId && $offset !== null ? $rootVarId . '[' . $offset . ']' : null;
            }
        }

        if ($stmt instanceof PhpParser\Node\Expr\PropertyFetch) {
            $objectId = self::getArrayVarId($stmt->var, $thisClassName, $source);

            if (!$objectId) {
                return null;
            }

            if ($stmt->name instanceof PhpParser\Node\Identifier) {
                return $objectId . '->' . $stmt->name;
            } elseif (isset($stmt->name->inferredType) && $stmt->name->inferredType->isSingleStringLiteral()) {
                return $objectId . '->' . $stmt->name->inferredType->getSingleStringLiteral()->value;
            } else {
                return null;
            }
        }

        if ($stmt instanceof PhpParser\Node\Expr\MethodCall
            && $stmt->name instanceof PhpParser\Node\Identifier
            && !$stmt->args
        ) {
            $config = \Psalm\Config::getInstance();

            if ($config->memoizeMethodCalls) {
                $lhsVarName = self::getArrayVarId(
                    $stmt->var,
                    $thisClassName,
                    $source
                );

                if (!$lhsVarName) {
                    return null;
                }

                return $lhsVarName . '->' . strtolower($stmt->name->name) . '()';
            }
        }

        return self::getVarId($stmt, $thisClassName, $source);
    }

    /**
     * @param  Type\Union   $returnType
     * @param  string|null  $selfClass
     * @param  string|null  $staticClass
     *
     * @return Type\Union
     */
    public static function fleshOutType(
        ProjectChecker $projectChecker,
        Type\Union $returnType,
        $selfClass = null,
        $staticClass = null
    ) {
        $returnType = clone $returnType;

        $newReturnTypeParts = [];

        foreach ($returnType->getTypes() as $returnTypePart) {
            $newReturnTypeParts[] = self::fleshOutAtomicType(
                $projectChecker,
                $returnTypePart,
                $selfClass,
                $staticClass
            );
        }

        $fleshedOutType = new Type\Union($newReturnTypeParts);

        $fleshedOutType->fromDocblock = $returnType->fromDocblock;
        $fleshedOutType->ignoreNullableIssues = $returnType->ignoreNullableIssues;
        $fleshedOutType->ignoreFalsableIssues = $returnType->ignoreFalsableIssues;
        $fleshedOutType->possiblyUndefined = $returnType->possiblyUndefined;
        $fleshedOutType->byRef = $returnType->byRef;
        $fleshedOutType->initialized = $returnType->initialized;

        return $fleshedOutType;
    }

    /**
     * @param  Type\Atomic  &$returnType
     * @param  string|null  $selfClass
     * @param  string|null  $staticClass
     *
     * @return Type\Atomic
     */
    private static function fleshOutAtomicType(
        ProjectChecker $projectChecker,
        Type\Atomic $returnType,
        $selfClass,
        $staticClass
    ) {
        if ($returnType instanceof TNamedObject) {
            $returnTypeLc = strtolower($returnType->value);

            if ($returnTypeLc === 'static' || $returnTypeLc === '$this') {
                if (!$staticClass) {
                    throw new \UnexpectedValueException(
                        'Cannot handle ' . $returnType->value . ' when $staticClass is empty'
                    );
                }

                $returnType->value = $staticClass;
            } elseif ($returnTypeLc === 'self') {
                if (!$selfClass) {
                    throw new \UnexpectedValueException(
                        'Cannot handle ' . $returnType->value . ' when $selfClass is empty'
                    );
                }

                $returnType->value = $selfClass;
            }
        }

        if ($returnType instanceof Type\Atomic\TScalarClassConstant) {
            if ($returnType->fqClasslikeName === 'self' && $selfClass) {
                $returnType->fqClasslikeName = $selfClass;
            }

            if ($projectChecker->codebase->classOrInterfaceExists($returnType->fqClasslikeName)) {
                if (strtolower($returnType->constName) === 'class') {
                    return new Type\Atomic\TLiteralClassString($returnType->fqClasslikeName);
                }

                $classConstants = $projectChecker->codebase->classlikes->getConstantsForClass(
                    $returnType->fqClasslikeName,
                    \ReflectionProperty::IS_PRIVATE
                );

                if (isset($classConstants[$returnType->constName])) {
                    $constType = $classConstants[$returnType->constName];

                    if ($constType->isSingle()) {
                        $constType = clone $constType;

                        return array_values($constType->getTypes())[0];
                    }
                }
            }

            return new TMixed();
        }

        if ($returnType instanceof Type\Atomic\TArray || $returnType instanceof Type\Atomic\TGenericObject) {
            foreach ($returnType->typeParams as &$typeParam) {
                $typeParam = self::fleshOutType(
                    $projectChecker,
                    $typeParam,
                    $selfClass,
                    $staticClass
                );
            }
        } elseif ($returnType instanceof Type\Atomic\ObjectLike) {
            foreach ($returnType->properties as &$propertyType) {
                $propertyType = self::fleshOutType(
                    $projectChecker,
                    $propertyType,
                    $selfClass,
                    $staticClass
                );
            }
        }

        return $returnType;
    }

    /**
     * @param   StatementsChecker           $statementsChecker
     * @param   PhpParser\Node\Expr\Closure $stmt
     * @param   Context                     $context
     *
     * @return  false|null
     */
    protected static function analyzeClosureUses(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\Closure $stmt,
        Context $context
    ) {
        foreach ($stmt->uses as $use) {
            if (!is_string($use->var->name)) {
                continue;
            }

            $useVarId = '$' . $use->var->name;

            if (!$context->hasVariable($useVarId, $statementsChecker)) {
                if ($useVarId === '$argv' || $useVarId === '$argc') {
                    continue;
                }

                if ($use->byRef) {
                    $context->varsInScope[$useVarId] = Type::getMixed();
                    $context->varsPossiblyInScope[$useVarId] = true;

                    if (!$statementsChecker->hasVariable($useVarId)) {
                        $statementsChecker->registerVariable(
                            $useVarId,
                            new CodeLocation($statementsChecker, $use->var),
                            null
                        );
                    }

                    return;
                }

                if (!isset($context->varsPossiblyInScope[$useVarId])) {
                    if ($context->checkVariables) {
                        if (IssueBuffer::accepts(
                            new UndefinedVariable(
                                'Cannot find referenced variable ' . $useVarId,
                                new CodeLocation($statementsChecker->getSource(), $use->var)
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            return false;
                        }

                        return null;
                    }
                }

                $firstAppearance = $statementsChecker->getFirstAppearance($useVarId);

                if ($firstAppearance) {
                    if (IssueBuffer::accepts(
                        new PossiblyUndefinedVariable(
                            'Possibly undefined variable ' . $useVarId . ', first seen on line ' .
                                $firstAppearance->getLineNumber(),
                            new CodeLocation($statementsChecker->getSource(), $use->var)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }

                    continue;
                }

                if ($context->checkVariables) {
                    if (IssueBuffer::accepts(
                        new UndefinedVariable(
                            'Cannot find referenced variable ' . $useVarId,
                            new CodeLocation($statementsChecker->getSource(), $use->var)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }

                    continue;
                }
            } elseif ($use->byRef) {
                foreach ($context->varsInScope[$useVarId]->getTypes() as $atomicType) {
                    if ($atomicType instanceof Type\Atomic\TLiteralInt) {
                        $context->varsInScope[$useVarId]->addType(new Type\Atomic\TInt);
                    } elseif ($atomicType instanceof Type\Atomic\TLiteralFloat) {
                        $context->varsInScope[$useVarId]->addType(new Type\Atomic\TFloat);
                    } elseif ($atomicType instanceof Type\Atomic\TLiteralString) {
                        $context->varsInScope[$useVarId]->addType(new Type\Atomic\TString);
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param   StatementsChecker           $statementsChecker
     * @param   PhpParser\Node\Expr\Yield_  $stmt
     * @param   Context                     $context
     *
     * @return  false|null
     */
    protected static function analyzeYield(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\Yield_ $stmt,
        Context $context
    ) {
        $docCommentText = (string)$stmt->getDocComment();

        $varComments = [];
        $varCommentType = null;

        if ($docCommentText) {
            try {
                $varComments = CommentChecker::getTypeFromComment(
                    $docCommentText,
                    $statementsChecker,
                    $statementsChecker->getAliases()
                );
            } catch (DocblockParseException $e) {
                if (IssueBuffer::accepts(
                    new InvalidDocblock(
                        (string)$e->getMessage(),
                        new CodeLocation($statementsChecker->getSource(), $stmt)
                    )
                )) {
                    // fall through
                }
            }

            foreach ($varComments as $varComment) {
                $commentType = ExpressionChecker::fleshOutType(
                    $statementsChecker->getFileChecker()->projectChecker,
                    $varComment->type,
                    $context->self,
                    $context->self
                );

                if (!$varComment->varId) {
                    $varCommentType = $commentType;
                    continue;
                }

                $context->varsInScope[$varComment->varId] = $commentType;
            }
        }

        if ($stmt->key) {
            if (self::analyze($statementsChecker, $stmt->key, $context) === false) {
                return false;
            }
        }

        if ($stmt->value) {
            if (self::analyze($statementsChecker, $stmt->value, $context) === false) {
                return false;
            }

            if ($varCommentType) {
                $stmt->inferredType = $varCommentType;
            } elseif (isset($stmt->value->inferredType)) {
                $stmt->inferredType = $stmt->value->inferredType;
            } else {
                $stmt->inferredType = Type::getMixed();
            }
        } else {
            $stmt->inferredType = Type::getNull();
        }

        return null;
    }

    /**
     * @param   StatementsChecker               $statementsChecker
     * @param   PhpParser\Node\Expr\YieldFrom   $stmt
     * @param   Context                         $context
     *
     * @return  false|null
     */
    protected static function analyzeYieldFrom(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\YieldFrom $stmt,
        Context $context
    ) {
        if (self::analyze($statementsChecker, $stmt->expr, $context) === false) {
            return false;
        }

        if (isset($stmt->expr->inferredType)) {
            $yieldFromType = null;

            foreach ($stmt->expr->inferredType->getTypes() as $atomicType) {
                if ($yieldFromType === null
                    && $atomicType instanceof Type\Atomic\TGenericObject
                    && strtolower($atomicType->value) === 'generator'
                    && isset($atomicType->typeParams[3])
                ) {
                    $yieldFromType = clone $atomicType->typeParams[3];
                } else {
                    $yieldFromType = Type::getMixed();
                }
            }

            // this should be whatever the generator above returns, but *not* the return type
            $stmt->inferredType = $yieldFromType ?: Type::getMixed();
        }

        return null;
    }

    /**
     * @param   StatementsChecker               $statementsChecker
     * @param   PhpParser\Node\Expr\BooleanNot  $stmt
     * @param   Context                         $context
     *
     * @return  false|null
     */
    protected static function analyzeBooleanNot(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\BooleanNot $stmt,
        Context $context
    ) {
        $stmt->inferredType = Type::getBool();

        if (self::analyze($statementsChecker, $stmt->expr, $context) === false) {
            return false;
        }
    }

    /**
     * @param   StatementsChecker           $statementsChecker
     * @param   PhpParser\Node\Expr\Empty_  $stmt
     * @param   Context                     $context
     *
     * @return  void
     */
    protected static function analyzeEmpty(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\Empty_ $stmt,
        Context $context
    ) {
        self::analyzeIssetVar($statementsChecker, $stmt->expr, $context);
        $stmt->inferredType = Type::getBool();
    }

    /**
     * @param   StatementsChecker               $statementsChecker
     * @param   PhpParser\Node\Scalar\Encapsed  $stmt
     * @param   Context                         $context
     *
     * @return  false|null
     */
    protected static function analyzeEncapsulatedString(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Scalar\Encapsed $stmt,
        Context $context
    ) {
        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;

        $functionStorage = null;

        if ($projectChecker->inferTypesFromUsage) {
            $sourceChecker = $statementsChecker->getSource();

            if ($sourceChecker instanceof FunctionLikeChecker) {
                $functionStorage = $sourceChecker->getFunctionLikeStorage($statementsChecker);
            }
        }

        /** @var PhpParser\Node\Expr $part */
        foreach ($stmt->parts as $part) {
            if (self::analyze($statementsChecker, $part, $context) === false) {
                return false;
            }

            if ($functionStorage) {
                $context->inferType($part, $functionStorage, Type::getString());
            }
        }

        $stmt->inferredType = Type::getString();

        return null;
    }

    /**
     * @param  StatementsChecker          $statementsChecker
     * @param  PhpParser\Node\Expr\Isset_ $stmt
     * @param  Context                    $context
     *
     * @return void
     */
    protected static function analyzeIsset(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\Isset_ $stmt,
        Context $context
    ) {
        foreach ($stmt->vars as $issetVar) {
            if ($issetVar instanceof PhpParser\Node\Expr\PropertyFetch
                && $issetVar->var instanceof PhpParser\Node\Expr\Variable
                && $issetVar->var->name === 'this'
                && $issetVar->name instanceof PhpParser\Node\Identifier
            ) {
                $varId = '$this->' . $issetVar->name->name;

                if (!isset($context->varsInScope[$varId])) {
                    $context->varsInScope[$varId] = Type::getMixed();
                    $context->varsPossiblyInScope[$varId] = true;
                }
            }

            self::analyzeIssetVar($statementsChecker, $issetVar, $context);
        }

        $stmt->inferredType = Type::getBool();
    }

    /**
     * @param  StatementsChecker   $statementsChecker
     * @param  PhpParser\Node\Expr $stmt
     * @param  Context             $context
     *
     * @return false|null
     */
    protected static function analyzeIssetVar(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr $stmt,
        Context $context
    ) {

        $context->insideIsset = true;

        if (self::analyze($statementsChecker, $stmt, $context) === false) {
            return false;
        }

        $context->insideIsset = false;
    }

    /**
     * @param  StatementsChecker            $statementsChecker
     * @param  PhpParser\Node\Expr\Clone_   $stmt
     * @param  Context                      $context
     *
     * @return false|null
     */
    protected static function analyzeClone(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\Clone_ $stmt,
        Context $context
    ) {
        if (self::analyze($statementsChecker, $stmt->expr, $context) === false) {
            return false;
        }

        if (isset($stmt->expr->inferredType)) {
            foreach ($stmt->expr->inferredType->getTypes() as $cloneTypePart) {
                if (!$cloneTypePart instanceof TNamedObject
                    && !$cloneTypePart instanceof TObject
                    && !$cloneTypePart instanceof TMixed
                    && !$cloneTypePart instanceof TGenericParam
                ) {
                    if (IssueBuffer::accepts(
                        new InvalidClone(
                            'Cannot clone ' . $cloneTypePart,
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }

                    return;
                }
            }

            $stmt->inferredType = $stmt->expr->inferredType;
        }
    }

    /**
     * @param  string  $fqClassName
     *
     * @return bool
     */
    public static function isMock($fqClassName)
    {
        return in_array($fqClassName, Config::getInstance()->getMockClasses(), true);
    }
}
