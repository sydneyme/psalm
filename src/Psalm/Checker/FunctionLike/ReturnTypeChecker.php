<?php
namespace Psalm\Checker\FunctionLike;

use PhpParser;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use Psalm\Checker\FunctionLikeChecker;
use Psalm\Checker\InterfaceChecker;
use Psalm\Checker\ProjectChecker;
use Psalm\Checker\ScopeChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Checker\TypeChecker;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\FileManipulation\FunctionDocblockManipulator;
use Psalm\Issue\InvalidFalsableReturnType;
use Psalm\Issue\InvalidNullableReturnType;
use Psalm\Issue\InvalidReturnType;
use Psalm\Issue\InvalidToString;
use Psalm\Issue\LessSpecificReturnType;
use Psalm\Issue\MismatchingDocblockReturnType;
use Psalm\Issue\MissingClosureReturnType;
use Psalm\Issue\MissingReturnType;
use Psalm\Issue\MixedInferredReturnType;
use Psalm\Issue\MixedTypeCoercion;
use Psalm\Issue\MoreSpecificReturnType;
use Psalm\IssueBuffer;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type;
use Psalm\Type\TypeCombination;

class ReturnTypeChecker
{
    /**
     * @param Closure|Function_|ClassMethod $function
     * @param StatementsSource    $source
     * @param Type\Union|null     $returnType
     * @param string              $fqClassName
     * @param CodeLocation|null   $returnTypeLocation
     * @param string[]            $compatibleMethodIds
     *
     * @return  false|null
     */
    public static function verifyReturnType(
        FunctionLike $function,
        StatementsSource $source,
        FunctionLikeChecker $functionLikeChecker,
        Type\Union $returnType = null,
        $fqClassName = null,
        CodeLocation $returnTypeLocation = null,
        array $compatibleMethodIds = []
    ) {
        $suppressedIssues = $functionLikeChecker->getSuppressedIssues();
        $projectChecker = $source->getFileChecker()->projectChecker;

        if (!$function->getStmts() &&
            (
                $function instanceof ClassMethod &&
                ($source instanceof InterfaceChecker || $function->isAbstract())
            )
        ) {
            return null;
        }

        $isToString = $function instanceof ClassMethod && strtolower($function->name->name) === '__tostring';

        if ($function instanceof ClassMethod
            && substr($function->name->name, 0, 2) === '__'
            && !$isToString
            && !$returnType
        ) {
            // do not check __construct, __set, __get, __call etc.
            return null;
        }

        $casedMethodId = $functionLikeChecker->getCorrectlyCasedMethodId();

        if (!$returnTypeLocation) {
            $returnTypeLocation = new CodeLocation(
                $functionLikeChecker,
                $function instanceof Closure ? $function : $function->name
            );
        }

        $inferredYieldTypes = [];

        /** @var PhpParser\Node\Stmt[] */
        $functionStmts = $function->getStmts();

        $inferredReturnTypeParts = ReturnTypeCollector::getReturnTypes(
            $functionStmts,
            $inferredYieldTypes,
            $ignoreNullableIssues,
            $ignoreFalsableIssues,
            true
        );

        if ((!$returnType || $returnType->fromDocblock)
            && ScopeChecker::getFinalControlActions(
                $functionStmts,
                $projectChecker->config->exitFunctions
            ) !== [ScopeChecker::ACTION_END]
            && !$inferredYieldTypes
            && count($inferredReturnTypeParts)
        ) {
            // only add null if we have a return statement elsewhere and it wasn't void
            foreach ($inferredReturnTypeParts as $inferredReturnTypePart) {
                if (!$inferredReturnTypePart instanceof Type\Atomic\TVoid) {
                    $atomicNull = new Type\Atomic\TNull();
                    $atomicNull->fromDocblock = true;
                    $inferredReturnTypeParts[] = $atomicNull;
                    break;
                }
            }
        }

        if ($returnType
            && !$returnType->fromDocblock
            && !$returnType->isVoid()
            && !$inferredYieldTypes
            && ScopeChecker::getFinalControlActions(
                $functionStmts,
                $projectChecker->config->exitFunctions
            ) !== [ScopeChecker::ACTION_END]
        ) {
            if (IssueBuffer::accepts(
                new InvalidReturnType(
                    'Not all code paths of ' . $casedMethodId . ' end in a return statement, return type '
                        . $returnType . ' expected',
                    $returnTypeLocation
                )
            )) {
                return false;
            }

            return null;
        }

        $inferredReturnType = $inferredReturnTypeParts
            ? TypeCombination::combineTypes($inferredReturnTypeParts)
            : Type::getVoid();
        $inferredYieldType = $inferredYieldTypes ? TypeCombination::combineTypes($inferredYieldTypes) : null;

        if ($inferredYieldType) {
            $inferredReturnType = $inferredYieldType;
        }

        $codebase = $projectChecker->codebase;

        if (!$returnType && !$codebase->config->addVoidDocblocks && $inferredReturnType->isVoid()) {
            return null;
        }

        $unsafeReturnType = false;

        // prevent any return types that do not return a value from being used in PHP typehints
        if ($projectChecker->alterCode
            && $inferredReturnType->isNullable()
            && !$inferredYieldTypes
        ) {
            foreach ($inferredReturnTypeParts as $inferredReturnTypePart) {
                if ($inferredReturnTypePart instanceof Type\Atomic\TVoid) {
                    $unsafeReturnType = true;
                }
            }
        }

        $inferredReturnType = TypeChecker::simplifyUnionType(
            $codebase,
            ExpressionChecker::fleshOutType(
                $projectChecker,
                $inferredReturnType,
                $source->getFQCLN(),
                $source->getFQCLN()
            )
        );

        if ($isToString) {
            if (!$inferredReturnType->isMixed() &&
                !TypeChecker::isContainedBy(
                    $codebase,
                    $inferredReturnType,
                    Type::getString(),
                    $inferredReturnType->ignoreNullableIssues,
                    $inferredReturnType->ignoreFalsableIssues,
                    $hasScalarMatch,
                    $typeCoerced,
                    $typeCoercedFromMixed
                )
            ) {
                if (IssueBuffer::accepts(
                    new InvalidToString(
                        '__toString methods must return a string, ' . $inferredReturnType . ' returned',
                        $returnTypeLocation
                    ),
                    $suppressedIssues
                )) {
                    return false;
                }
            }

            return null;
        }

        if (!$returnType) {
            if ($function instanceof Closure) {
                if ($projectChecker->alterCode
                    && isset($projectChecker->getIssuesToFix()['MissingClosureReturnType'])
                ) {
                    if ($inferredReturnType->isMixed() || $inferredReturnType->isNull()) {
                        return null;
                    }

                    self::addOrUpdateReturnType(
                        $function,
                        $projectChecker,
                        $inferredReturnType,
                        $source,
                        $functionLikeChecker,
                        ($projectChecker->onlyReplacePhpTypesWithNonDocblockTypes
                            || $unsafeReturnType)
                            && $inferredReturnType->fromDocblock
                    );

                    return null;
                }

                if (IssueBuffer::accepts(
                    new MissingClosureReturnType(
                        'Closure does not have a return type, expecting ' . $inferredReturnType,
                        new CodeLocation($functionLikeChecker, $function, null, true)
                    ),
                    $suppressedIssues
                )) {
                    // fall through
                }

                return null;
            }

            if ($projectChecker->alterCode
                && isset($projectChecker->getIssuesToFix()['MissingReturnType'])
            ) {
                if ($inferredReturnType->isMixed() || $inferredReturnType->isNull()) {
                    return null;
                }

                self::addOrUpdateReturnType(
                    $function,
                    $projectChecker,
                    $inferredReturnType,
                    $source,
                    $functionLikeChecker,
                    $compatibleMethodIds
                    || (($projectChecker->onlyReplacePhpTypesWithNonDocblockTypes
                            || $unsafeReturnType)
                        && $inferredReturnType->fromDocblock)
                );

                return null;
            }

            if (IssueBuffer::accepts(
                new MissingReturnType(
                    'Method ' . $casedMethodId . ' does not have a return type' .
                      (!$inferredReturnType->isMixed() ? ', expecting ' . $inferredReturnType : ''),
                    new CodeLocation($functionLikeChecker, $function, null, true)
                ),
                $suppressedIssues
            )) {
                // fall through
            }

            return null;
        }

        $selfFqClassName = $fqClassName ?: $source->getFQCLN();

        // passing it through fleshOutTypes eradicates errant $ vars
        $declaredReturnType = ExpressionChecker::fleshOutType(
            $projectChecker,
            $returnType,
            $selfFqClassName,
            $selfFqClassName
        );

        if (!$inferredReturnTypeParts && !$inferredYieldTypes) {
            if ($declaredReturnType->isVoid()) {
                return null;
            }

            if (ScopeChecker::onlyThrows($functionStmts)) {
                // if there's a single throw statement, it's presumably an exception saying this method is not to be
                // used
                return null;
            }

            if ($projectChecker->alterCode && isset($projectChecker->getIssuesToFix()['InvalidReturnType'])) {
                self::addOrUpdateReturnType(
                    $function,
                    $projectChecker,
                    Type::getVoid(),
                    $source,
                    $functionLikeChecker
                );

                return null;
            }

            if (!$declaredReturnType->fromDocblock || !$declaredReturnType->isNullable()) {
                if (IssueBuffer::accepts(
                    new InvalidReturnType(
                        'No return statements were found for method ' . $casedMethodId .
                            ' but return type \'' . $declaredReturnType . '\' was expected',
                        $returnTypeLocation
                    ),
                    $suppressedIssues
                )) {
                    return false;
                }
            }

            return null;
        }

        if (!$declaredReturnType->isMixed()) {
            if ($inferredReturnType->isVoid() && $declaredReturnType->isVoid()) {
                return null;
            }

            if ($inferredReturnType->isMixed() || $inferredReturnType->isEmpty()) {
                if (IssueBuffer::accepts(
                    new MixedInferredReturnType(
                        'Could not verify return type \'' . $declaredReturnType . '\' for ' .
                            $casedMethodId,
                        $returnTypeLocation
                    ),
                    $suppressedIssues
                )) {
                    return false;
                }

                return null;
            }

            if (!TypeChecker::isContainedBy(
                $codebase,
                $inferredReturnType,
                $declaredReturnType,
                true,
                true,
                $hasScalarMatch,
                $typeCoerced,
                $typeCoercedFromMixed
            )) {
                // is the declared return type more specific than the inferred one?
                if ($typeCoerced) {
                    if ($typeCoercedFromMixed) {
                        if (IssueBuffer::accepts(
                            new MixedTypeCoercion(
                                'The declared return type \'' . $declaredReturnType . '\' for ' . $casedMethodId .
                                    ' is more specific than the inferred return type \'' . $inferredReturnType . '\'',
                                $returnTypeLocation
                            ),
                            $suppressedIssues
                        )) {
                            return false;
                        }
                    } else {
                        if (IssueBuffer::accepts(
                            new MoreSpecificReturnType(
                                'The declared return type \'' . $declaredReturnType . '\' for ' . $casedMethodId .
                                    ' is more specific than the inferred return type \'' . $inferredReturnType . '\'',
                                $returnTypeLocation
                            ),
                            $suppressedIssues
                        )) {
                            return false;
                        }
                    }
                } else {
                    if ($projectChecker->alterCode
                        && isset($projectChecker->getIssuesToFix()['InvalidReturnType'])
                    ) {
                        self::addOrUpdateReturnType(
                            $function,
                            $projectChecker,
                            $inferredReturnType,
                            $source,
                            $functionLikeChecker,
                            ($projectChecker->onlyReplacePhpTypesWithNonDocblockTypes
                                || $unsafeReturnType)
                                && $inferredReturnType->fromDocblock
                        );

                        return null;
                    }

                    if (IssueBuffer::accepts(
                        new InvalidReturnType(
                            'The declared return type \'' . $declaredReturnType . '\' for ' . $casedMethodId .
                                ' is incorrect, got \'' . $inferredReturnType . '\'',
                            $returnTypeLocation
                        ),
                        $suppressedIssues
                    )) {
                        return false;
                    }
                }
            } elseif ($projectChecker->alterCode
                    && isset($projectChecker->getIssuesToFix()['LessSpecificReturnType'])
            ) {
                if (!TypeChecker::isContainedBy(
                    $codebase,
                    $declaredReturnType,
                    $inferredReturnType,
                    false,
                    false
                )) {
                    self::addOrUpdateReturnType(
                        $function,
                        $projectChecker,
                        $inferredReturnType,
                        $source,
                        $functionLikeChecker,
                        $compatibleMethodIds
                        || (($projectChecker->onlyReplacePhpTypesWithNonDocblockTypes
                            || $unsafeReturnType)
                        && $inferredReturnType->fromDocblock)
                    );

                    return null;
                }
            } elseif ((!$inferredReturnType->isNullable() && $declaredReturnType->isNullable())
                || (!$inferredReturnType->isFalsable() && $declaredReturnType->isFalsable())
            ) {
                if ($function instanceof Function_
                    || $function instanceof Closure
                    || $function->isPrivate()
                ) {
                    $checkForLessSpecificType = true;
                } elseif ($source instanceof StatementsChecker) {
                    $methodStorage = $functionLikeChecker->getFunctionLikeStorage($source);

                    if ($methodStorage instanceof MethodStorage) {
                        $checkForLessSpecificType = !$methodStorage->overriddenSomewhere;
                    } else {
                        $checkForLessSpecificType = false;
                    }
                } else {
                    $checkForLessSpecificType = false;
                }

                if ($checkForLessSpecificType) {
                    if (IssueBuffer::accepts(
                        new LessSpecificReturnType(
                            'The inferred return type \'' . $inferredReturnType . '\' for ' . $casedMethodId .
                                ' is more specific than the declared return type \'' . $declaredReturnType . '\'',
                            $returnTypeLocation
                        ),
                        $suppressedIssues
                    )) {
                        return false;
                    }
                }
            }

            if (!$ignoreNullableIssues
                && $inferredReturnType->isNullable()
                && !$declaredReturnType->isNullable()
                && !$declaredReturnType->isVoid()
            ) {
                if ($projectChecker->alterCode
                    && isset($projectChecker->getIssuesToFix()['InvalidNullableReturnType'])
                    && !$inferredReturnType->isNull()
                ) {
                    self::addOrUpdateReturnType(
                        $function,
                        $projectChecker,
                        $inferredReturnType,
                        $source,
                        $functionLikeChecker,
                        ($projectChecker->onlyReplacePhpTypesWithNonDocblockTypes
                            || $unsafeReturnType)
                            && $inferredReturnType->fromDocblock
                    );

                    return null;
                }

                if (IssueBuffer::accepts(
                    new InvalidNullableReturnType(
                        'The declared return type \'' . $declaredReturnType . '\' for ' . $casedMethodId .
                            ' is not nullable, but \'' . $inferredReturnType . '\' contains null',
                        $returnTypeLocation
                    ),
                    $suppressedIssues
                )) {
                    return false;
                }
            }

            if (!$ignoreFalsableIssues
                && $inferredReturnType->isFalsable()
                && !$declaredReturnType->isFalsable()
                && !$declaredReturnType->hasBool()
            ) {
                if ($projectChecker->alterCode
                    && isset($projectChecker->getIssuesToFix()['InvalidFalsableReturnType'])
                ) {
                    self::addOrUpdateReturnType(
                        $function,
                        $projectChecker,
                        $inferredReturnType,
                        $source,
                        $functionLikeChecker,
                        ($projectChecker->onlyReplacePhpTypesWithNonDocblockTypes
                            || $unsafeReturnType)
                            && $inferredReturnType->fromDocblock
                    );

                    return null;
                }

                if (IssueBuffer::accepts(
                    new InvalidFalsableReturnType(
                        'The declared return type \'' . $declaredReturnType . '\' for ' . $casedMethodId .
                            ' does not allow false, but \'' . $inferredReturnType . '\' contains false',
                        $returnTypeLocation
                    ),
                    $suppressedIssues
                )) {
                    return false;
                }
            }
        }

        return null;
    }

