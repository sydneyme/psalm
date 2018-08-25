<?php
namespace Psalm\Checker\Statements\Expression\Assignment;

use PhpParser;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Stmt\PropertyProperty;
use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\FunctionLikeChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Checker\TypeChecker;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Issue\DeprecatedProperty;
use Psalm\Issue\ImplicitToStringCast;
use Psalm\Issue\InvalidPropertyAssignment;
use Psalm\Issue\InvalidPropertyAssignmentValue;
use Psalm\Issue\LoopInvalidation;
use Psalm\Issue\MixedAssignment;
use Psalm\Issue\MixedPropertyAssignment;
use Psalm\Issue\MixedTypeCoercion;
use Psalm\Issue\NoInterfaceProperties;
use Psalm\Issue\NullPropertyAssignment;
use Psalm\Issue\PossiblyFalsePropertyAssignmentValue;
use Psalm\Issue\PossiblyInvalidPropertyAssignment;
use Psalm\Issue\PossiblyInvalidPropertyAssignmentValue;
use Psalm\Issue\PossiblyNullPropertyAssignment;
use Psalm\Issue\PossiblyNullPropertyAssignmentValue;
use Psalm\Issue\TypeCoercion;
use Psalm\Issue\UndefinedClass;
use Psalm\Issue\UndefinedPropertyAssignment;
use Psalm\Issue\UndefinedThisPropertyAssignment;
use Psalm\IssueBuffer;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TObject;

