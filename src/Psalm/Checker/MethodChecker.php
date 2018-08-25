<?php
namespace Psalm\Checker;

use PhpParser;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\CodeLocation;
use Psalm\Issue\DeprecatedMethod;
use Psalm\Issue\ImplementedReturnTypeMismatch;
use Psalm\Issue\InaccessibleMethod;
use Psalm\Issue\InvalidStaticInvocation;
use Psalm\Issue\MethodSignatureMismatch;
use Psalm\Issue\MethodSignatureMustOmitReturnType;
use Psalm\Issue\MoreSpecificImplementedParamType;
use Psalm\Issue\LessSpecificImplementedReturnType;
use Psalm\Issue\NonStaticSelfCall;
use Psalm\Issue\OverriddenMethodAccess;
use Psalm\Issue\UndefinedMethod;
use Psalm\IssueBuffer;
use Psalm\StatementsSource;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type;

class MethodChecker extends FunctionLikeChecker
{
    /**
     * @param PhpParser\Node\FunctionLike $function
     * @param StatementsSource            $source
     * @psalm-suppress MixedAssignment
     */
    public function __construct($function, StatementsSource $source)
    {
        if (!$function instanceof PhpParser\Node\Stmt\ClassMethod) {
            throw new \InvalidArgumentException('Must be called with a ClassMethod');
        }

        parent::__construct($function, $source);
    }