    /**
     * @param Closure|Function_|ClassMethod $function
     *
     * @return false|null
     */
    public static function checkSignatureReturnType(
        FunctionLike $function,
        ProjectChecker $projectChecker,
        FunctionLikeChecker $functionLikeChecker,
        FunctionLikeStorage $storage,
        Context $context
    ) {
        $codebase = $projectChecker->codebase;

        if (!$storage->returnType || !$storage->returnTypeLocation || $storage->hasTemplateReturnType) {
            return;
        }

        if (!$storage->signatureReturnType || $storage->signatureReturnType === $storage->returnType) {
            $fleshedOutReturnType = ExpressionChecker::fleshOutType(
                $projectChecker,
                $storage->returnType,
                $context->self,
                $context->self
            );

            $fleshedOutReturnType->check(
                $functionLikeChecker,
                $storage->returnTypeLocation,
                $storage->suppressedIssues,
                [],
                false
            );

            return;
        }

        $fleshedOutSignatureType = ExpressionChecker::fleshOutType(
            $projectChecker,
            $storage->signatureReturnType,
            $context->self,
            $context->self
        );

        $fleshedOutSignatureType->check(
            $functionLikeChecker,
            $storage->signatureReturnTypeLocation ?: $storage->returnTypeLocation,
            $storage->suppressedIssues,
            [],
            false
        );

        if ($function instanceof Closure) {
            return;
        }

        $fleshedOutReturnType = ExpressionChecker::fleshOutType(
            $projectChecker,
            $storage->returnType,
            $context->self,
            $context->self
        );

        $fleshedOutSignatureType = ExpressionChecker::fleshOutType(
            $projectChecker,
            $storage->signatureReturnType,
            $context->self,
            $context->self
        );

        if (!TypeChecker::isContainedBy(
            $codebase,
            $fleshedOutReturnType,
            $fleshedOutSignatureType
        )
        ) {
            if ($projectChecker->alterCode
                && isset($projectChecker->getIssuesToFix()['MismatchingDocblockReturnType'])
            ) {
                self::addOrUpdateReturnType(
                    $function,
                    $projectChecker,
                    $storage->signatureReturnType,
                    $functionLikeChecker->getSource(),
                    $functionLikeChecker
                );

                return null;
            }

            if (IssueBuffer::accepts(
                new MismatchingDocblockReturnType(
                    'Docblock has incorrect return type \'' . $storage->returnType .
                        '\', should be \'' . $storage->signatureReturnType . '\'',
                    $storage->returnTypeLocation
                ),
                $storage->suppressedIssues
            )) {
                return false;
            }
        }
    }

