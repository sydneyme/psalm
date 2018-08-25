<?php
namespace Psalm\Checker;

use PhpParser;
use Psalm\Checker\Statements\Block\DoChecker;
use Psalm\Checker\Statements\Block\ForChecker;
use Psalm\Checker\Statements\Block\ForeachChecker;
use Psalm\Checker\Statements\Block\IfChecker;
use Psalm\Checker\Statements\Block\SwitchChecker;
use Psalm\Checker\Statements\Block\TryChecker;
use Psalm\Checker\Statements\Block\WhileChecker;
use Psalm\Checker\Statements\Expression\Assignment\PropertyAssignmentChecker;
use Psalm\Checker\Statements\Expression\BinaryOpChecker;
use Psalm\Checker\Statements\Expression\CallChecker;
use Psalm\Checker\Statements\Expression\Fetch\ConstFetchChecker;
use Psalm\Checker\Statements\Expression\Fetch\VariableFetchChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\Statements\ReturnChecker;
use Psalm\Checker\Statements\ThrowChecker;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\Context;
use Psalm\Exception\DocblockParseException;
use Psalm\FileManipulation\FileManipulation;
use Psalm\FileManipulation\FileManipulationBuffer;
use Psalm\Issue\ContinueOutsideLoop;
use Psalm\Issue\InvalidDocblock;
use Psalm\Issue\InvalidGlobal;
use Psalm\Issue\UnevaluatedCode;
use Psalm\Issue\UnrecognizedStatement;
use Psalm\Issue\UnusedVariable;
use Psalm\IssueBuffer;
use Psalm\Scope\LoopScope;
use Psalm\StatementsSource;
use Psalm\Type;

class StatementsChecker extends SourceChecker implements StatementsSource
{
    /**
     * @var StatementsSource
     */
    protected $source;

    /**
     * @var FileChecker
     */
    protected $fileChecker;

    /**
     * @var array<string, CodeLocation>
     */
    private $allVars = [];

    /**
     * @var array<string, int>
     */
    private $varBranchPoints = [];

    /**
     * Possibly undefined variables should be initialised if we're altering code
     *
     * @var array<string, int>|null
     */
    private $varsToInitialize;

    /**
     * @var array<string, FunctionChecker>
     */
    private $functionCheckers = [];

    /**
     * @var array<string, array{0: string, 1: CodeLocation}>
     */
    private $unusedVarLocations = [];

    /**
     * @var array<string, bool>
     */
    private $usedVarLocations = [];

    /**
     * @param StatementsSource $source
     */
    public function __construct(StatementsSource $source)
    {
        $this->source = $source;
        $this->fileChecker = $source->getFileChecker();
    }

    /**
     * Checks an array of statements for validity
     *
     * @param  array<PhpParser\Node\Stmt>   $stmts
     * @param  Context                                          $context
     * @param  Context|null                                     $globalContext
     * @param  bool                                             $rootScope
     *
     * @return null|false
     */
    public function analyze(
        array $stmts,
        Context $context,
        Context $globalContext = null,
        $rootScope = false
    ) {
        $hasReturned = false;

        // hoist functions to the top
        foreach ($stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\Function_) {
                $functionChecker = new FunctionChecker($stmt, $this->source);
                $this->functionCheckers[strtolower($stmt->name->name)] = $functionChecker;
            }
        }

        $projectChecker = $this->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;

        if ($codebase->config->hoistConstants) {
            foreach ($stmts as $stmt) {
                if ($stmt instanceof PhpParser\Node\Stmt\Const_) {
                    foreach ($stmt->consts as $const) {
                        $this->setConstType(
                            $const->name->name,
                            self::getSimpleType($codebase, $const->value, $this->getAliases(), $this)
                                ?: Type::getMixed(),
                            $context
                        );
                    }
                } elseif ($stmt instanceof PhpParser\Node\Stmt\Expression
                    && $stmt->expr instanceof PhpParser\Node\Expr\FuncCall
                    && $stmt->expr->name instanceof PhpParser\Node\Name
                    && $stmt->expr->name->parts === ['define']
                    && isset($stmt->expr->args[1])
                    && $stmt->expr->args[0]->value instanceof PhpParser\Node\Scalar\String_
                ) {
                    $constName = $stmt->expr->args[0]->value->value;

                    $this->setConstType(
                        $constName,
                        self::getSimpleType($codebase, $stmt->expr->args[1]->value, $this->getAliases(), $this)
                            ?: Type::getMixed(),
                        $context
                    );
                }
            }
        }

        $originalContext = null;

        if ($context->loopScope) {
            $originalContext = clone $context;
        }

        $pluginClasses = $codebase->config->afterStatementChecks;

