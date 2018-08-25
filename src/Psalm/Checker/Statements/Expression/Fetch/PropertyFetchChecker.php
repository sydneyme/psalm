<?php
namespace Psalm\Checker\Statements\Expression\Fetch;

use PhpParser;
use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\FunctionLikeChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Issue\DeprecatedProperty;
use Psalm\Issue\InvalidPropertyFetch;
use Psalm\Issue\MissingPropertyType;
use Psalm\Issue\MixedPropertyFetch;
use Psalm\Issue\NoInterfaceProperties;
use Psalm\Issue\NullPropertyFetch;
use Psalm\Issue\ParentNotFound;
use Psalm\Issue\PossiblyInvalidPropertyFetch;
use Psalm\Issue\PossiblyNullPropertyFetch;
use Psalm\Issue\UndefinedClass;
use Psalm\Issue\UndefinedPropertyFetch;
use Psalm\Issue\UndefinedThisPropertyFetch;
use Psalm\IssueBuffer;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TObject;

class PropertyFetchChecker
{
    /**
     * @param   StatementsChecker                   $statementsChecker
     * @param   PhpParser\Node\Expr\PropertyFetch   $stmt
     * @param   Context                             $context
     *
     * @return  false|null
     */
    public static function analyzeInstance(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\PropertyFetch $stmt,
        Context $context
    ) {
        if (!$stmt->name instanceof PhpParser\Node\Identifier) {
            if (ExpressionChecker::analyze($statementsChecker, $stmt->name, $context) === false) {
                return false;
            }
        }

        if (ExpressionChecker::analyze($statementsChecker, $stmt->var, $context) === false) {
            return false;
        }

        if ($stmt->name instanceof PhpParser\Node\Identifier) {
            $propName = $stmt->name->name;
        } elseif (isset($stmt->name->inferredType)
            && $stmt->name->inferredType->isSingleStringLiteral()
        ) {
            $propName = $stmt->name->inferredType->getSingleStringLiteral()->value;
        } else {
            $propName = null;
        }

        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;

        $stmtVarId = ExpressionChecker::getArrayVarId(
            $stmt->var,
            $statementsChecker->getFQCLN(),
            $statementsChecker
        );

        $varId = ExpressionChecker::getArrayVarId(
            $stmt,
            $statementsChecker->getFQCLN(),
            $statementsChecker
        );

        $stmtVarType = null;
        $stmt->inferredType = null;

        if ($varId && $context->hasVariable($varId, $statementsChecker)) {
            // we don't need to check anything
            $stmt->inferredType = $context->varsInScope[$varId];

            $codebase->analyzer->incrementNonMixedCount($statementsChecker->getFilePath());

            if ($context->collectReferences
                && isset($stmt->var->inferredType)
                && $stmt->var->inferredType->hasObjectType()
                && $stmt->name instanceof PhpParser\Node\Identifier
            ) {
                // log the appearance
                foreach ($stmt->var->inferredType->getTypes() as $lhsTypePart) {
                    if ($lhsTypePart instanceof TNamedObject) {
                        if (!$codebase->classExists($lhsTypePart->value)) {
                            continue;
                        }

                        $propertyId = $lhsTypePart->value . '::$' . $stmt->name->name;

                        $codebase->properties->propertyExists(
                            $propertyId,
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        );
                    }
                }
            }

            return null;
        }

        if ($stmtVarId && $context->hasVariable($stmtVarId, $statementsChecker)) {
            $stmtVarType = $context->varsInScope[$stmtVarId];
        } elseif (isset($stmt->var->inferredType)) {
            $stmtVarType = $stmt->var->inferredType;
        }

        if (!$stmtVarType) {
            return null;
        }

        if ($stmtVarType->isNull()) {
            if (IssueBuffer::accepts(
                new NullPropertyFetch(
                    'Cannot get property on null variable ' . $stmtVarId,
                    new CodeLocation($statementsChecker->getSource(), $stmt)
                ),
                $statementsChecker->getSuppressedIssues()
            )) {
                return false;
            }

            return null;
        }

        if ($stmtVarType->isEmpty()) {
            if (IssueBuffer::accepts(
                new MixedPropertyFetch(
                    'Cannot fetch property on empty var ' . $stmtVarId,
                    new CodeLocation($statementsChecker->getSource(), $stmt)
                ),
                $statementsChecker->getSuppressedIssues()
            )) {
                return false;
            }

            return null;
        }

        if ($stmtVarType->isMixed()) {
            $codebase->analyzer->incrementMixedCount($statementsChecker->getFilePath());

            if (IssueBuffer::accepts(
                new MixedPropertyFetch(
                    'Cannot fetch property on mixed var ' . $stmtVarId,
                    new CodeLocation($statementsChecker->getSource(), $stmt)
                ),
                $statementsChecker->getSuppressedIssues()
            )) {
                // fall through
            }

            $stmt->inferredType = Type::getMixed();

            return null;
        }

        $codebase->analyzer->incrementNonMixedCount($statementsChecker->getRootFilePath());

        if ($stmtVarType->isNullable() && !$stmtVarType->ignoreNullableIssues && !$context->insideIsset) {
            if (IssueBuffer::accepts(
                new PossiblyNullPropertyFetch(
                    'Cannot get property on possibly null variable ' . $stmtVarId . ' of type ' . $stmtVarType,
                    new CodeLocation($statementsChecker->getSource(), $stmt)
                ),
                $statementsChecker->getSuppressedIssues()
            )) {
                // fall through
            }

            $stmt->inferredType = Type::getNull();
        }

        if (!$propName) {
            return null;
        }

        $invalidFetchTypes = [];
        $hasValidFetchType = false;

        foreach ($stmtVarType->getTypes() as $lhsTypePart) {
            if ($lhsTypePart instanceof TNull) {
                continue;
            }

            if ($lhsTypePart instanceof Type\Atomic\TFalse && $stmtVarType->ignoreFalsableIssues) {
                continue;
            }

            if (!$lhsTypePart instanceof TNamedObject && !$lhsTypePart instanceof TObject) {
                $invalidFetchTypes[] = (string)$lhsTypePart;

                continue;
            }

            $hasValidFetchType = true;

            // stdClass and SimpleXMLElement are special cases where we cannot infer the return types
            // but we don't want to throw an error
            // Hack has a similar issue: https://github.com/facebook/hhvm/issues/5164
            if ($lhsTypePart instanceof TObject
                || in_array(strtolower($lhsTypePart->value), ['stdclass', 'simplexmlelement'], true)
            ) {
                $stmt->inferredType = Type::getMixed();
                continue;
            }

            if (ExpressionChecker::isMock($lhsTypePart->value)) {
                $stmt->inferredType = Type::getMixed();
                continue;
            }

            if (!$codebase->classExists($lhsTypePart->value)) {
                if ($codebase->interfaceExists($lhsTypePart->value)) {
                    if (IssueBuffer::accepts(
                        new NoInterfaceProperties(
                            'Interfaces cannot have properties',
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }

                    continue;
                }

                if (IssueBuffer::accepts(
                    new UndefinedClass(
                        'Cannot get properties of undefined class ' . $lhsTypePart->value,
                        new CodeLocation($statementsChecker->getSource(), $stmt),
                        $lhsTypePart->value
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }

                continue;
            }

            $propertyId = $lhsTypePart->value . '::$' . $propName;

            $statementsCheckerSource = $statementsChecker->getSource();

            if ($codebase->methodExists($lhsTypePart->value . '::__get')
                && (!$statementsCheckerSource instanceof FunctionLikeChecker
                    || $statementsCheckerSource->getMethodId() !== $lhsTypePart->value . '::__get')
                && (!$context->self || !$codebase->classExtends($context->self, $lhsTypePart->value))
                && (!$codebase->properties->propertyExists($propertyId)
                    || ($stmtVarId !== '$this'
                        && $lhsTypePart->value !== $context->self
                        && ClassLikeChecker::checkPropertyVisibility(
                            $propertyId,
                            $context->self,
                            $statementsCheckerSource,
                            new CodeLocation($statementsChecker->getSource(), $stmt),
                            $statementsChecker->getSuppressedIssues(),
                            false
                        ) !== true)
                )
            ) {
                $classStorage = $projectChecker->classlikeStorageProvider->get((string)$lhsTypePart);

                if (isset($classStorage->pseudoPropertyGetTypes['$' . $propName])) {
                    $stmt->inferredType = clone $classStorage->pseudoPropertyGetTypes['$' . $propName];
                    continue;
                }

                $stmt->inferredType = Type::getMixed();
                /*
                 * If we have an explicit list of all allowed magic properties on the class, and we're
                 * not in that list, fall through
                 */
                if (!$classStorage->sealedProperties) {
                    continue;
                }
            }

            if (!$codebase->properties->propertyExists(
                $propertyId,
                $context->collectReferences ? new CodeLocation($statementsChecker->getSource(), $stmt) : null
            )
            ) {
                if ($context->insideIsset) {
                    return;
                }

                if ($stmtVarId === '$this') {
                    if ($context->collectMutations) {
                        return;
                    }

                    if (IssueBuffer::accepts(
                        new UndefinedThisPropertyFetch(
                            'Instance property ' . $propertyId . ' is not defined',
                            new CodeLocation($statementsChecker->getSource(), $stmt),
                            $propertyId
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                } else {
                    if (IssueBuffer::accepts(
                        new UndefinedPropertyFetch(
                            'Instance property ' . $propertyId . ' is not defined',
                            new CodeLocation($statementsChecker->getSource(), $stmt),
                            $propertyId
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }

                $stmt->inferredType = Type::getMixed();

                if ($varId) {
                    $context->varsInScope[$varId] = $stmt->inferredType;
                }

                return;
            }

            if (ClassLikeChecker::checkPropertyVisibility(
                $propertyId,
                $context->self,
                $statementsChecker->getSource(),
                new CodeLocation($statementsChecker->getSource(), $stmt),
                $statementsChecker->getSuppressedIssues()
            ) === false) {
                return false;
            }

            $declaringPropertyClass = $codebase->properties->getDeclaringClassForProperty($propertyId);

            $declaringClassStorage = $projectChecker->classlikeStorageProvider->get(
                (string)$declaringPropertyClass
            );

            $propertyStorage = $declaringClassStorage->properties[$propName];

            if ($propertyStorage->deprecated) {
                if (IssueBuffer::accepts(
                    new DeprecatedProperty(
                        $propertyId . ' is marked deprecated',
                        new CodeLocation($statementsChecker->getSource(), $stmt),
                        $propertyId
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    // fall through
                }
            }

            $classPropertyType = $propertyStorage->type;

            if ($classPropertyType === false) {
                if (IssueBuffer::accepts(
                    new MissingPropertyType(
                        'Property ' . $lhsTypePart->value . '::$' . $propName
                            . ' does not have a declared type',
                        new CodeLocation($statementsChecker->getSource(), $stmt)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    // fall through
                }

                $classPropertyType = Type::getMixed();
            } else {
                $classPropertyType = ExpressionChecker::fleshOutType(
                    $projectChecker,
                    clone $classPropertyType,
                    $declaringPropertyClass,
                    $declaringPropertyClass
                );

                if ($lhsTypePart instanceof TGenericObject) {
                    $classStorage = $projectChecker->classlikeStorageProvider->get($lhsTypePart->value);

                    if ($classStorage->templateTypes) {
                        $classTemplateParams = [];

                        $reversedClassTemplateTypes = array_reverse(array_keys($classStorage->templateTypes));

                        $providedTypeParamCount = count($lhsTypePart->typeParams);

                        foreach ($reversedClassTemplateTypes as $i => $typeName) {
                            if (isset($lhsTypePart->typeParams[$providedTypeParamCount - 1 - $i])) {
                                $classTemplateParams[$typeName] =
                                    (string)$lhsTypePart->typeParams[$providedTypeParamCount - 1 - $i];
                            } else {
                                $classTemplateParams[$typeName] = 'mixed';
                            }
                        }

                        $typeTokens = Type::tokenize((string)$classPropertyType);

                        foreach ($typeTokens as &$typeToken) {
                            if (isset($classTemplateParams[$typeToken])) {
                                $typeToken = $classTemplateParams[$typeToken];
                            }
                        }

                        $classPropertyType = Type::parseString(implode('', $typeTokens));
                    }
                }
            }

            if (isset($stmt->inferredType)) {
                $stmt->inferredType = Type::combineUnionTypes($classPropertyType, $stmt->inferredType);
            } else {
                $stmt->inferredType = $classPropertyType;
            }
        }

        if ($invalidFetchTypes) {
            $lhsTypePart = $invalidFetchTypes[0];

            if ($hasValidFetchType) {
                if (IssueBuffer::accepts(
                    new PossiblyInvalidPropertyFetch(
                        'Cannot fetch property on possible non-object ' . $stmtVarId . ' of type ' . $lhsTypePart,
                        new CodeLocation($statementsChecker->getSource(), $stmt)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    // fall through
                }
            } else {
                if (IssueBuffer::accepts(
                    new InvalidPropertyFetch(
                        'Cannot fetch property on non-object ' . $stmtVarId . ' of type ' . $lhsTypePart,
                        new CodeLocation($statementsChecker->getSource(), $stmt)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    // fall through
                }
            }
        }

        if ($varId) {
            $context->varsInScope[$varId] = isset($stmt->inferredType) ? $stmt->inferredType : Type::getMixed();
        }
    }

    /**
     * @param   StatementsChecker                       $statementsChecker
     * @param   PhpParser\Node\Expr\StaticPropertyFetch $stmt
     * @param   Context                                 $context
     *
     * @return  null|false
     */
    public static function analyzeStatic(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\StaticPropertyFetch $stmt,
        Context $context
    ) {
        if ($stmt->class instanceof PhpParser\Node\Expr\Variable ||
            $stmt->class instanceof PhpParser\Node\Expr\ArrayDimFetch
        ) {
            // @todo check this
            return null;
        }

        $fqClassName = null;

        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;

        if ($stmt->class instanceof PhpParser\Node\Name) {
            if (count($stmt->class->parts) === 1
                && in_array(strtolower($stmt->class->parts[0]), ['self', 'static', 'parent'], true)
            ) {
                if ($stmt->class->parts[0] === 'parent') {
                    $fqClassName = $statementsChecker->getParentFQCLN();

                    if ($fqClassName === null) {
                        if (IssueBuffer::accepts(
                            new ParentNotFound(
                                'Cannot check property fetch on parent as this class does not extend another',
                                new CodeLocation($statementsChecker->getSource(), $stmt)
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            return false;
                        }

                        return;
                    }
                } else {
                    $fqClassName = (string)$context->self;
                }

                if ($context->isPhantomClass($fqClassName)) {
                    return null;
                }
            } else {
                $fqClassName = ClassLikeChecker::getFQCLNFromNameObject(
                    $stmt->class,
                    $statementsChecker->getAliases()
                );

                if ($context->isPhantomClass($fqClassName)) {
                    return null;
                }

                if ($context->checkClasses) {
                    if (ClassLikeChecker::checkFullyQualifiedClassLikeName(
                        $statementsChecker,
                        $fqClassName,
                        new CodeLocation($statementsChecker->getSource(), $stmt->class),
                        $statementsChecker->getSuppressedIssues(),
                        false
                    ) !== true) {
                        return false;
                    }
                }
            }

            $stmt->class->inferredType = $fqClassName ? new Type\Union([new TNamedObject($fqClassName)]) : null;
        }

        if ($stmt->name instanceof PhpParser\Node\VarLikeIdentifier) {
            $propName = $stmt->name->name;
        } elseif (isset($stmt->name->inferredType)
            && $stmt->name->inferredType->isSingleStringLiteral()
        ) {
            $propName = $stmt->name->inferredType->getSingleStringLiteral()->value;
        } else {
            $propName = null;
        }

        if ($fqClassName &&
            $context->checkClasses &&
            $context->checkVariables &&
            $propName &&
            !ExpressionChecker::isMock($fqClassName)
        ) {
            $varId = ExpressionChecker::getVarId(
                $stmt,
                $statementsChecker->getFQCLN(),
                $statementsChecker
            );

            $propertyId = $fqClassName . '::$' . $propName;

            if ($varId && $context->hasVariable($varId, $statementsChecker)) {
                // we don't need to check anything
                $stmt->inferredType = $context->varsInScope[$varId];

                if ($context->collectReferences) {
                    // log the appearance
                    $codebase->properties->propertyExists(
                        $propertyId,
                        new CodeLocation($statementsChecker->getSource(), $stmt)
                    );
                }

                return null;
            }

            if (!$codebase->properties->propertyExists(
                $propertyId,
                $context->collectReferences ? new CodeLocation($statementsChecker->getSource(), $stmt) : null
            )
            ) {
                if (IssueBuffer::accepts(
                    new UndefinedPropertyFetch(
                        'Static property ' . $propertyId . ' is not defined',
                        new CodeLocation($statementsChecker->getSource(), $stmt),
                        $propertyId
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }

                return;
            }

            if (ClassLikeChecker::checkPropertyVisibility(
                $propertyId,
                $context->self,
                $statementsChecker->getSource(),
                new CodeLocation($statementsChecker->getSource(), $stmt),
                $statementsChecker->getSuppressedIssues()
            ) === false) {
                return false;
            }

            $declaringPropertyClass = $codebase->properties->getDeclaringClassForProperty(
                $fqClassName . '::$' . $propName
            );

            $classStorage = $projectChecker->classlikeStorageProvider->get((string)$declaringPropertyClass);
            $property = $classStorage->properties[$propName];

            if ($varId) {
                $context->varsInScope[$varId] = $property->type
                    ? clone $property->type
                    : Type::getMixed();

                $stmt->inferredType = clone $context->varsInScope[$varId];
            } else {
                $stmt->inferredType = Type::getMixed();
            }
        }

        return null;
    }
}
