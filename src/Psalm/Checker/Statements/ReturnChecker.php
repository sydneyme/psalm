<?php
namespace Psalm\Checker\Statements;

use PhpParser;
use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\CommentChecker;
use Psalm\Checker\FunctionLikeChecker;
use Psalm\Checker\ProjectChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Checker\TraitChecker;
use Psalm\Checker\TypeChecker;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Exception\DocblockParseException;
use Psalm\Issue\FalsableReturnStatement;
use Psalm\Issue\InvalidDocblock;
use Psalm\Issue\InvalidReturnStatement;
use Psalm\Issue\LessSpecificReturnStatement;
use Psalm\Issue\MixedReturnStatement;
use Psalm\Issue\MixedTypeCoercion;
use Psalm\Issue\NullableReturnStatement;
use Psalm\IssueBuffer;
use Psalm\Type;

class ReturnChecker
{
    /**
     * @param  PhpParser\Node\Stmt\Return_ $stmt
     * @param  Context                     $context
     *
     * @return false|null
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        ProjectChecker $projectChecker,
        PhpParser\Node\Stmt\Return_ $stmt,
        Context $context
    ) {
        $docCommentText = (string)$stmt->getDocComment();

        $varComments = [];
        $varCommentType = null;

        $source = $statementsChecker->getSource();

        $codebase = $projectChecker->codebase;

        if ($docCommentText) {
            try {
                $varComments = CommentChecker::getTypeFromComment(
                    $docCommentText,
                    $source,
                    $source->getAliases()
                );
            } catch (DocblockParseException $e) {
                if (IssueBuffer::accepts(
                    new InvalidDocblock(
                        (string)$e->getMessage(),
                        new CodeLocation($source, $stmt)
                    )
                )) {
                    // fall through
                }
            }

            foreach ($varComments as $varComment) {
                $commentType = ExpressionChecker::fleshOutType(
                    $projectChecker,
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

        if ($stmt->expr) {
            if (ExpressionChecker::analyze($statementsChecker, $stmt->expr, $context) === false) {
                return false;
            }

            if ($varCommentType) {
                $stmt->inferredType = $varCommentType;
            } elseif (isset($stmt->expr->inferredType)) {
                $stmt->inferredType = $stmt->expr->inferredType;

                if ($stmt->inferredType->isVoid()) {
                    $stmt->inferredType = Type::getNull();
                }
            } else {
                $stmt->inferredType = Type::getMixed();
            }
        } else {
            $stmt->inferredType = Type::getVoid();
        }

        if ($source instanceof FunctionLikeChecker
            && !($source->getSource() instanceof TraitChecker)
        ) {
            $source->addReturnTypes($stmt->expr ? (string) $stmt->inferredType : '', $context);

            $storage = $source->getFunctionLikeStorage($statementsChecker);

            $casedMethodId = $source->getCorrectlyCasedMethodId();

            if ($stmt->expr) {
                if ($storage->returnType && !$storage->returnType->isMixed()) {
                    $inferredType = ExpressionChecker::fleshOutType(
                        $projectChecker,
                        $stmt->inferredType,
                        $source->getFQCLN(),
                        $source->getFQCLN()
                    );

                    $localReturnType = $source->getLocalReturnType($storage->returnType);

                    if ($localReturnType->isGenerator() && $storage->hasYield) {
                        return null;
                    }

                    if ($stmt->inferredType->isMixed()) {
                        if ($localReturnType->isVoid()) {
                            if (IssueBuffer::accepts(
                                new InvalidReturnStatement(
                                    'No return values are expected for ' . $casedMethodId,
                                    new CodeLocation($source, $stmt)
                                ),
                                $statementsChecker->getSuppressedIssues()
                            )) {
                                return false;
                            }
                        }

                        $codebase->analyzer->incrementMixedCount($statementsChecker->getFilePath());

                        if (IssueBuffer::accepts(
                            new MixedReturnStatement(
                                'Could not infer a return type',
                                new CodeLocation($source, $stmt)
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            return false;
                        }

                        return null;
                    }

                    $codebase->analyzer->incrementNonMixedCount($statementsChecker->getFilePath());

                    if ($localReturnType->isVoid()) {
                        if (IssueBuffer::accepts(
                            new InvalidReturnStatement(
                                'No return values are expected for ' . $casedMethodId,
                                new CodeLocation($source, $stmt)
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            return false;
                        }

                        return null;
                    }

                    if (!TypeChecker::isContainedBy(
                        $codebase,
                        $inferredType,
                        $localReturnType,
                        true,
                        true,
                        $hasScalarMatch,
                        $typeCoerced,
                        $typeCoercedFromMixed,
                        $toStringCast,
                        $typeCoercedFromScalar
                    )
                    ) {
                        // is the declared return type more specific than the inferred one?
                        if ($typeCoerced) {
                            if ($typeCoercedFromMixed) {
                                if (IssueBuffer::accepts(
                                    new MixedTypeCoercion(
                                        'The type \'' . $stmt->inferredType . '\' is more general than the declared '
                                            . 'return type \'' . $localReturnType . '\' for ' . $casedMethodId,
                                        new CodeLocation($source, $stmt)
                                    ),
                                    $statementsChecker->getSuppressedIssues()
                                )) {
                                    return false;
                                }
                            } else {
                                if (IssueBuffer::accepts(
                                    new LessSpecificReturnStatement(
                                        'The type \'' . $stmt->inferredType . '\' is more general than the declared '
                                            . 'return type \'' . $localReturnType . '\' for ' . $casedMethodId,
                                        new CodeLocation($source, $stmt)
                                    ),
                                    $statementsChecker->getSuppressedIssues()
                                )) {
                                    return false;
                                }
                            }

                            foreach ($localReturnType->getTypes() as $localTypePart) {
                                if ($localTypePart instanceof Type\Atomic\TClassString
                                    && $stmt->expr instanceof PhpParser\Node\Scalar\String_
                                ) {
                                    if (ClassLikeChecker::checkFullyQualifiedClassLikeName(
                                        $statementsChecker,
                                        $stmt->expr->value,
                                        new CodeLocation($source, $stmt->expr),
                                        $statementsChecker->getSuppressedIssues()
                                    ) === false
                                    ) {
                                        return false;
                                    }
                                } elseif ($localTypePart instanceof Type\Atomic\TArray
                                    && $stmt->expr instanceof PhpParser\Node\Expr\Array_
                                ) {
                                    foreach ($localTypePart->typeParams[1]->getTypes() as $localArrayTypePart) {
                                        if ($localArrayTypePart instanceof Type\Atomic\TClassString) {
                                            foreach ($stmt->expr->items as $item) {
                                                if ($item && $item->value instanceof PhpParser\Node\Scalar\String_) {
                                                    if (ClassLikeChecker::checkFullyQualifiedClassLikeName(
                                                        $statementsChecker,
                                                        $item->value->value,
                                                        new CodeLocation($source, $item->value),
                                                        $statementsChecker->getSuppressedIssues()
                                                    ) === false
                                                    ) {
                                                        return false;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new InvalidReturnStatement(
                                    'The type \'' . $stmt->inferredType->getId()
                                        . '\' does not match the declared return '
                                        . 'type \'' . $localReturnType->getId() . '\' for ' . $casedMethodId,
                                    new CodeLocation($source, $stmt)
                                ),
                                $statementsChecker->getSuppressedIssues()
                            )) {
                                return false;
                            }
                        }
                    }

                    if (!$stmt->inferredType->ignoreNullableIssues
                        && $inferredType->isNullable()
                        && !$localReturnType->isNullable()
                    ) {
                        if (IssueBuffer::accepts(
                            new NullableReturnStatement(
                                'The declared return type \'' . $localReturnType . '\' for '
                                    . $casedMethodId . ' is not nullable, but the function returns \''
                                        . $inferredType . '\'',
                                new CodeLocation($source, $stmt)
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            return false;
                        }
                    }

                    if (!$stmt->inferredType->ignoreFalsableIssues
                        && $inferredType->isFalsable()
                        && !$localReturnType->isFalsable()
                        && !$localReturnType->hasBool()
                    ) {
                        if (IssueBuffer::accepts(
                            new FalsableReturnStatement(
                                'The declared return type \'' . $localReturnType . '\' for '
                                    . $casedMethodId . ' does not allow false, but the function returns \''
                                        . $inferredType . '\'',
                                new CodeLocation($source, $stmt)
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            return false;
                        }
                    }
                }
            } else {
                if ($storage->signatureReturnType
                    && !$storage->signatureReturnType->isVoid()
                    && (!$storage->signatureReturnType->isGenerator() || !$storage->hasYield)
                ) {
                    if (IssueBuffer::accepts(
                        new InvalidReturnStatement(
                            'Empty return statement is not expected in ' . $casedMethodId,
                            new CodeLocation($source, $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }

                    return null;
                }
            }
        }

        return null;
    }
}