        foreach ($stmts as $stmt) {
            if ($hasReturned && !($stmt instanceof PhpParser\Node\Stmt\Nop) &&
                !($stmt instanceof PhpParser\Node\Stmt\InlineHTML)
            ) {
                if ($context->collectReferences) {
                    if (IssueBuffer::accepts(
                        new UnevaluatedCode(
                            'Expressions after return/throw/continue',
                            new CodeLocation($this->source, $stmt)
                        ),
                        $this->source->getSuppressedIssues()
                    )) {
                        return false;
                    }
                }
                break;
            }

            if ($projectChecker->debugLines) {
                echo $this->getFilePath() . ':' . $stmt->getLine() . "\n";
            }

            /*
            if (isset($context->varsInScope['$array']) && !$stmt instanceof PhpParser\Node\Stmt\Nop) {
                var_dump($stmt->getLine(), $context->varsInScope['$array']);
            }
            */

            $newIssues = null;

            if ($docblock = $stmt->getDocComment()) {
                $comments = CommentChecker::parseDocComment((string)$docblock);
                if (isset($comments['specials']['psalm-suppress'])) {
                    $suppressed = array_filter(
                        array_map(
                            /**
                             * @param string $line
                             *
                             * @return string
                             */
                            function ($line) {
                                return explode(' ', trim($line))[0];
                            },
                            $comments['specials']['psalm-suppress']
                        )
                    );

                    if ($suppressed) {
                        $newIssues = array_diff($suppressed, $this->source->getSuppressedIssues());
                        /** @psalm-suppress MixedTypeCoercion */
                        $this->addSuppressedIssues($newIssues);
                    }
                }
            }

            if ($stmt instanceof PhpParser\Node\Stmt\If_) {
                if (IfChecker::analyze($this, $stmt, $context) === false) {
                    return false;
                }
            } elseif ($stmt instanceof PhpParser\Node\Stmt\TryCatch) {
                if (TryChecker::analyze($this, $stmt, $context) === false) {
                    return false;
                }
            } elseif ($stmt instanceof PhpParser\Node\Stmt\For_) {
                if (ForChecker::analyze($this, $stmt, $context) === false) {
                    return false;
                }
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Foreach_) {
                if (ForeachChecker::analyze($this, $stmt, $context) === false) {
                    return false;
                }
            } elseif ($stmt instanceof PhpParser\Node\Stmt\While_) {
                if (WhileChecker::analyze($this, $stmt, $context) === false) {
                    return false;
                }
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Do_) {
                DoChecker::analyze($this, $stmt, $context);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Const_) {
                $this->analyzeConstAssignment($stmt, $context);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Unset_) {
                $context->insideUnset = true;

                foreach ($stmt->vars as $var) {
                    ExpressionChecker::analyze($this, $var, $context);

                    $varId = ExpressionChecker::getArrayVarId(
                        $var,
                        $this->getFQCLN(),
                        $this
                    );

                    if ($varId) {
                        $context->remove($varId);

                        if ($var instanceof PhpParser\Node\Expr\ArrayDimFetch
                            && $var->dim
                            && ($var->dim instanceof PhpParser\Node\Scalar\String_
                                || $var->dim instanceof PhpParser\Node\Scalar\LNumber
                            )
                        ) {
                            $rootVarId = ExpressionChecker::getArrayVarId(
                                $var->var,
                                $this->getFQCLN(),
                                $this
                            );

                            if ($rootVarId && isset($context->varsInScope[$rootVarId])) {
                                $rootType = clone $context->varsInScope[$rootVarId];

                                foreach ($rootType->getTypes() as $atomicRootType) {
                                    if ($atomicRootType instanceof Type\Atomic\ObjectLike) {
                                        if (isset($atomicRootType->properties[$var->dim->value])) {
                                            unset($atomicRootType->properties[$var->dim->value]);
                                        }

                                        if (!$atomicRootType->properties) {
                                            $rootType->addType(
                                                new Type\Atomic\TArray([
                                                    new Type\Union([new Type\Atomic\TEmpty]),
                                                    new Type\Union([new Type\Atomic\TEmpty]),
                                                ])
                                            );
                                        }
                                    }
                                }

                                $context->varsInScope[$rootVarId] = $rootType;
                            }
                        }
                    }
                }

                $context->insideUnset = false;
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Return_) {
                $hasReturned = true;
                ReturnChecker::analyze($this, $projectChecker, $stmt, $context);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Throw_) {
                $hasReturned = true;
                ThrowChecker::analyze($this, $stmt, $context);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Switch_) {
                SwitchChecker::analyze($this, $stmt, $context);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Break_) {
                $loopScope = $context->loopScope;
                if ($loopScope && $originalContext) {
                    $loopScope->finalActions[] = ScopeChecker::ACTION_BREAK;

                    $redefinedVars = $context->getRedefinedVars($loopScope->loopParentContext->varsInScope);

                    if ($loopScope->possiblyRedefinedLoopParentVars === null) {
                        $loopScope->possiblyRedefinedLoopParentVars = $redefinedVars;
                    } else {
                        foreach ($redefinedVars as $var => $type) {
                            if ($type->isMixed()) {
                                $loopScope->possiblyRedefinedLoopParentVars[$var] = $type;
                            } elseif (isset($loopScope->possiblyRedefinedLoopParentVars[$var])) {
                                $loopScope->possiblyRedefinedLoopParentVars[$var] = Type::combineUnionTypes(
                                    $type,
                                    $loopScope->possiblyRedefinedLoopParentVars[$var]
                                );
                            } else {
                                $loopScope->possiblyRedefinedLoopParentVars[$var] = $type;
                            }
                        }
                    }

                    if ($context->collectReferences && (!$context->switchScope || $stmt->num)) {
                        foreach ($context->unreferencedVars as $varId => $locations) {
                            if (isset($loopScope->unreferencedVars[$varId])) {
                                $loopScope->unreferencedVars[$varId] += $locations;
                            } else {
                                $loopScope->unreferencedVars[$varId] = $locations;
                            }
                        }
                    }
                }

                $switchScope = $context->switchScope;
                if ($switchScope && $context->collectReferences) {
                    foreach ($context->unreferencedVars as $varId => $locations) {
                        if (isset($switchScope->unreferencedVars[$varId])) {
                            $switchScope->unreferencedVars[$varId] += $locations;
                        } else {
                            $switchScope->unreferencedVars[$varId] = $locations;
                        }
                    }
                }

                $hasReturned = true;
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Continue_) {
                $loopScope = $context->loopScope;
                if ($loopScope === null) {
                    if (!$context->insideCase) {
                        if (IssueBuffer::accepts(
                            new ContinueOutsideLoop(
                                'Continue call outside loop context',
                                new CodeLocation($this->source, $stmt)
                            ),
                            $this->source->getSuppressedIssues()
                        )) {
                            return false;
                        }
                    }
                } elseif ($originalContext) {
                    $loopScope->finalActions[] = ScopeChecker::ACTION_CONTINUE;

                    $redefinedVars = $context->getRedefinedVars($originalContext->varsInScope);

                    if ($loopScope->redefinedLoopVars === null) {
                        $loopScope->redefinedLoopVars = $redefinedVars;
                    } else {
                        foreach ($loopScope->redefinedLoopVars as $redefinedVar => $type) {
                            if (!isset($redefinedVars[$redefinedVar])) {
                                unset($loopScope->redefinedLoopVars[$redefinedVar]);
                            } else {
                                $loopScope->redefinedLoopVars[$redefinedVar] = Type::combineUnionTypes(
                                    $redefinedVars[$redefinedVar],
                                    $type
                                );
                            }
                        }
                    }

                    foreach ($redefinedVars as $var => $type) {
                        if ($type->isMixed()) {
                            $loopScope->possiblyRedefinedLoopVars[$var] = $type;
                        } elseif (isset($loopScope->possiblyRedefinedLoopVars[$var])) {
                            $loopScope->possiblyRedefinedLoopVars[$var] = Type::combineUnionTypes(
                                $type,
                                $loopScope->possiblyRedefinedLoopVars[$var]
                            );
                        } else {
                            $loopScope->possiblyRedefinedLoopVars[$var] = $type;
                        }
                    }

                    if ($context->collectReferences && (!$context->switchScope || $stmt->num)) {
                        foreach ($context->unreferencedVars as $varId => $locations) {
                            if (isset($loopScope->possiblyUnreferencedVars[$varId])) {
                                $loopScope->possiblyUnreferencedVars[$varId] += $locations;
                            } else {
                                $loopScope->possiblyUnreferencedVars[$varId] = $locations;
                            }
                        }
                    }
                }

                $switchScope = $context->switchScope;
                if ($switchScope && $context->collectReferences) {
                    foreach ($context->unreferencedVars as $varId => $locations) {
                        if (isset($switchScope->unreferencedVars[$varId])) {
                            $switchScope->unreferencedVars[$varId] += $locations;
                        } else {
                            $switchScope->unreferencedVars[$varId] = $locations;
                        }
                    }
                }

                $hasReturned = true;
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Static_) {
                $this->analyzeStatic($stmt, $context);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Echo_) {
                foreach ($stmt->exprs as $i => $expr) {
                    ExpressionChecker::analyze($this, $expr, $context);

                    if (isset($expr->inferredType)) {
                        if (CallChecker::checkFunctionArgumentType(
                            $this,
                            $expr->inferredType,
                            Type::getString(),
                            'echo',
                            (int)$i,
                            new CodeLocation($this->getSource(), $expr),
                            $expr,
                            $context
                        ) === false) {
                            return false;
                        }
                    }
                }
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Function_) {
                foreach ($stmt->stmts as $functionStmt) {
                    if ($functionStmt instanceof PhpParser\Node\Stmt\Global_) {
                        foreach ($functionStmt->vars as $var) {
                            if ($var instanceof PhpParser\Node\Expr\Variable) {
                                if (is_string($var->name)) {
                                    $varId = '$' . $var->name;

                                    // registers variable in global context
                                    $context->hasVariable($varId, $this);
                                }
                            }
                        }
                    } elseif (!$functionStmt instanceof PhpParser\Node\Stmt\Nop) {
                        break;
                    }
                }

                if (!$projectChecker->codebase->registerStubFiles
                    && !$projectChecker->codebase->registerAutoloadFiles
                ) {
                    $functionId = strtolower($stmt->name->name);
                    $functionContext = new Context($context->self);
                    $config = Config::getInstance();
                    $functionContext->collectReferences = $projectChecker->codebase->collectReferences;
                    $functionContext->collectExceptions = $config->checkForThrowsDocblock;
                    $this->functionCheckers[$functionId]->analyze($functionContext, $context);

                    if ($config->reportIssueInFile('InvalidReturnType', $this->getFilePath())) {
                        $methodId = $this->functionCheckers[$functionId]->getMethodId();

                        $functionStorage = $codebase->functions->getStorage(
                            $this,
                            $methodId
                        );

                        $returnType = $functionStorage->returnType;
                        $returnTypeLocation = $functionStorage->returnTypeLocation;

                        $this->functionCheckers[$functionId]->verifyReturnType(
                            $returnType,
                            $this->getFQCLN(),
                            $returnTypeLocation
                        );
                    }
                }
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Expression) {
                if (ExpressionChecker::analyze($this, $stmt->expr, $context, false, $globalContext) === false) {
                    return false;
                }
            } elseif ($stmt instanceof PhpParser\Node\Stmt\InlineHTML) {
                // do nothing
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Global_) {
                if (!$context->collectInitializations && !$globalContext) {
                    if (IssueBuffer::accepts(
                        new InvalidGlobal(
                            'Cannot use global scope here',
                            new CodeLocation($this->source, $stmt)
                        ),
                        $this->source->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }

                $source = $this->getSource();
                $functionStorage = $source instanceof FunctionLikeChecker
                    ? $source->getFunctionLikeStorage($this)
                    : null;

                foreach ($stmt->vars as $var) {
                    if ($var instanceof PhpParser\Node\Expr\Variable) {
                        if (is_string($var->name)) {
                            $varId = '$' . $var->name;

                            if ($var->name === 'argv' || $var->name === 'argc') {
                                if ($var->name === 'argv') {
                                    $context->varsInScope[$varId] = new Type\Union([
                                        new Type\Atomic\TArray([
                                            Type::getInt(),
                                            Type::getString(),
                                        ]),
                                    ]);
                                } else {
                                    $context->varsInScope[$varId] = Type::getInt();
                                }
                            } elseif (isset($functionStorage->globalTypes[$varId])) {
                                $context->varsInScope[$varId] = clone $functionStorage->globalTypes[$varId];
                                $context->varsPossiblyInScope[$varId] = true;
                            } else {
                                $context->varsInScope[$varId] =
                                    $globalContext && $globalContext->hasVariable($varId, $this)
                                        ? clone $globalContext->varsInScope[$varId]
                                        : Type::getMixed();

                                $context->varsPossiblyInScope[$varId] = true;
                            }
                        }
                    }
                }
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    if ($prop->default) {
                        ExpressionChecker::analyze($this, $prop->default, $context);

                        if (isset($prop->default->inferredType)) {
                            if (!$stmt->isStatic()) {
                                if (PropertyAssignmentChecker::analyzeInstance(
                                    $this,
                                    $prop,
                                    $prop->name->name,
                                    $prop->default,
                                    $prop->default->inferredType,
                                    $context
                                ) === false) {
                                    // fall through
                                }
                            }
                        }
                    }
                }
            } elseif ($stmt instanceof PhpParser\Node\Stmt\ClassConst) {
                $constVisibility = \ReflectionProperty::IS_PUBLIC;

                if ($stmt->isProtected()) {
                    $constVisibility = \ReflectionProperty::IS_PROTECTED;
                }

                if ($stmt->isPrivate()) {
                    $constVisibility = \ReflectionProperty::IS_PRIVATE;
                }

                foreach ($stmt->consts as $const) {
                    ExpressionChecker::analyze($this, $const->value, $context);

                    if (isset($const->value->inferredType) && !$const->value->inferredType->isMixed()) {
                        $codebase->classlikes->setConstantType(
                            (string)$this->getFQCLN(),
                            $const->name->name,
                            $const->value->inferredType,
                            $constVisibility
                        );
                    }
                }
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Class_) {
                try {
                    $classChecker = new ClassChecker($stmt, $this->source, $stmt->name ? $stmt->name->name : null);
                    $classChecker->analyze(null, $globalContext);
                } catch (\InvalidArgumentException $e) {
                    // disregard this exception, we'll likely see it elsewhere in the form
                    // of an issue
                }
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Nop) {
                if ((string)$stmt->getDocComment()) {
                    $varComments = [];

                    try {
                        $varComments = CommentChecker::getTypeFromComment(
                            (string)$stmt->getDocComment(),
                            $this->getSource(),
                            $this->getSource()->getAliases()
                        );
                    } catch (DocblockParseException $e) {
                        if (IssueBuffer::accepts(
                            new InvalidDocblock(
                                (string)$e->getMessage(),
                                new CodeLocation($this->getSource(), $stmt, null, true)
                            )
                        )) {
                            // fall through
                        }
                    }

                    foreach ($varComments as $varComment) {
                        if (!$varComment->varId) {
                            continue;
                        }

                        $commentType = ExpressionChecker::fleshOutType(
                            $projectChecker,
                            $varComment->type,
                            $context->self
                        );

                        $context->varsInScope[$varComment->varId] = $commentType;
                    }
                }
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Goto_) {
                // do nothing
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Label) {
                // do nothing
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Declare_) {
                // do nothing
            } else {
                if (IssueBuffer::accepts(
                    new UnrecognizedStatement(
                        'Psalm does not understand ' . get_class($stmt),
                        new CodeLocation($this->source, $stmt)
                    ),
                    $this->getSuppressedIssues()
                )) {
                    return false;
                }
            }

            if ($context->loopScope
                && $context->loopScope->finalActions
                && !in_array(ScopeChecker::ACTION_NONE, $context->loopScope->finalActions, true)
            ) {
                //$hasReturned = true;
            }

            if ($pluginClasses) {
                $fileManipulations = [];
                $codeLocation = new CodeLocation($this->source, $stmt);

                foreach ($pluginClasses as $pluginFqClassName) {
                    if ($pluginFqClassName::afterStatementCheck(
                        $this,
                        $stmt,
                        $context,
                        $codeLocation,
                        $this->getSuppressedIssues(),
                        $fileManipulations
                    ) === false) {
                        return false;
                    }
                }

                if ($fileManipulations) {
                    /** @psalm-suppress MixedTypeCoercion */
                    FileManipulationBuffer::add($this->getFilePath(), $fileManipulations);
                }
            }

            if ($newIssues) {
                /** @psalm-suppress MixedTypeCoercion */
                $this->removeSuppressedIssues($newIssues);
            }
        }

        if ($rootScope
            && $context->collectReferences
            && !$context->collectInitializations
            && $projectChecker->codebase->findUnusedCode
            && $context->checkVariables
        ) {
            $this->checkUnreferencedVars();
        }

        if ($projectChecker->alterCode && $rootScope && $this->varsToInitialize) {
            $fileContents = $projectChecker->codebase->getFileContents($this->getFilePath());

            foreach ($this->varsToInitialize as $varId => $branchPoint) {
                $newlinePos = (int)strrpos($fileContents, "\n", $branchPoint - strlen($fileContents)) + 1;
                $indentation = substr($fileContents, $newlinePos, $branchPoint - $newlinePos);
                FileManipulationBuffer::add($this->getFilePath(), [
                    new FileManipulation($branchPoint, $branchPoint, $varId . ' = null;' . "\n" . $indentation),
                ]);
            }
        }

        return null;
    }