class PropertyAssignmentChecker
{
    /**
     * @param   StatementsChecker               $statementsChecker
     * @param   PropertyFetch|PropertyProperty  $stmt
     * @param   string                          $propName
     * @param   PhpParser\Node\Expr|null        $assignmentValue
     * @param   Type\Union                      $assignmentValueType
     * @param   Context                         $context
     * @param   bool                            $directAssignment whether the variable is assigned explicitly
     *
     * @return  false|null
     */
    public static function analyzeInstance(
        StatementsChecker $statementsChecker,
        $stmt,
        $propName,
        $assignmentValue,
        Type\Union $assignmentValueType,
        Context $context,
        $directAssignment = true
    ) {
        $classPropertyTypes = [];

        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;

        $propertyExists = false;

        $propertyIds = [];

        if ($stmt instanceof PropertyProperty) {
            if (!$context->self || !$stmt->default) {
                return null;
            }

            $propertyId = $context->self . '::$' . $propName;
            $propertyIds[] = $propertyId;

            if (!$codebase->properties->propertyExists($propertyId)) {
                return null;
            }

            $propertyExists = true;

            $declaringPropertyClass = $codebase->properties->getDeclaringClassForProperty($propertyId);

            $classStorage = $projectChecker->classlikeStorageProvider->get((string)$declaringPropertyClass);

            $classPropertyType = $classStorage->properties[$propName]->type;

            $classPropertyTypes[] = $classPropertyType ? clone $classPropertyType : Type::getMixed();

            $varId = '$this->' . $propName;
        } else {
            if (ExpressionChecker::analyze($statementsChecker, $stmt->var, $context) === false) {
                return false;
            }

            $lhsType = isset($stmt->var->inferredType) ? $stmt->var->inferredType : null;

            if ($lhsType === null) {
                return null;
            }

            $lhsVarId = ExpressionChecker::getVarId(
                $stmt->var,
                $statementsChecker->getFQCLN(),
                $statementsChecker
            );

            $varId = ExpressionChecker::getVarId(
                $stmt,
                $statementsChecker->getFQCLN(),
                $statementsChecker
            );

            if ($varId) {
                $context->assignedVarIds[$varId] = true;

                if ($directAssignment && isset($context->protectedVarIds[$varId])) {
                    if (IssueBuffer::accepts(
                        new LoopInvalidation(
                            'Variable ' . $varId . ' has already been assigned in a for/foreach loop',
                            new CodeLocation($statementsChecker->getSource(), $stmt->var)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }
            }

            if ($lhsType->isMixed()) {
                $codebase->analyzer->incrementMixedCount($statementsChecker->getFilePath());

                if (IssueBuffer::accepts(
                    new MixedPropertyAssignment(
                        $lhsVarId . ' of type mixed cannot be assigned to',
                        new CodeLocation($statementsChecker->getSource(), $stmt->var)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }

                return null;
            }

            $codebase->analyzer->incrementNonMixedCount($statementsChecker->getFilePath());

            if ($lhsType->isNull()) {
                if (IssueBuffer::accepts(
                    new NullPropertyAssignment(
                        $lhsVarId . ' of type null cannot be assigned to',
                        new CodeLocation($statementsChecker->getSource(), $stmt->var)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }

                return null;
            }

            if ($lhsType->isNullable() && !$lhsType->ignoreNullableIssues) {
                if (IssueBuffer::accepts(
                    new PossiblyNullPropertyAssignment(
                        $lhsVarId . ' with possibly null type \'' . $lhsType . '\' cannot be assigned to',
                        new CodeLocation($statementsChecker->getSource(), $stmt->var)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }
            }

            $hasRegularSetter = false;

            $invalidAssignmentTypes = [];

            $hasValidAssignmentType = false;

            foreach ($lhsType->getTypes() as $lhsTypePart) {
                if ($lhsTypePart instanceof TNull) {
                    continue;
                }

                if (!$lhsTypePart instanceof TObject && !$lhsTypePart instanceof TNamedObject) {
                    $invalidAssignmentTypes[] = (string)$lhsTypePart;

                    continue;
                }

                $hasValidAssignmentType = true;

                // stdClass and SimpleXMLElement are special cases where we cannot infer the return types
                // but we don't want to throw an error
                // Hack has a similar issue: https://github.com/facebook/hhvm/issues/5164
                if ($lhsTypePart instanceof TObject ||
                    (
                        in_array(
                            strtolower($lhsTypePart->value),
                            ['stdclass', 'simplexmlelement', 'dateinterval', 'domdocument', 'domnode'],
                            true
                        )
                    )
                ) {
                    if ($varId) {
                        if ($lhsTypePart instanceof TNamedObject &&
                            strtolower($lhsTypePart->value) === 'stdclass'
                        ) {
                            $context->varsInScope[$varId] = $assignmentValueType;
                        } else {
                            $context->varsInScope[$varId] = Type::getMixed();
                        }
                    }

                    return null;
                }

                if (ExpressionChecker::isMock($lhsTypePart->value)) {
                    if ($varId) {
                        $context->varsInScope[$varId] = Type::getMixed();
                    }

                    return null;
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

                        return null;
                    }

                    if (IssueBuffer::accepts(
                        new UndefinedClass(
                            'Cannot set properties of undefined class ' . $lhsTypePart->value,
                            new CodeLocation($statementsChecker->getSource(), $stmt),
                            $lhsTypePart->value
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }

                    return null;
                }

                $propertyId = $lhsTypePart->value . '::$' . $propName;
                $propertyIds[] = $propertyId;

                $statementsCheckerSource = $statementsChecker->getSource();

                if ($codebase->methodExists($lhsTypePart->value . '::__set')
                    && (!$statementsCheckerSource instanceof FunctionLikeChecker
                        || $statementsCheckerSource->getMethodId() !== $lhsTypePart->value . '::__set')
                    && (!$context->self || !$codebase->classExtends($context->self, $lhsTypePart->value))
                    && (!$codebase->properties->propertyExists($propertyId)
                        || ($lhsVarId !== '$this'
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

                    if ($varId) {
                        if (isset($classStorage->pseudoPropertySetTypes['$' . $propName])) {
                            $classPropertyTypes[] =
                                clone $classStorage->pseudoPropertySetTypes['$' . $propName];

                            $hasRegularSetter = true;
                            $propertyExists = true;
                            continue;
                        }

                        $context->varsInScope[$varId] = Type::getMixed();
                    }

                    /*
                     * If we have an explicit list of all allowed magic properties on the class, and we're
                     * not in that list, fall through
                     */
                    if (!$varId || !$classStorage->sealedProperties) {
                        continue;
                    }
                }

                $hasRegularSetter = true;

                if (!$codebase->properties->propertyExists($propertyId)) {
                    if ($stmt->var instanceof PhpParser\Node\Expr\Variable && $stmt->var->name === 'this') {
                        // if this is a proper error, we'll see it on the first pass
                        if ($context->collectMutations) {
                            continue;
                        }

                        if (IssueBuffer::accepts(
                            new UndefinedThisPropertyAssignment(
                                'Instance property ' . $propertyId . ' is not defined',
                                new CodeLocation($statementsChecker->getSource(), $stmt),
                                $propertyId
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            return false;
                        }
                    } else {
                        if (IssueBuffer::accepts(
                            new UndefinedPropertyAssignment(
                                'Instance property ' . $propertyId . ' is not defined',
                                new CodeLocation($statementsChecker->getSource(), $stmt),
                                $propertyId
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            return false;
                        }
                    }

                    continue;
                }

                $propertyExists = true;

                if (!$context->collectMutations) {
                    if (ClassLikeChecker::checkPropertyVisibility(
                        $propertyId,
                        $context->self,
                        $statementsChecker->getSource(),
                        new CodeLocation($statementsChecker->getSource(), $stmt),
                        $statementsChecker->getSuppressedIssues()
                    ) === false) {
                        return false;
                    }
                } else {
                    if (ClassLikeChecker::checkPropertyVisibility(
                        $propertyId,
                        $context->self,
                        $statementsChecker->getSource(),
                        new CodeLocation($statementsChecker->getSource(), $stmt),
                        $statementsChecker->getSuppressedIssues(),
                        false
                    ) !== true) {
                        continue;
                    }
                }

                $declaringPropertyClass = $codebase->properties->getDeclaringClassForProperty(
                    $lhsTypePart->value . '::$' . $propName
                );

                $classStorage = $projectChecker->classlikeStorageProvider->get((string)$declaringPropertyClass);

                $propertyStorage = $classStorage->properties[$propName];

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
                    $classPropertyType = Type::getMixed();

                    if (!$assignmentValueType->isMixed()) {
                        if ($propertyStorage->suggestedType) {
                            $propertyStorage->suggestedType = Type::combineUnionTypes(
                                $assignmentValueType,
                                $propertyStorage->suggestedType
                            );
                        } else {
                            $propertyStorage->suggestedType =
                                $lhsVarId === '$this' &&
                                    ($context->insideConstructor || $context->collectInitializations)
                                    ? $assignmentValueType
                                    : Type::combineUnionTypes(Type::getNull(), $assignmentValueType);
                        }
                    }
                } else {
                    $classPropertyType = ExpressionChecker::fleshOutType(
                        $projectChecker,
                        $classPropertyType,
                        $lhsTypePart->value,
                        $lhsTypePart->value
                    );

                    if (!$classPropertyType->isMixed() && $assignmentValueType->isMixed()) {
                        if (IssueBuffer::accepts(
                            new MixedAssignment(
                                'Cannot assign ' . $varId . ' to a mixed type',
                                new CodeLocation($statementsChecker->getSource(), $stmt)
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    }
                }

                $classPropertyTypes[] = $classPropertyType;
            }

            if ($invalidAssignmentTypes) {
                $invalidAssignmentType = $invalidAssignmentTypes[0];

                if (!$hasValidAssignmentType) {
                    if (IssueBuffer::accepts(
                        new InvalidPropertyAssignment(
                            $lhsVarId . ' with non-object type \'' . $invalidAssignmentType .
                            '\' cannot treated as an object',
                            new CodeLocation($statementsChecker->getSource(), $stmt->var)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }
                } else {
                    if (IssueBuffer::accepts(
                        new PossiblyInvalidPropertyAssignment(
                            $lhsVarId . ' with possible non-object type \'' . $invalidAssignmentType .
                            '\' cannot treated as an object',
                            new CodeLocation($statementsChecker->getSource(), $stmt->var)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }
                }
            }

            if (!$hasRegularSetter) {
                return null;
            }

            if ($varId) {
                // because we don't want to be assigning for property declarations
                $context->varsInScope[$varId] = $assignmentValueType;
            }
        }

        if (!$propertyExists) {
            return null;
        }

        if ($assignmentValueType->isMixed()) {
            return null;
        }

        $invalidAssignmentValueTypes = [];

        $hasValidAssignmentValueType = false;

        foreach ($classPropertyTypes as $classPropertyType) {
            if ($classPropertyType->isMixed()) {
                continue;
            }

            $typeMatchFound = TypeChecker::isContainedBy(
                $projectChecker->codebase,
                $assignmentValueType,
                $classPropertyType,
                true,
                true,
                $hasScalarMatch,
                $typeCoerced,
                $typeCoercedFromMixed,
                $toStringCast
            );

            if ($typeCoerced) {
                if ($typeCoercedFromMixed) {
                    if (IssueBuffer::accepts(
                        new MixedTypeCoercion(
                            $varId . ' expects \'' . $classPropertyType . '\', '
                                . ' parent type `' . $assignmentValueType . '` provided',
                            new CodeLocation(
                                $statementsChecker->getSource(),
                                $assignmentValue ?: $stmt,
                                $context->includeLocation
                            )
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // keep soldiering on
                    }
                } else {
                    if (IssueBuffer::accepts(
                        new TypeCoercion(
                            $varId . ' expects \'' . $classPropertyType . '\', '
                                . ' parent type \'' . $assignmentValueType . '\' provided',
                            new CodeLocation(
                                $statementsChecker->getSource(),
                                $assignmentValue ?: $stmt,
                                $context->includeLocation
                            )
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // keep soldiering on
                    }
                }
            }

            if ($toStringCast) {
                if (IssueBuffer::accepts(
                    new ImplicitToStringCast(
                        $varId . ' expects \'' . $classPropertyType . '\', '
                            . '\'' . $assignmentValueType . '\' provided with a __toString method',
                        new CodeLocation(
                            $statementsChecker->getSource(),
                            $assignmentValue ?: $stmt,
                            $context->includeLocation
                        )
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    // fall through
                }
            }

            if (!$typeMatchFound && !$typeCoerced) {
                if (TypeChecker::canBeContainedBy(
                    $projectChecker->codebase,
                    $assignmentValueType,
                    $classPropertyType,
                    true,
                    true
                )) {
                    $hasValidAssignmentValueType = true;
                }

                $invalidAssignmentValueTypes[] = $classPropertyType->getId();
            } else {
                $hasValidAssignmentValueType = true;
            }

            if ($typeMatchFound) {
                if (!$assignmentValueType->ignoreNullableIssues
                    && $assignmentValueType->isNullable()
                    && !$classPropertyType->isNullable()
                ) {
                    if (IssueBuffer::accepts(
                        new PossiblyNullPropertyAssignmentValue(
                            $varId . ' with non-nullable declared type \'' . $classPropertyType .
                                '\' cannot be assigned nullable type \'' . $assignmentValueType . '\'',
                            new CodeLocation(
                                $statementsChecker->getSource(),
                                $assignmentValue ?: $stmt,
                                $context->includeLocation
                            ),
                            $propertyIds[0]
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }
                }

                if (!$assignmentValueType->ignoreFalsableIssues
                    && $assignmentValueType->isFalsable()
                    && !$classPropertyType->hasBool()
                ) {
                    if (IssueBuffer::accepts(
                        new PossiblyFalsePropertyAssignmentValue(
                            $varId . ' with non-falsable declared type \'' . $classPropertyType .
                                '\' cannot be assigned possibly false type \'' . $assignmentValueType . '\'',
                            new CodeLocation(
                                $statementsChecker->getSource(),
                                $assignmentValue ?: $stmt,
                                $context->includeLocation
                            ),
                            $propertyIds[0]
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }
                }
            }
        }

        if ($invalidAssignmentValueTypes) {
            $invalidClassPropertyType = $invalidAssignmentValueTypes[0];

            if (!$hasValidAssignmentValueType) {
                if (IssueBuffer::accepts(
                    new InvalidPropertyAssignmentValue(
                        $varId . ' with declared type \'' . $invalidClassPropertyType .
                            '\' cannot be assigned type \'' . $assignmentValueType->getId() . '\'',
                        new CodeLocation(
                            $statementsChecker->getSource(),
                            $assignmentValue ?: $stmt,
                            $context->includeLocation
                        ),
                        $propertyIds[0]
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }
            } else {
                if (IssueBuffer::accepts(
                    new PossiblyInvalidPropertyAssignmentValue(
                        $varId . ' with declared type \'' . $invalidClassPropertyType .
                            '\' cannot be assigned possibly different type \'' .
                            $assignmentValueType->getId() . '\'',
                        new CodeLocation(
                            $statementsChecker->getSource(),
                            $assignmentValue ?: $stmt,
                            $context->includeLocation
                        ),
                        $propertyIds[0]
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }
            }
        }

        return null;
    }

    /**
     * @param   StatementsChecker                         $statementsChecker
     * @param   PhpParser\Node\Expr\StaticPropertyFetch   $stmt
     * @param   PhpParser\Node\Expr|null                  $assignmentValue
     * @param   Type\Union                                $assignmentValueType
     * @param   Context                                   $context
     *
     * @return  false|null
     */
    public static function analyzeStatic(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\StaticPropertyFetch $stmt,
        $assignmentValue,
        Type\Union $assignmentValueType,
        Context $context
    ) {
        $varId = ExpressionChecker::getVarId(
            $stmt,
            $statementsChecker->getFQCLN(),
            $statementsChecker
        );

        $fqClassName = (string)$stmt->class->inferredType;

        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;

        $propName = $stmt->name;

        if (!$propName instanceof PhpParser\Node\Identifier) {
            return;
        }

        $propertyId = $fqClassName . '::$' . $propName;

        if (!$codebase->properties->propertyExists($propertyId)) {
            if (IssueBuffer::accepts(
                new UndefinedPropertyAssignment(
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
            $fqClassName . '::$' . $propName->name
        );

        $classStorage = $projectChecker->classlikeStorageProvider->get((string)$declaringPropertyClass);

        $propertyStorage = $classStorage->properties[$propName->name];

        if ($varId) {
            $context->varsInScope[$varId] = $assignmentValueType;
        }

        $classPropertyType = $propertyStorage->type;

        if ($classPropertyType === false) {
            $classPropertyType = Type::getMixed();

            if (!$assignmentValueType->isMixed()) {
                if ($propertyStorage->suggestedType) {
                    $propertyStorage->suggestedType = Type::combineUnionTypes(
                        $assignmentValueType,
                        $propertyStorage->suggestedType
                    );
                } else {
                    $propertyStorage->suggestedType = Type::combineUnionTypes(
                        Type::getNull(),
                        $assignmentValueType
                    );
                }
            }
        } else {
            $classPropertyType = clone $classPropertyType;
        }

        if ($assignmentValueType->isMixed()) {
            return null;
        }

        if ($classPropertyType->isMixed()) {
            return null;
        }

        $classPropertyType = ExpressionChecker::fleshOutType(
            $projectChecker,
            $classPropertyType,
            $fqClassName,
            $fqClassName
        );

        $typeMatchFound = TypeChecker::isContainedBy(
            $projectChecker->codebase,
            $assignmentValueType,
            $classPropertyType,
            true,
            true,
            $hasScalarMatch,
            $typeCoerced,
            $typeCoercedFromMixed,
            $toStringCast
        );

        if ($typeCoerced) {
            if ($typeCoercedFromMixed) {
                if (IssueBuffer::accepts(
                    new MixedTypeCoercion(
                        $varId . ' expects \'' . $classPropertyType . '\', '
                            . ' parent type `' . $assignmentValueType . '` provided',
                        new CodeLocation(
                            $statementsChecker->getSource(),
                            $assignmentValue ?: $stmt,
                            $context->includeLocation
                        )
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    // keep soldiering on
                }
            } else {
                if (IssueBuffer::accepts(
                    new TypeCoercion(
                        $varId . ' expects \'' . $classPropertyType . '\', '
                            . ' parent type \'' . $assignmentValueType . '\' provided',
                        new CodeLocation(
                            $statementsChecker->getSource(),
                            $assignmentValue ?: $stmt,
                            $context->includeLocation
                        )
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    // keep soldiering on
                }
            }
        }

        if ($toStringCast) {
            if (IssueBuffer::accepts(
                new ImplicitToStringCast(
                    $varId . ' expects \'' . $classPropertyType . '\', '
                        . '\'' . $assignmentValueType . '\' provided with a __toString method',
                    new CodeLocation(
                        $statementsChecker->getSource(),
                        $assignmentValue ?: $stmt,
                        $context->includeLocation
                    )
                ),
                $statementsChecker->getSuppressedIssues()
            )) {
                // fall through
            }
        }

        if (!$typeMatchFound && !$typeCoerced) {
            if (TypeChecker::canBeContainedBy($codebase, $assignmentValueType, $classPropertyType)) {
                if (IssueBuffer::accepts(
                    new PossiblyInvalidPropertyAssignmentValue(
                        $varId . ' with declared type \'' . $classPropertyType . '\' cannot be assigned type \'' .
                            $assignmentValueType . '\'',
                        new CodeLocation(
                            $statementsChecker->getSource(),
                            $assignmentValue ?: $stmt
                        ),
                        $propertyId
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }
            } else {
                if (IssueBuffer::accepts(
                    new InvalidPropertyAssignmentValue(
                        $varId . ' with declared type \'' . $classPropertyType . '\' cannot be assigned type \'' .
                            $assignmentValueType . '\'',
                        new CodeLocation(
                            $statementsChecker->getSource(),
                            $assignmentValue ?: $stmt
                        ),
                        $propertyId
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }
            }
        }

        if ($varId) {
            $context->varsInScope[$varId] = $assignmentValueType;
        }

        return null;
    }
}