    /**
     * @param Closure|Function_|ClassMethod $function
     * @param bool $docblockOnly
     *
     * @return void
     */
    private static function addOrUpdateReturnType(
        FunctionLike $function,
        ProjectChecker $projectChecker,
        Type\Union $inferredReturnType,
        StatementsSource $source,
        FunctionLikeChecker $functionLikeChecker,
        $docblockOnly = false
    ) {
        $manipulator = FunctionDocblockManipulator::getForFunction(
            $projectChecker,
            $source->getFilePath(),
            $functionLikeChecker->getMethodId(),
            $function
        );
        $manipulator->setReturnType(
            !$docblockOnly && $projectChecker->phpMajorVersion >= 7
                ? $inferredReturnType->toPhpString(
                    $source->getNamespace(),
                    $source->getAliasedClassesFlipped(),
                    $source->getFQCLN(),
                    $projectChecker->phpMajorVersion,
                    $projectChecker->phpMinorVersion
                ) : null,
            $inferredReturnType->toNamespacedString(
                $source->getNamespace(),
                $source->getAliasedClassesFlipped(),
                $source->getFQCLN(),
                false
            ),
            $inferredReturnType->toNamespacedString(
                $source->getNamespace(),
                $source->getAliasedClassesFlipped(),
                $source->getFQCLN(),
                true
            ),
            $inferredReturnType->canBeFullyExpressedInPhp()
        );
    }
}