    /**
     * @return void
     */
    public function checkUnreferencedVars()
    {
        $source = $this->getSource();
        $functionStorage = $source instanceof FunctionLikeChecker ? $source->getFunctionLikeStorage($this) : null;

        foreach ($this->unusedVarLocations as $hash => list($varId, $originalLocation)) {
            if ($varId === '$_' || isset($this->usedVarLocations[$hash])) {
                continue;
            }

            if (!$functionStorage || !array_key_exists(substr($varId, 1), $functionStorage->paramTypes)) {
                if (IssueBuffer::accepts(
                    new UnusedVariable(
                        'Variable ' . $varId . ' is never referenced',
                        $originalLocation
                    ),
                    $this->getSuppressedIssues()
                )) {
                    // fall through
                }
            }
        }
    }

    /**
     * @param   PhpParser\Node\Stmt\Static_ $stmt
     * @param   Context                     $context
     *
     * @return  false|null
     */
    private function analyzeStatic(PhpParser\Node\Stmt\Static_ $stmt, Context $context)
    {
        foreach ($stmt->vars as $var) {
            if ($var->default) {
                if (ExpressionChecker::analyze($this, $var->default, $context) === false) {
                    return false;
                }
            }

            if ($context->checkVariables) {
                if (!is_string($var->var->name)) {
                    continue;
                }

                $varId = '$' . $var->var->name;

                $context->varsInScope[$varId] = Type::getMixed();
                $context->varsPossiblyInScope[$varId] = true;
                $context->assignedVarIds[$varId] = true;

                $location = new CodeLocation($this, $stmt);

                if ($context->collectReferences) {
                    $context->unreferencedVars[$varId] = [$location->getHash() => $location];
                }

                $this->registerVariable(
                    $varId,
                    $location,
                    $context->branchPoint
                );
            }
        }

        return null;
    }