    /**
     * Determines whether a given method is static or not
     *
     * @param  string          $methodId
     * @param  bool            $selfCall
     * @param  bool            $isContextDynamic
     * @param  CodeLocation    $codeLocation
     * @param  array<string>   $suppressedIssues
     * @param  bool            $isDynamicThisMethod
     *
     * @return bool
     */
    public static function checkStatic(
        $methodId,
        $selfCall,
        $isContextDynamic,
        ProjectChecker $projectChecker,
        CodeLocation $codeLocation,
        array $suppressedIssues,
        &$isDynamicThisMethod = false
    ) {
        $codebaseMethods = $projectChecker->codebase->methods;

        $methodId = $codebaseMethods->getDeclaringMethodId($methodId);

        if (!$methodId) {
            throw new \LogicException('Method id should not be null');
        }

        $storage = $codebaseMethods->getStorage($methodId);

        if (!$storage->isStatic) {
            if ($selfCall) {
                if (!$isContextDynamic) {
                    if (IssueBuffer::accepts(
                        new NonStaticSelfCall(
                            'Method ' . $codebaseMethods->getCasedMethodId($methodId) .
                                ' is not static, but is called ' .
                                'using self::',
                            $codeLocation
                        ),
                        $suppressedIssues
                    )) {
                        return false;
                    }
                } else {
                    $isDynamicThisMethod = true;
                }
            } else {
                if (IssueBuffer::accepts(
                    new InvalidStaticInvocation(
                        'Method ' . $codebaseMethods->getCasedMethodId($methodId) .
                            ' is not static, but is called ' .
                            'statically',
                        $codeLocation
                    ),
                    $suppressedIssues
                )) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  string       $methodId
     * @param  CodeLocation $codeLocation
     * @param  array        $suppressedIssues
     * @param  string|null  $sourceMethodId
     *
     * @return bool|null
     */
    public static function checkMethodExists(
        ProjectChecker $projectChecker,
        $methodId,
        CodeLocation $codeLocation,
        array $suppressedIssues,
        $sourceMethodId = null
    ) {
        if ($projectChecker->codebase->methodExists(
            $methodId,
            $sourceMethodId !== $methodId ? $codeLocation : null
        )) {
            return true;
        }

        if (IssueBuffer::accepts(
            new UndefinedMethod('Method ' . $methodId . ' does not exist', $codeLocation, $methodId),
            $suppressedIssues
        )) {
            return false;
        }

        return null;
    }

    /**
     * @param  string       $methodId
     * @param  CodeLocation $codeLocation
     * @param  array        $suppressedIssues
     *
     * @return false|null
     */
    public static function checkMethodNotDeprecated(
        ProjectChecker $projectChecker,
        $methodId,
        CodeLocation $codeLocation,
        array $suppressedIssues
    ) {
        $codebaseMethods = $projectChecker->codebase->methods;

        $methodId = (string) $codebaseMethods->getDeclaringMethodId($methodId);
        $storage = $codebaseMethods->getStorage($methodId);

        if ($storage->deprecated) {
            if (IssueBuffer::accepts(
                new DeprecatedMethod(
                    'The method ' . $codebaseMethods->getCasedMethodId($methodId) .
                        ' has been marked as deprecated',
                    $codeLocation,
                    $methodId
                ),
                $suppressedIssues
            )) {
                return false;
            }
        }

        return null;
    }

    /**
     * @param  string           $methodId
     * @param  string|null      $callingContext
     * @param  StatementsSource $source
     * @param  CodeLocation     $codeLocation
     * @param  array            $suppressedIssues
     *
     * @return false|null
     */
    public static function checkMethodVisibility(
        $methodId,
        $callingContext,
        StatementsSource $source,
        CodeLocation $codeLocation,
        array $suppressedIssues
    ) {
        $projectChecker = $source->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;
        $codebaseMethods = $codebase->methods;
        $codebaseClasslikes = $codebase->classlikes;

        $declaringMethodId = $codebaseMethods->getDeclaringMethodId($methodId);

        if (!$declaringMethodId) {
            $methodName = explode('::', $methodId)[1];

            if ($methodName === '__construct' || $methodId === 'Closure::__invoke') {
                return null;
            }

            throw new \UnexpectedValueException('$declaringMethodId not expected to be null here');
        }

        $appearingMethodId = $codebaseMethods->getAppearingMethodId($methodId);

        $appearingMethodClass = null;

        if ($appearingMethodId) {
            list($appearingMethodClass) = explode('::', $appearingMethodId);

            // if the calling class is the same, we know the method exists, so it must be visible
            if ($appearingMethodClass === $callingContext) {
                return null;
            }
        }

        list($declaringMethodClass) = explode('::', $declaringMethodId);

        if ($source->getSource() instanceof TraitChecker && $declaringMethodClass === $source->getFQCLN()) {
            return null;
        }

        $storage = $projectChecker->codebase->methods->getStorage($declaringMethodId);

        switch ($storage->visibility) {
            case ClassLikeChecker::VISIBILITY_PUBLIC:
                return null;

            case ClassLikeChecker::VISIBILITY_PRIVATE:
                if (!$callingContext || $appearingMethodClass !== $callingContext) {
                    if (IssueBuffer::accepts(
                        new InaccessibleMethod(
                            'Cannot access private method ' . $codebaseMethods->getCasedMethodId($methodId) .
                                ' from context ' . $callingContext,
                            $codeLocation
                        ),
                        $suppressedIssues
                    )) {
                        return false;
                    }
                }

                return null;

            case ClassLikeChecker::VISIBILITY_PROTECTED:
                if (!$callingContext) {
                    if (IssueBuffer::accepts(
                        new InaccessibleMethod(
                            'Cannot access protected method ' . $methodId,
                            $codeLocation
                        ),
                        $suppressedIssues
                    )) {
                        return false;
                    }

                    return null;
                }

                if ($appearingMethodClass
                    && $codebaseClasslikes->classExtends($appearingMethodClass, $callingContext)
                ) {
                    return null;
                }

                if ($appearingMethodClass
                    && !$codebaseClasslikes->classExtends($callingContext, $appearingMethodClass)
                ) {
                    if (IssueBuffer::accepts(
                        new InaccessibleMethod(
                            'Cannot access protected method ' . $codebaseMethods->getCasedMethodId($methodId) .
                                ' from context ' . $callingContext,
                            $codeLocation
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
     * @param  string           $methodId
     * @param  string|null      $callingContext
     * @param  StatementsSource $source
     *
     * @return bool
     */
    public static function isMethodVisible(
        $methodId,
        $callingContext,
        StatementsSource $source
    ) {
        $projectChecker = $source->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;

        $declaringMethodId = $codebase->methods->getDeclaringMethodId($methodId);

        if (!$declaringMethodId) {
            $methodName = explode('::', $methodId)[1];

            if ($methodName === '__construct') {
                return true;
            }

            throw new \UnexpectedValueException('$declaringMethodId not expected to be null here');
        }

        $appearingMethodId = $codebase->methods->getAppearingMethodId($methodId);

        $appearingMethodClass = null;

        if ($appearingMethodId) {
            list($appearingMethodClass) = explode('::', $appearingMethodId);

            // if the calling class is the same, we know the method exists, so it must be visible
            if ($appearingMethodClass === $callingContext) {
                return true;
            }
        }

        list($declaringMethodClass) = explode('::', $declaringMethodId);

        if ($source->getSource() instanceof TraitChecker && $declaringMethodClass === $source->getFQCLN()) {
            return true;
        }

        $storage = $codebase->methods->getStorage($declaringMethodId);

        switch ($storage->visibility) {
            case ClassLikeChecker::VISIBILITY_PUBLIC:
                return true;

            case ClassLikeChecker::VISIBILITY_PRIVATE:
                if (!$callingContext || $appearingMethodClass !== $callingContext) {
                    return false;
                }

                return true;

            case ClassLikeChecker::VISIBILITY_PROTECTED:
                if (!$callingContext) {
                    return false;
                }

                if ($appearingMethodClass
                    && $codebase->classExtends($appearingMethodClass, $callingContext)
                ) {
                    return true;
                }

                if ($appearingMethodClass
                    && !$codebase->classExtends($callingContext, $appearingMethodClass)
                ) {
                    return false;
                }
        }

        return true;
    }

    /**
     * @param  ProjectChecker   $projectChecker
     * @param  ClassLikeStorage $implementerClasslikeStorage
     * @param  ClassLikeStorage $guideClasslikeStorage
     * @param  MethodStorage    $implementerMethodStorage
     * @param  MethodStorage    $guideMethodStorage
     * @param  CodeLocation     $codeLocation
     * @param  array            $suppressedIssues
     * @param  bool             $preventAbstractOverride
     *
     * @return false|null
     */
    public static function compareMethods(
        ProjectChecker $projectChecker,
        ClassLikeStorage $implementerClasslikeStorage,
        ClassLikeStorage $guideClasslikeStorage,
        MethodStorage $implementerMethodStorage,
        MethodStorage $guideMethodStorage,
        CodeLocation $codeLocation,
        array $suppressedIssues,
        $preventAbstractOverride = true
    ) {
        $codebase = $projectChecker->codebase;

        $implementerMethodId = $implementerClasslikeStorage->name . '::'
            . strtolower($guideMethodStorage->casedName);

        $implementerDeclaringMethodId = $codebase->methods->getDeclaringMethodId($implementerMethodId);

        $casedImplementerMethodId = $implementerClasslikeStorage->name . '::'
            . $implementerMethodStorage->casedName;

        $casedGuideMethodId = $guideClasslikeStorage->name . '::' . $guideMethodStorage->casedName;

        if ($implementerMethodStorage->visibility > $guideMethodStorage->visibility) {
            if (IssueBuffer::accepts(
                new OverriddenMethodAccess(
                    'Method ' . $casedImplementerMethodId . ' has different access level than '
                        . $casedGuideMethodId,
                    $codeLocation
                )
            )) {
                return false;
            }

            return null;
        }

        if ($preventAbstractOverride
            && !$guideMethodStorage->abstract
            && $implementerMethodStorage->abstract
            && !$guideClasslikeStorage->abstract
            && !$guideClasslikeStorage->isInterface
        ) {
            if (IssueBuffer::accepts(
                new MethodSignatureMismatch(
                    'Method ' . $casedImplementerMethodId . ' cannot be abstract when inherited method '
                        . $casedGuideMethodId . ' is non-abstract',
                    $codeLocation
                )
            )) {
                return false;
            }

            return null;
        }

        if ($guideMethodStorage->signatureReturnType) {
            $guideSignatureReturnType = ExpressionChecker::fleshOutType(
                $projectChecker,
                $guideMethodStorage->signatureReturnType,
                $guideClasslikeStorage->name,
                $guideClasslikeStorage->name
            );

            $implementerSignatureReturnType = $implementerMethodStorage->signatureReturnType
                ? ExpressionChecker::fleshOutType(
                    $projectChecker,
                    $implementerMethodStorage->signatureReturnType,
                    $implementerClasslikeStorage->name,
                    $implementerClasslikeStorage->name
                ) : null;

            if (!TypeChecker::isContainedByInPhp($implementerSignatureReturnType, $guideSignatureReturnType)) {
                if (IssueBuffer::accepts(
                    new MethodSignatureMismatch(
                        'Method ' . $casedImplementerMethodId . ' with return type \''
                            . $implementerSignatureReturnType . '\' is different to return type \''
                            . $guideSignatureReturnType . '\' of inherited method ' . $casedGuideMethodId,
                        $codeLocation
                    ),
                    $suppressedIssues
                )) {
                    return false;
                }

                return null;
            }
        } elseif ($guideMethodStorage->returnType
            && $implementerMethodStorage->returnType
            && $implementerClasslikeStorage->userDefined
            && !$guideClasslikeStorage->stubbed
        ) {
            $implementerMethodStorageReturnType = ExpressionChecker::fleshOutType(
                $projectChecker,
                $implementerMethodStorage->returnType,
                $implementerClasslikeStorage->name,
                $implementerClasslikeStorage->name
            );

            $guideMethodStorageReturnType = ExpressionChecker::fleshOutType(
                $projectChecker,
                $guideMethodStorage->returnType,
                $guideClasslikeStorage->name,
                $guideClasslikeStorage->name
            );

            // treat void as null when comparing against docblock implementer
            if ($implementerMethodStorageReturnType->isVoid()) {
                $implementerMethodStorageReturnType = Type::getNull();
            }

            if ($guideMethodStorageReturnType->isVoid()) {
                $guideMethodStorageReturnType = Type::getNull();
            }

            if (!TypeChecker::isContainedBy(
                $codebase,
                $implementerMethodStorageReturnType,
                $guideMethodStorageReturnType,
                false,
                false,
                $hasScalarMatch,
                $typeCoerced,
                $typeCoercedFromMixed
            )) {
                // is the declared return type more specific than the inferred one?
                if ($typeCoerced) {
                    if (IssueBuffer::accepts(
                        new LessSpecificImplementedReturnType(
                            'The return type \'' . $guideMethodStorage->returnType
                            . '\' for ' . $casedGuideMethodId . ' is more specific than the implemented '
                            . 'return type for ' . $implementerDeclaringMethodId . ' \''
                            . $implementerMethodStorage->returnType . '\'',
                            $implementerMethodStorage->returnTypeLocation ?: $codeLocation
                        ),
                        $suppressedIssues
                    )) {
                        return false;
                    }
                } else {
                    if (IssueBuffer::accepts(
                        new ImplementedReturnTypeMismatch(
                            'The return type \'' . $guideMethodStorage->returnType
                            . '\' for ' . $casedGuideMethodId . ' is different to the implemented '
                            . 'return type for ' . $implementerDeclaringMethodId . ' \''
                            . $implementerMethodStorage->returnType . '\'',
                            $implementerMethodStorage->returnTypeLocation ?: $codeLocation
                        ),
                        $suppressedIssues
                    )) {
                        return false;
                    }
                }
            }
        }

        foreach ($guideMethodStorage->params as $i => $guideParam) {
            if (!isset($implementerMethodStorage->params[$i])) {
                if (!$preventAbstractOverride && $i >= $guideMethodStorage->requiredParamCount) {
                    continue;
                }

                if (IssueBuffer::accepts(
                    new MethodSignatureMismatch(
                        'Method ' . $casedImplementerMethodId . ' has fewer parameters than parent method ' .
                            $casedGuideMethodId,
                        $codeLocation
                    )
                )) {
                    return false;
                }

                return null;
            }

            $implementerParam = $implementerMethodStorage->params[$i];

            if ($guideClasslikeStorage->userDefined
                && $implementerParam->signatureType
                && !TypeChecker::isContainedByInPhp($guideParam->signatureType, $implementerParam->signatureType)
            ) {
                if (IssueBuffer::accepts(
                    new MethodSignatureMismatch(
                        'Argument ' . ($i + 1) . ' of ' . $casedImplementerMethodId . ' has wrong type \'' .
                            $implementerParam->signatureType . '\', expecting \'' .
                            $guideParam->signatureType . '\' as defined by ' .
                            $casedGuideMethodId,
                        $implementerMethodStorage->params[$i]->location
                            ?: $codeLocation
                    )
                )) {
                    return false;
                }

                return null;
            }

            if ($guideClasslikeStorage->userDefined
                && $implementerParam->type
                && $guideParam->type
                && $implementerParam->type->getId() !== $guideParam->type->getId()
            ) {
                if (!TypeChecker::isContainedBy(
                    $codebase,
                    $guideParam->type,
                    $implementerParam->type,
                    false,
                    false
                )) {
                    if (IssueBuffer::accepts(
                        new MoreSpecificImplementedParamType(
                            'Argument ' . ($i + 1) . ' of ' . $casedImplementerMethodId . ' has wrong type \'' .
                                $implementerParam->type . '\', expecting \'' .
                                $guideParam->type . '\' as defined by ' .
                                $casedGuideMethodId,
                            $implementerMethodStorage->params[$i]->location
                                ?: $codeLocation
                        ),
                        $suppressedIssues
                    )) {
                        return false;
                    }
                }
            }

            if ($guideClasslikeStorage->userDefined && $implementerParam->byRef !== $guideParam->byRef) {
                if (IssueBuffer::accepts(
                    new MethodSignatureMismatch(
                        'Argument ' . ($i + 1) . ' of ' . $casedImplementerMethodId . ' is' .
                            ($implementerParam->byRef ? '' : ' not') . ' passed by reference, but argument ' .
                            ($i + 1) . ' of ' . $casedGuideMethodId . ' is' . ($guideParam->byRef ? '' : ' not'),
                        $implementerMethodStorage->params[$i]->location
                            ?: $codeLocation
                    )
                )) {
                    return false;
                }

                return null;
            }

            $implemeneterParamType = $implementerMethodStorage->params[$i]->type;

            $orNullGuideType = $guideParam->signatureType
                ? clone $guideParam->signatureType
                : null;

            if ($orNullGuideType) {
                $orNullGuideType->addType(new Type\Atomic\TNull);
            }

            if (!$guideClasslikeStorage->userDefined
                && $guideParam->type
                && !$guideParam->type->isMixed()
                && !$guideParam->type->fromDocblock
                && (
                    !$implemeneterParamType
                    || (
                        $implemeneterParamType->getId() !== $guideParam->type->getId()
                        && (
                            !$orNullGuideType
                            || $implemeneterParamType->getId() !== $orNullGuideType->getId()
                        )
                    )
                )
            ) {
                if (IssueBuffer::accepts(
                    new MethodSignatureMismatch(
                        'Argument ' . ($i + 1) . ' of ' . $casedImplementerMethodId . ' has wrong type \'' .
                            $implementerMethodStorage->params[$i]->type . '\', expecting \'' .
                            $guideParam->type . '\' as defined by ' .
                            $casedGuideMethodId,
                        $implementerMethodStorage->params[$i]->location
                            ?: $codeLocation
                    )
                )) {
                    return false;
                }

                return null;
            }
        }

        if ($guideClasslikeStorage->userDefined
            && $implementerMethodStorage->casedName !== '__construct'
            && $implementerMethodStorage->requiredParamCount > $guideMethodStorage->requiredParamCount
        ) {
            if (IssueBuffer::accepts(
                new MethodSignatureMismatch(
                    'Method ' . $casedImplementerMethodId . ' has more required parameters than parent method ' .
                        $casedGuideMethodId,
                    $codeLocation
                )
            )) {
                return false;
            }

            return null;
        }
    }

    /**
     * Check that __clone, __construct, and __destruct do not have a return type
     * hint in their signature.
     *
     * @param  MethodStorage $methodStorage
     * @param  CodeLocation  $codeLocation
     * @return false|null
     */
    public static function checkMethodSignatureMustOmitReturnType(
        MethodStorage $methodStorage,
        CodeLocation $codeLocation
    ) {
        if ($methodStorage->signatureReturnType === null) {
            return null;
        }

        $casedMethodName = $methodStorage->casedName;
        $methodsOfInterest = ['__clone', '__construct', '__destruct'];
        if (in_array($casedMethodName, $methodsOfInterest)) {
            if (IssueBuffer::accepts(
                new MethodSignatureMustOmitReturnType(
                    'Method ' . $casedMethodName . ' must not declare a return type',
                    $codeLocation
                )
            )) {
                return false;
            }
        }

        return null;
    }
}
