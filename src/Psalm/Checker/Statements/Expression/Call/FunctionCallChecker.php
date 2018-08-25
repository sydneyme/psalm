<?php
namespace Psalm\Checker\Statements\Expression\Call;

use PhpParser;
use Psalm\Checker\FunctionChecker;
use Psalm\Checker\FunctionLikeChecker;
use Psalm\Checker\ProjectChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Codebase\CallMap;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\FileManipulation\FileManipulationBuffer;
use Psalm\Issue\ForbiddenCode;
use Psalm\Issue\InvalidFunctionCall;
use Psalm\Issue\NullFunctionCall;
use Psalm\Issue\PossiblyInvalidFunctionCall;
use Psalm\Issue\PossiblyNullFunctionCall;
use Psalm\IssueBuffer;
use Psalm\Type;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TGenericParam;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Algebra;
use Psalm\Type\Reconciler;

class FunctionCallChecker extends \Psalm\Checker\Statements\Expression\CallChecker
{
    /**
     * @param   StatementsChecker               $statementsChecker
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     * @param   Context                         $context
     *
     * @return  false|null
     */
    public static function analyze(
        ProjectChecker $projectChecker,
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\FuncCall $stmt,
        Context $context
    ) {
        $function = $stmt->name;

        $functionId = null;
        $functionParams = null;
        $inCallMap = false;

        $isStubbed = false;

        $functionStorage = null;

        $codeLocation = new CodeLocation($statementsChecker->getSource(), $stmt);
        $codebase = $projectChecker->codebase;
        $codebaseFunctions = $codebase->functions;
        $config = $codebase->config;
        $definedConstants = [];
        $globalVariables = [];

        $functionExists = false;

        if ($stmt->name instanceof PhpParser\Node\Expr) {
            if (ExpressionChecker::analyze($statementsChecker, $stmt->name, $context) === false) {
                return false;
            }

            if (isset($stmt->name->inferredType)) {
                if ($stmt->name->inferredType->isNull()) {
                    if (IssueBuffer::accepts(
                        new NullFunctionCall(
                            'Cannot call function on null value',
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }

                    return;
                }

                if ($stmt->name->inferredType->isNullable()) {
                    if (IssueBuffer::accepts(
                        new PossiblyNullFunctionCall(
                            'Cannot call function on possibly null value',
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }

                $invalidFunctionCallTypes = [];
                $hasValidFunctionCallType = false;

                foreach ($stmt->name->inferredType->getTypes() as $varTypePart) {
                    if ($varTypePart instanceof Type\Atomic\Fn || $varTypePart instanceof Type\Atomic\TCallable) {
                        $functionParams = $varTypePart->params;

                        if (isset($stmt->inferredType) && $varTypePart->returnType) {
                            $stmt->inferredType = Type::combineUnionTypes(
                                $stmt->inferredType,
                                $varTypePart->returnType
                            );
                        } else {
                            $stmt->inferredType = $varTypePart->returnType ?: Type::getMixed();
                        }

                        $functionExists = true;
                        $hasValidFunctionCallType = true;
                    } elseif ($varTypePart instanceof TMixed || $varTypePart instanceof TGenericParam) {
                        $hasValidFunctionCallType = true;
                        // @todo maybe emit issue here
                    } elseif (($varTypePart instanceof TNamedObject && $varTypePart->value === 'Closure')) {
                        // this is fine
                        $hasValidFunctionCallType = true;
                    } elseif ($varTypePart instanceof TString
                        || $varTypePart instanceof Type\Atomic\TArray
                        || ($varTypePart instanceof Type\Atomic\ObjectLike
                            && count($varTypePart->properties) === 2)
                    ) {
                        // this is also kind of fine
                        $hasValidFunctionCallType = true;
                    } elseif ($varTypePart instanceof TNull) {
                        // handled above
                    } elseif (!$varTypePart instanceof TNamedObject
                        || !$codebase->classlikes->classOrInterfaceExists($varTypePart->value)
                        || !$codebase->methods->methodExists($varTypePart->value . '::__invoke')
                    ) {
                        $invalidFunctionCallTypes[] = (string)$varTypePart;
                    } else {
                        if (self::checkMethodArgs(
                            $varTypePart->value . '::__invoke',
                            $stmt->args,
                            $classTemplateParams,
                            $context,
                            new CodeLocation($statementsChecker->getSource(), $stmt),
                            $statementsChecker
                        ) === false) {
                            return false;
                        }

                        $invokableReturnType = $codebase->methods->getMethodReturnType(
                            $varTypePart->value . '::__invoke',
                            $varTypePart->value
                        );

                        if (isset($stmt->inferredType)) {
                            $stmt->inferredType = Type::combineUnionTypes(
                                $invokableReturnType ?: Type::getMixed(),
                                $stmt->inferredType
                            );
                        } else {
                            $stmt->inferredType = $invokableReturnType ?: Type::getMixed();
                        }
                    }
                }

                if ($invalidFunctionCallTypes) {
                    $varTypePart = reset($invalidFunctionCallTypes);

                    if ($hasValidFunctionCallType) {
                        if (IssueBuffer::accepts(
                            new PossiblyInvalidFunctionCall(
                                'Cannot treat type ' . $varTypePart . ' as callable',
                                new CodeLocation($statementsChecker->getSource(), $stmt)
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            return false;
                        }
                    } else {
                        if (IssueBuffer::accepts(
                            new InvalidFunctionCall(
                                'Cannot treat type ' . $varTypePart . ' as callable',
                                new CodeLocation($statementsChecker->getSource(), $stmt)
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            return false;
                        }
                    }
                }
            }

            if (!isset($stmt->inferredType)) {
                $stmt->inferredType = Type::getMixed();
            }
        } else {
            $functionId = implode('\\', $stmt->name->parts);

            $inCallMap = CallMap::inCallMap($functionId);
            $isStubbed = $codebaseFunctions->hasStubbedFunction($functionId);

            $isPredefined = true;

            $isMaybeRootFunction = !$stmt->name instanceof PhpParser\Node\Name\FullyQualified
                && count($stmt->name->parts) === 1;

            if (!$inCallMap) {
                $predefinedFunctions = $config->getPredefinedFunctions();
                $isPredefined = isset($predefinedFunctions[$functionId]);
            }

            if (!$inCallMap && !$stmt->name instanceof PhpParser\Node\Name\FullyQualified) {
                $functionId = $codebaseFunctions->getFullyQualifiedFunctionNameFromString(
                    $functionId,
                    $statementsChecker
                );
            }

            if (!$inCallMap) {
                if ($context->checkFunctions) {
                    if (self::checkFunctionExists(
                        $statementsChecker,
                        $functionId,
                        $codeLocation,
                        $isMaybeRootFunction
                    ) === false
                    ) {
                        return false;
                    }
                } else {
                    $functionId = self::getExistingFunctionId(
                        $statementsChecker,
                        $functionId,
                        $isMaybeRootFunction
                    );
                }

                $functionExists = $isStubbed || $codebaseFunctions->functionExists(
                    $statementsChecker,
                    strtolower($functionId)
                );
            } else {
                $functionExists = true;
            }

            if ($functionExists) {
                if (!$inCallMap || $isStubbed) {
                    $functionStorage = $codebaseFunctions->getStorage(
                        $statementsChecker,
                        strtolower($functionId)
                    );

                    $functionParams = $functionStorage->params;

                    if (!$isPredefined) {
                        $definedConstants = $functionStorage->definedConstants;
                        $globalVariables = $functionStorage->globalVariables;
                    }
                }

                if ($inCallMap && !$isStubbed) {
                    $functionParams = FunctionLikeChecker::getFunctionParamsFromCallMapById(
                        $statementsChecker->getFileChecker()->projectChecker,
                        $functionId,
                        $stmt->args
                    );
                }
            }
        }

        if (self::checkFunctionArguments(
            $statementsChecker,
            $stmt->args,
            $functionParams,
            $functionId,
            $context
        ) === false) {
            // fall through
        }

        if ($functionExists) {
            $genericParams = null;

            if ($stmt->name instanceof PhpParser\Node\Name && $functionId) {
                if (!$isStubbed && $inCallMap) {
                    $functionParams = FunctionLikeChecker::getFunctionParamsFromCallMapById(
                        $statementsChecker->getFileChecker()->projectChecker,
                        $functionId,
                        $stmt->args
                    );
                }
            }

            // do this here to allow closure param checks
            if ($functionParams !== null
                && self::checkFunctionLikeArgumentsMatch(
                    $statementsChecker,
                    $stmt->args,
                    $functionId,
                    $functionParams,
                    $functionStorage,
                    null,
                    $genericParams,
                    $codeLocation,
                    $context
                ) === false) {
                // fall through
            }

            if ($stmt->name instanceof PhpParser\Node\Name && $functionId) {
                if (!$inCallMap || $isStubbed) {
                    if ($functionStorage && $functionStorage->templateTypes) {
                        foreach ($functionStorage->templateTypes as $templateName => $_) {
                            if (!isset($genericParams[$templateName])) {
                                $genericParams[$templateName] = Type::getMixed();
                            }
                        }
                    }

                    if ($functionStorage && $context->collectExceptions) {
                        $context->possiblyThrownExceptions += $functionStorage->throws;
                    }

                    try {
                        if ($functionStorage && $functionStorage->returnType) {
                            $returnType = clone $functionStorage->returnType;

                            if ($genericParams && $functionStorage->templateTypes) {
                                $returnType->replaceTemplateTypesWithArgTypes(
                                    $genericParams
                                );
                            }

                            $returnTypeLocation = $functionStorage->returnTypeLocation;

                            if ($config->afterFunctionChecks) {
                                $fileManipulations = [];

                                foreach ($config->afterFunctionChecks as $pluginFqClassName) {
                                    $pluginFqClassName::afterFunctionCallCheck(
                                        $statementsChecker,
                                        $functionId,
                                        $stmt->args,
                                        $returnTypeLocation,
                                        $context,
                                        $fileManipulations,
                                        $returnType
                                    );
                                }

                                if ($fileManipulations) {
                                    /** @psalm-suppress MixedTypeCoercion */
                                    FileManipulationBuffer::add(
                                        $statementsChecker->getFilePath(),
                                        $fileManipulations
                                    );
                                }
                            }

                            $stmt->inferredType = $returnType;
                            $returnType->byRef = $functionStorage->returnsByRef;

                            // only check the type locally if it's defined externally
                            if ($returnTypeLocation &&
                                !$isStubbed && // makes lookups or array_* functions quicker
                                !$config->isInProjectDirs($returnTypeLocation->filePath)
                            ) {
                                $returnType->check(
                                    $statementsChecker,
                                    new CodeLocation($statementsChecker->getSource(), $stmt),
                                    $statementsChecker->getSuppressedIssues(),
                                    $context->phantomClasses
                                );
                            }
                        }
                    } catch (\InvalidArgumentException $e) {
                        // this can happen when the function was defined in the Config startup script
                        $stmt->inferredType = Type::getMixed();
                    }
                } else {
                    $stmt->inferredType = FunctionChecker::getReturnTypeFromCallMapWithArgs(
                        $statementsChecker,
                        $functionId,
                        $stmt->args,
                        $codeLocation,
                        $statementsChecker->getSuppressedIssues()
                    );
                }
            }

            foreach ($definedConstants as $constName => $constType) {
                $context->constants[$constName] = clone $constType;
                $context->varsInScope[$constName] = clone $constType;
            }

            foreach ($globalVariables as $varId => $_) {
                $context->varsInScope[$varId] = Type::getMixed();
                $context->varsPossiblyInScope[$varId] = true;
            }

            if ($config->useAssertForType &&
                $function instanceof PhpParser\Node\Name &&
                $function->parts === ['assert'] &&
                isset($stmt->args[0])
            ) {
                $assertClauses = \Psalm\Type\Algebra::getFormula(
                    $stmt->args[0]->value,
                    $statementsChecker->getFQCLN(),
                    $statementsChecker
                );

                $simplifiedClauses = Algebra::simplifyCNF(array_merge($context->clauses, $assertClauses));

                $assertTypeAssertions = Algebra::getTruthsFromFormula($simplifiedClauses);

                $changedVars = [];

                // while in an and, we allow scope to boil over to support
                // statements of the form if ($x && $x->foo())
                $opVarsInScope = Reconciler::reconcileKeyedTypes(
                    $assertTypeAssertions,
                    $context->varsInScope,
                    $changedVars,
                    [],
                    $statementsChecker,
                    new CodeLocation($statementsChecker->getSource(), $stmt),
                    $statementsChecker->getSuppressedIssues()
                );

                foreach ($changedVars as $changedVar) {
                    if (isset($opVarsInScope[$changedVar])) {
                        $opVarsInScope[$changedVar]->fromDocblock = true;
                    }
                }

                $context->varsInScope = $opVarsInScope;
            }
        }

        if (!$config->rememberPropertyAssignmentsAfterCall
            && !$inCallMap
            && !$context->collectInitializations
        ) {
            $context->removeAllObjectVars();
        }

        if ($stmt->name instanceof PhpParser\Node\Name &&
            ($stmt->name->parts === ['get_class'] || $stmt->name->parts === ['gettype']) &&
            $stmt->args
        ) {
            $var = $stmt->args[0]->value;

            if ($var instanceof PhpParser\Node\Expr\Variable && is_string($var->name)) {
                $atomicType = $stmt->name->parts === ['get_class']
                    ? new Type\Atomic\GetClassT('$' . $var->name)
                    : new Type\Atomic\GetTypeT('$' . $var->name);

                $stmt->inferredType = new Type\Union([$atomicType]);
            }
        }

        if ($functionStorage) {
            if ($functionStorage->assertions) {
                self::applyAssertionsToContext(
                    $functionStorage->assertions,
                    $stmt->args,
                    $context,
                    $statementsChecker
                );
            }

            if ($functionStorage->ifTrueAssertions) {
                $stmt->ifTrueAssertions = $functionStorage->ifTrueAssertions;
            }

            if ($functionStorage->ifFalseAssertions) {
                $stmt->ifFalseAssertions = $functionStorage->ifFalseAssertions;
            }
        }

        if ($function instanceof PhpParser\Node\Name) {
            $firstArg = isset($stmt->args[0]) ? $stmt->args[0] : null;

            if ($function->parts === ['method_exists']) {
                $context->checkMethods = false;
            } elseif ($function->parts === ['class_exists']) {
                if ($firstArg && $firstArg->value instanceof PhpParser\Node\Scalar\String_) {
                    $context->phantomClasses[strtolower($firstArg->value->value)] = true;
                } else {
                    $context->checkClasses = false;
                }
            } elseif ($function->parts === ['file_exists'] && $firstArg) {
                $varId = ExpressionChecker::getArrayVarId($firstArg->value, null);

                if ($varId) {
                    $context->phantomFiles[$varId] = true;
                }
            } elseif ($function->parts === ['extension_loaded']) {
                $context->checkClasses = false;
            } elseif ($function->parts === ['function_exists']) {
                $context->checkFunctions = false;
            } elseif ($function->parts === ['is_callable']) {
                $context->checkMethods = false;
                $context->checkFunctions = false;
            } elseif ($function->parts === ['defined']) {
                $context->checkConsts = false;
            } elseif ($function->parts === ['extract']) {
                $context->checkVariables = false;
            } elseif ($function->parts === ['var_dump'] || $function->parts === ['shell_exec']) {
                if (IssueBuffer::accepts(
                    new ForbiddenCode(
                        'Unsafe ' . implode('', $function->parts),
                        new CodeLocation($statementsChecker->getSource(), $stmt)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }
            } elseif ($function->parts === ['define']) {
                if ($firstArg && $firstArg->value instanceof PhpParser\Node\Scalar\String_) {
                    $secondArg = $stmt->args[1];
                    ExpressionChecker::analyze($statementsChecker, $secondArg->value, $context);
                    $constName = $firstArg->value->value;

                    $statementsChecker->setConstType(
                        $constName,
                        isset($secondArg->value->inferredType) ? $secondArg->value->inferredType : Type::getMixed(),
                        $context
                    );
                } else {
                    $context->checkConsts = false;
                }
            }
        }

        return null;
    }
}