    /**
     * @param   PhpParser\Node\Expr $stmt
     * @param   ?array<string, Type\Union> $existingClassConstants
     * @param   string $fqClasslikeName
     *
     * @return  Type\Union|null
     */
    public static function getSimpleType(
        \Psalm\Codebase $codebase,
        PhpParser\Node\Expr $stmt,
        \Psalm\Aliases $aliases,
        \Psalm\FileSource $fileSource = null,
        array $existingClassConstants = null,
        $fqClasslikeName = null
    ) {
        if ($stmt instanceof PhpParser\Node\Expr\BinaryOp) {
            if ($stmt instanceof PhpParser\Node\Expr\BinaryOp\Concat) {
                return Type::getString();
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
                return Type::getBool();
            }

            if ($stmt instanceof PhpParser\Node\Expr\BinaryOp\Coalesce) {
                return null;
            }

            if ($stmt instanceof PhpParser\Node\Expr\BinaryOp\Spaceship) {
                return Type::getInt();
            }

            $stmt->left->inferredType = self::getSimpleType(
                $codebase,
                $stmt->left,
                $aliases,
                $fileSource,
                $existingClassConstants,
                $fqClasslikeName
            );
            $stmt->right->inferredType = self::getSimpleType(
                $codebase,
                $stmt->right,
                $aliases,
                $fileSource,
                $existingClassConstants,
                $fqClasslikeName
            );

            if (!$stmt->left->inferredType || !$stmt->right->inferredType) {
                return null;
            }

            if ($stmt instanceof PhpParser\Node\Expr\BinaryOp\Plus ||
                $stmt instanceof PhpParser\Node\Expr\BinaryOp\Minus ||
                $stmt instanceof PhpParser\Node\Expr\BinaryOp\Mod ||
                $stmt instanceof PhpParser\Node\Expr\BinaryOp\Mul ||
                $stmt instanceof PhpParser\Node\Expr\BinaryOp\Pow
            ) {
                BinaryOpChecker::analyzeNonDivArithmenticOp(
                    $fileSource instanceof StatementsSource ? $fileSource : null,
                    $stmt->left,
                    $stmt->right,
                    $stmt,
                    $resultType
                );

                if ($resultType) {
                    return $resultType;
                }

                return null;
            }

            if ($stmt instanceof PhpParser\Node\Expr\BinaryOp\Div
                && ($stmt->left->inferredType->hasInt() || $stmt->left->inferredType->hasFloat())
                && ($stmt->right->inferredType->hasInt() || $stmt->right->inferredType->hasFloat())
            ) {
                return Type::combineUnionTypes(Type::getFloat(), Type::getInt());
            }
        }

        if ($stmt instanceof PhpParser\Node\Expr\ConstFetch) {
            if (strtolower($stmt->name->parts[0]) === 'false') {
                return Type::getFalse();
            } elseif (strtolower($stmt->name->parts[0]) === 'true') {
                return Type::getBool();
            } elseif (strtolower($stmt->name->parts[0]) === 'null') {
                return Type::getNull();
            }

            return null;
        }

        if ($stmt instanceof PhpParser\Node\Expr\ClassConstFetch) {
            if ($stmt->class instanceof PhpParser\Node\Name
                && $stmt->name instanceof PhpParser\Node\Identifier
                && $fqClasslikeName
                && $stmt->class->parts !== ['static']
                && $stmt->class->parts !== ['parent']
            ) {
                if (isset($existingClassConstants[$stmt->name->name])) {
                    if ($stmt->class->parts === ['self']) {
                        return clone $existingClassConstants[$stmt->name->name];
                    }
                }

                if ($stmt->class->parts === ['self']) {
                    $constFqClassName = $fqClasslikeName;
                } else {
                    $constFqClassName = ClassLikeChecker::getFQCLNFromNameObject(
                        $stmt->class,
                        $aliases
                    );
                }

                if (strtolower($constFqClassName) === strtolower($fqClasslikeName)
                    && isset($existingClassConstants[$stmt->name->name])
                ) {
                    return clone $existingClassConstants[$stmt->name->name];
                }

                if (strtolower($stmt->name->name) === 'class') {
                    return Type::getClassString($constFqClassName);
                }

                if ($existingClassConstants === null) {
                    try {
                        $foreignClassConstants = $codebase->classlikes->getConstantsForClass(
                            $constFqClassName,
                            \ReflectionProperty::IS_PRIVATE
                        );

                        if (isset($foreignClassConstants[$stmt->name->name])) {
                            return clone $foreignClassConstants[$stmt->name->name];
                        }

                        return null;
                    } catch (\InvalidArgumentException $e) {
                        return null;
                    }
                }
            }

            if ($stmt->name instanceof PhpParser\Node\Identifier && strtolower($stmt->name->name) === 'class') {
                return Type::getClassString();
            }

            return null;
        }

        if ($stmt instanceof PhpParser\Node\Scalar\String_) {
            return Type::getString(strlen($stmt->value) < 30 ? $stmt->value : null);
        }

        if ($stmt instanceof PhpParser\Node\Scalar\LNumber) {
            return Type::getInt(false, $stmt->value);
        }

        if ($stmt instanceof PhpParser\Node\Scalar\DNumber) {
            return Type::getFloat($stmt->value);
        }

        if ($stmt instanceof PhpParser\Node\Expr\Array_) {
            if (count($stmt->items) === 0) {
                return Type::getEmptyArray();
            }

            $itemKeyType = null;
            $itemValueType = null;

            $propertyTypes = [];
            $classStrings = [];

            $canCreateObjectlike = true;

            foreach ($stmt->items as $intOffset => $item) {
                if ($item === null) {
                    continue;
                }

                if ($item->key) {
                    $singleItemKeyType = self::getSimpleType(
                        $codebase,
                        $item->key,
                        $aliases,
                        $fileSource,
                        $existingClassConstants,
                        $fqClasslikeName
                    );

                    if ($singleItemKeyType) {
                        if ($itemKeyType) {
                            $itemKeyType = Type::combineUnionTypes($singleItemKeyType, $itemKeyType);
                        } else {
                            $itemKeyType = $singleItemKeyType;
                        }
                    }
                } else {
                    $itemKeyType = Type::getInt();
                }

                if ($itemValueType && !$canCreateObjectlike) {
                    continue;
                }

                $singleItemValueType = self::getSimpleType(
                    $codebase,
                    $item->value,
                    $aliases,
                    $fileSource,
                    $existingClassConstants,
                    $fqClasslikeName
                );

                if (!$singleItemValueType) {
                    return null;
                }

                if ($item->key instanceof PhpParser\Node\Scalar\String_
                    || $item->key instanceof PhpParser\Node\Scalar\LNumber
                    || !$item->key
                ) {
                    $propertyTypes[$item->key ? $item->key->value : $intOffset] = $singleItemValueType;
                } else {
                    $dimType = self::getSimpleType(
                        $codebase,
                        $item->key,
                        $aliases,
                        $fileSource,
                        $existingClassConstants,
                        $fqClasslikeName
                    );

                    if (!$dimType) {
                        return null;
                    }

                    $dimAtomicTypes = $dimType->getTypes();

                    if (count($dimAtomicTypes) > 1 || $dimType->isMixed()) {
                        $canCreateObjectlike = false;
                    } else {
                        $atomicType = array_shift($dimAtomicTypes);

                        if ($atomicType instanceof Type\Atomic\TLiteralInt
                            || $atomicType instanceof Type\Atomic\TLiteralString
                        ) {
                            if ($atomicType instanceof Type\Atomic\TLiteralClassString) {
                                $classStrings[$atomicType->value] = true;
                            }

                            $propertyTypes[$atomicType->value] = $singleItemValueType;
                        } else {
                            $canCreateObjectlike = false;
                        }
                    }
                }

                if ($itemValueType) {
                    $itemValueType = Type::combineUnionTypes($singleItemValueType, $itemValueType);
                } else {
                    $itemValueType = $singleItemValueType;
                }
            }

            // if this array looks like an object-like array, let's return that instead
            if ($itemValueType
                && $itemKeyType
                && ($itemKeyType->hasString() || $itemKeyType->hasInt())
                && $canCreateObjectlike
            ) {
                return new Type\Union([new Type\Atomic\ObjectLike($propertyTypes, $classStrings)]);
            }

            if (!$itemKeyType || !$itemValueType) {
                return null;
            }

            return new Type\Union([
                new Type\Atomic\TArray([
                    $itemKeyType,
                    $itemValueType,
                ]),
            ]);
        }

        if ($stmt instanceof PhpParser\Node\Expr\Cast\Int_) {
            return Type::getInt();
        }

        if ($stmt instanceof PhpParser\Node\Expr\Cast\Double) {
            return Type::getFloat();
        }

        if ($stmt instanceof PhpParser\Node\Expr\Cast\Bool_) {
            return Type::getBool();
        }

        if ($stmt instanceof PhpParser\Node\Expr\Cast\String_) {
            return Type::getString();
        }

        if ($stmt instanceof PhpParser\Node\Expr\Cast\Object_) {
            return Type::getObject();
        }

        if ($stmt instanceof PhpParser\Node\Expr\Cast\Array_) {
            return Type::getArray();
        }

        if ($stmt instanceof PhpParser\Node\Expr\UnaryMinus || $stmt instanceof PhpParser\Node\Expr\UnaryPlus) {
            $typeToInvert = self::getSimpleType(
                $codebase,
                $stmt->expr,
                $aliases,
                $fileSource,
                $existingClassConstants,
                $fqClasslikeName
            );

            if (!$typeToInvert) {
                return null;
            }

            foreach ($typeToInvert->getTypes() as $typePart) {
                if ($typePart instanceof Type\Atomic\TLiteralInt
                    && $stmt instanceof PhpParser\Node\Expr\UnaryMinus
                ) {
                    $typePart->value = -$typePart->value;
                } elseif ($typePart instanceof Type\Atomic\TLiteralFloat
                    && $stmt instanceof PhpParser\Node\Expr\UnaryMinus
                ) {
                    $typePart->value = -$typePart->value;
                }
            }

            return $typeToInvert;
        }

        return null;
    }

    /**
     * @param   PhpParser\Node\Stmt\Const_  $stmt
     * @param   Context                     $context
     *
     * @return  void
     */
    private function analyzeConstAssignment(PhpParser\Node\Stmt\Const_ $stmt, Context $context)
    {
        foreach ($stmt->consts as $const) {
            ExpressionChecker::analyze($this, $const->value, $context);

            $this->setConstType(
                $const->name->name,
                isset($const->value->inferredType) ? $const->value->inferredType : Type::getMixed(),
                $context
            );
        }
    }

    /**
     * @param   string  $constName
     * @param   bool    $isFullyQualified
     * @param   Context $context
     *
     * @return  Type\Union|null
     */
    public function getConstType(
        StatementsChecker $statementsChecker,
        $constName,
        $isFullyQualified,
        Context $context
    ) {
        $fqConstName = null;

        $aliasedConstants = $this->getAliases()->constants;

        if (isset($aliasedConstants[$constName])) {
            $fqConstName = $aliasedConstants[$constName];
        } elseif ($isFullyQualified) {
            $fqConstName = $constName;
        } elseif (strpos($constName, '\\')) {
            $fqConstName = Type::getFQCLNFromString($constName, $this->getAliases());
        }

        if ($fqConstName) {
            $constNameParts = explode('\\', $fqConstName);
            $constName = array_pop($constNameParts);
            $namespaceName = implode('\\', $constNameParts);
            $namespaceConstants = NamespaceChecker::getConstantsForNamespace(
                $namespaceName,
                \ReflectionProperty::IS_PUBLIC
            );

            if (isset($namespaceConstants[$constName])) {
                return $namespaceConstants[$constName];
            }
        }

        if ($context->hasVariable($constName, $statementsChecker)) {
            return $context->varsInScope[$constName];
        }

        $filePath = $statementsChecker->getRootFilePath();
        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;

        $fileStorageProvider = $projectChecker->fileStorageProvider;

        $fileStorage = $fileStorageProvider->get($filePath);

        if (isset($fileStorage->declaringConstants[$constName])) {
            $constantFilePath = $fileStorage->declaringConstants[$constName];

            return $fileStorageProvider->get($constantFilePath)->constants[$constName];
        }

        return ConstFetchChecker::getGlobalConstType($projectChecker->codebase, $fqConstName, $constName);
    }

    /**
     * @param   string      $constName
     * @param   Type\Union  $constType
     * @param   Context     $context
     *
     * @return  void
     */
    public function setConstType($constName, Type\Union $constType, Context $context)
    {
        $context->varsInScope[$constName] = $constType;
        $context->constants[$constName] = $constType;

        if ($this->source instanceof NamespaceChecker) {
            $this->source->setConstType($constName, $constType);
        }
    }

    /**
     * @param  string       $varName
     *
     * @return bool
     */
    public function hasVariable($varName)
    {
        return isset($this->allVars[$varName]);
    }

    /**
     * @param  string       $varId
     * @param  CodeLocation $location
     * @param  int|null     $branchPoint
     *
     * @return void
     */
    public function registerVariable($varId, CodeLocation $location, $branchPoint)
    {
        $this->allVars[$varId] = $location;

        if ($branchPoint) {
            $this->varBranchPoints[$varId] = $branchPoint;
        }

        $this->registerVariableAssignment($varId, $location);
    }

    /**
     * @param  string       $varId
     * @param  CodeLocation $location
     *
     * @return void
     */
    public function registerVariableAssignment($varId, CodeLocation $location)
    {
        $this->unusedVarLocations[$location->getHash()] = [$varId, $location];
    }

    /**
     * @param array<string, CodeLocation> $locations
     * @return void
     */
    public function registerVariableUses(array $locations)
    {
        foreach ($locations as $hash => $_) {
            unset($this->unusedVarLocations[$hash]);
            $this->usedVarLocations[$hash] = true;
        }
    }

    /**
     * @return array<string, array{0: string, 1: CodeLocation}>
     */
    public function getUnusedVarLocations()
    {
        return $this->unusedVarLocations;
    }

    /**
     * The first appearance of the variable in this set of statements being evaluated
     *
     * @param  string  $varId
     *
     * @return CodeLocation|null
     */
    public function getFirstAppearance($varId)
    {
        return isset($this->allVars[$varId]) ? $this->allVars[$varId] : null;
    }

    /**
     * @param  string $varId
     *
     * @return int|null
     */
    public function getBranchPoint($varId)
    {
        return isset($this->varBranchPoints[$varId]) ? $this->varBranchPoints[$varId] : null;
    }

    /**
     * @param string $varId
     * @param int    $branchPoint
     *
     * @return void
     */
    public function addVariableInitialization($varId, $branchPoint)
    {
        $this->varsToInitialize[$varId] = $branchPoint;
    }

    /**
     * @return FileChecker
     */
    public function getFileChecker()
    {
        return $this->fileChecker;
    }

    /**
     * @return array<string, FunctionChecker>
     */
    public function getFunctionCheckers()
    {
        return $this->functionCheckers;
    }
}
