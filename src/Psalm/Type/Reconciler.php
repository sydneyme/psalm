<?php
namespace Psalm\Type;

use Psalm\Checker\AlgebraChecker;
use Psalm\Checker\ProjectChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Checker\TraitChecker;
use Psalm\Checker\TypeChecker;
use Psalm\CodeLocation;
use Psalm\Issue\DocblockTypeContradiction;
use Psalm\Issue\ParadoxicalCondition;
use Psalm\Issue\RedundantCondition;
use Psalm\Issue\RedundantConditionGivenDocblockType;
use Psalm\Issue\TypeDoesNotContainNull;
use Psalm\Issue\TypeDoesNotContainType;
use Psalm\IssueBuffer;
use Psalm\Type;
use Psalm\Type\Atomic\ObjectLike;
use Psalm\Type\Atomic\Scalar;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TEmpty;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TGenericParam;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TNumeric;
use Psalm\Type\Atomic\TNumericString;
use Psalm\Type\Atomic\TObject;
use Psalm\Type\Atomic\TResource;
use Psalm\Type\Atomic\TScalar;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TTrue;

class Reconciler
{
    /** @var array<string, array<int, string>> */
    private static $brokenPaths = [];

    /**
     * Takes two arrays and consolidates them, removing null values from existing types where applicable
     *
     * @param  array<string, string[][]> $newTypes
     * @param  array<string, Type\Union> $existingTypes
     * @param  array<string>             $changedVarIds
     * @param  array<string, bool>       $referencedVarIds
     * @param  StatementsChecker         $statementsChecker
     * @param  CodeLocation|null         $codeLocation
     * @param  array<string>             $suppressedIssues
     *
     * @return array<string, Type\Union>
     */
    public static function reconcileKeyedTypes(
        array $newTypes,
        array $existingTypes,
        array &$changedVarIds,
        array $referencedVarIds,
        StatementsChecker $statementsChecker,
        CodeLocation $codeLocation = null,
        array $suppressedIssues = []
    ) {
        foreach ($newTypes as $nk => $type) {
            if ((strpos($nk, '[') || strpos($nk, '->'))
                && ($type[0][0] === '^isset'
                    || $type[0][0] === '!^empty'
                    || $type[0][0] === 'isset'
                    || $type[0][0] === '!empty')
            ) {
                $issetOrEmpty = $type[0][0] === 'isset' || $type[0][0] === '^isset'
                    ? '^isset'
                    : '!^empty';

                $keyParts = Reconciler::breakUpPathIntoParts($nk);

                $baseKey = array_shift($keyParts);

                if (!isset($newTypes[$baseKey])) {
                    $newTypes[$baseKey] = [['!^bool'], ['!^int'], ['^isset']];
                } else {
                    $newTypes[$baseKey][] = ['!^bool'];
                    $newTypes[$baseKey][] = ['!^int'];
                    $newTypes[$baseKey][] = ['^isset'];
                }

                while ($keyParts) {
                    $divider = array_shift($keyParts);

                    if ($divider === '[') {
                        $arrayKey = array_shift($keyParts);
                        array_shift($keyParts);

                        $newBaseKey = $baseKey . '[' . $arrayKey . ']';

                        $baseKey = $newBaseKey;
                    } elseif ($divider === '->') {
                        $propertyName = array_shift($keyParts);
                        $newBaseKey = $baseKey . '->' . $propertyName;

                        $baseKey = $newBaseKey;
                    } else {
                        throw new \InvalidArgumentException('Unexpected divider ' . $divider);
                    }

                    if (!$keyParts) {
                        break;
                    }

                    if (!isset($newTypes[$baseKey])) {
                        $newTypes[$baseKey] = [['!^bool'], ['!^int'], ['^isset']];
                    } else {
                        $newTypes[$baseKey][] = ['!^bool'];
                        $newTypes[$baseKey][] = ['!^int'];
                        $newTypes[$baseKey][] = ['^isset'];
                    }
                }

                // replace with a less specific check
                $newTypes[$nk][0][0] = $issetOrEmpty;
            }
        }

        // make sure array keys come after base keys
        ksort($newTypes);

        if (empty($newTypes)) {
            return $existingTypes;
        }

        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;

        foreach ($newTypes as $key => $newTypeParts) {
            $resultType = isset($existingTypes[$key])
                ? clone $existingTypes[$key]
                : self::getValueForKey($projectChecker, $key, $existingTypes);

            if ($resultType && empty($resultType->getTypes())) {
                throw new \InvalidArgumentException('Union::$types cannot be empty after get value for ' . $key);
            }

            $beforeAdjustment = $resultType ? clone $resultType : null;

            $failedReconciliation = false;
            $hasNegation = false;
            $hasEquality = false;
            $hasIsset = false;

            foreach ($newTypeParts as $newTypePartParts) {
                $orredType = null;

                foreach ($newTypePartParts as $newTypePartPart) {
                    switch ($newTypePartPart[0]) {
                        case '!':
                            $hasNegation = true;
                            break;
                        case '^':
                        case '~':
                            $hasEquality = true;
                    }

                    $hasIsset = $hasIsset
                        || $newTypePartPart === 'isset'
                        || $newTypePartPart === 'array-key-exists';

                    $resultTypeCandidate = self::reconcileTypes(
                        $newTypePartPart,
                        $resultType ? clone $resultType : null,
                        $key,
                        $statementsChecker,
                        $codeLocation && isset($referencedVarIds[$key]) ? $codeLocation : null,
                        $suppressedIssues,
                        $failedReconciliation
                    );

                    if (!$resultTypeCandidate->getTypes()) {
                        $resultTypeCandidate->addType(new TEmpty);
                    }

                    $orredType = $orredType
                        ? Type::combineUnionTypes($resultTypeCandidate, $orredType)
                        : $resultTypeCandidate;
                }

                $resultType = $orredType;
            }

            if (!$resultType) {
                throw new \UnexpectedValueException('$resultType should not be null');
            }

            $typeChanged = !$beforeAdjustment || !$resultType->equals($beforeAdjustment);

            if ($typeChanged || $failedReconciliation) {
                $changedVarIds[] = $key;

                if (substr($key, -1) === ']') {
                    $keyParts = self::breakUpPathIntoParts($key);
                    self::adjustObjectLikeType(
                        $keyParts,
                        $existingTypes,
                        $changedVarIds,
                        $resultType
                    );
                }
            } elseif ($codeLocation
                && isset($referencedVarIds[$key])
                && !$hasNegation
                && !$hasEquality
                && !$resultType->isMixed()
                && (!$hasIsset || substr($key, -1, 1) !== ']')
            ) {
                $reconcileKey = implode(
                    '&',
                    array_map(
                        /**
                         * @return string
                         */
                        function (array $newTypePartParts) {
                            return implode('|', $newTypePartParts);
                        },
                        $newTypeParts
                    )
                );

                self::triggerIssueForImpossible(
                    $resultType,
                    $beforeAdjustment ? $beforeAdjustment->getId() : '',
                    $key,
                    $reconcileKey,
                    !$typeChanged,
                    $codeLocation,
                    $suppressedIssues
                );
            }

            if ($failedReconciliation) {
                $resultType->failedReconciliation = true;
            }

            $existingTypes[$key] = $resultType;
        }

        return $existingTypes;
    }

    /**
     * Reconciles types
     *
     * think of this as a set of functions e.g. empty(T), notEmpty(T), null(T), notNull(T) etc. where
     *  - empty(Object) => null,
     *  - empty(bool) => false,
     *  - notEmpty(Object|null) => Object,
     *  - notEmpty(Object|false) => Object
     *
     * @param   string              $newVarType
     * @param   Type\Union|null     $existingVarType
     * @param   string|null         $key
     * @param   StatementsChecker   $statementsChecker
     * @param   CodeLocation        $codeLocation
     * @param   string[]            $suppressedIssues
     * @param   bool                $failedReconciliation if the types cannot be reconciled, we need to know
     *
     * @return  Type\Union
     */
    public static function reconcileTypes(
        $newVarType,
        $existingVarType,
        $key,
        StatementsChecker $statementsChecker,
        CodeLocation $codeLocation = null,
        array $suppressedIssues = [],
        &$failedReconciliation = false
    ) {
        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;

        $isStrictEquality = false;
        $isLooseEquality = false;
        $isEquality = false;
        $isNegation = false;

        if ($newVarType[0] === '!') {
            $newVarType = substr($newVarType, 1);
            $isNegation = true;
        }

        if ($newVarType[0] === '^') {
            $newVarType = substr($newVarType, 1);
            $isStrictEquality = true;
            $isEquality = true;
        }

        if ($newVarType[0] === '~') {
            $newVarType = substr($newVarType, 1);
            $isLooseEquality = true;
            $isEquality = true;
        }

        if ($existingVarType === null) {
            if (($newVarType === 'isset' && !$isNegation)
                || ($newVarType === 'empty' && $isNegation)
            ) {
                return Type::getMixed(true);
            }

            if ($newVarType === 'array-key-exists') {
                return Type::getMixed(true);
            }

            if (!$isNegation && $newVarType !== 'falsy' && $newVarType !== 'empty') {
                if ($isEquality) {
                    $bracketPos = strpos($newVarType, '(');

                    if ($bracketPos) {
                        $newVarType = substr($newVarType, 0, $bracketPos);
                    }
                }

                return Type::parseString($newVarType);
            }

            return Type::getMixed();
        }

        $oldVarTypeString = $existingVarType->getId();

        if ($isNegation) {
            return self::handleNegatedType(
                $statementsChecker,
                $newVarType,
                $isStrictEquality,
                $isLooseEquality,
                $existingVarType,
                $oldVarTypeString,
                $key,
                $codeLocation,
                $suppressedIssues,
                $failedReconciliation
            );
        }

        if ($newVarType === 'mixed' && $existingVarType->isMixed()) {
            return $existingVarType;
        }

        if ($newVarType === 'isset') {
            $existingVarType->removeType('null');

            if (empty($existingVarType->getTypes())) {
                $failedReconciliation = true;

                // @todo - I think there's a better way to handle this, but for the moment
                // mixed will have to do.
                return Type::getMixed();
            }

            if ($existingVarType->hasType('empty')) {
                $existingVarType->removeType('empty');
                $existingVarType->addType(new TMixed(true));
            }

            $existingVarType->possiblyUndefined = false;
            $existingVarType->possiblyUndefinedFromTry = false;

            return $existingVarType;
        }

        if ($newVarType === 'array-key-exists') {
            $existingVarType->possiblyUndefined = false;

            return $existingVarType;
        }

        $existingVarAtomicTypes = $existingVarType->getTypes();

        if ($newVarType === 'falsy' || $newVarType === 'empty') {
            if ($existingVarType->isMixed()) {
                return new Type\Union([new Type\Atomic\TEmptyMixed]);
            }

            $didRemoveType = $existingVarType->hasDefinitelyNumericType();

            if ($existingVarType->hasType('bool')) {
                $didRemoveType = true;
                $existingVarType->removeType('bool');
                $existingVarType->addType(new TFalse);
            }

            if ($existingVarType->hasType('true')) {
                $didRemoveType = true;
                $existingVarType->removeType('true');
            }

            if ($existingVarType->hasString()) {
                $existingStringTypes = $existingVarType->getLiteralStrings();

                if ($existingStringTypes) {
                    foreach ($existingStringTypes as $key => $literalType) {
                        if ($literalType->value) {
                            $existingVarType->removeType($key);
                            $didRemoveType = true;
                        }
                    }
                } else {
                    $didRemoveType = true;
                    $existingVarType->removeType('string');
                    $existingVarType->addType(new Type\Atomic\TLiteralString(''));
                    $existingVarType->addType(new Type\Atomic\TLiteralString('0'));
                }
            }

            if ($existingVarType->hasInt()) {
                $existingIntTypes = $existingVarType->getLiteralInts();

                if ($existingIntTypes) {
                    foreach ($existingIntTypes as $key => $literalType) {
                        if ($literalType->value) {
                            $existingVarType->removeType($key);
                            $didRemoveType = true;
                        }
                    }
                } else {
                    $didRemoveType = true;
                    $existingVarType->removeType('int');
                    $existingVarType->addType(new Type\Atomic\TLiteralInt(0));
                }
            }

            if (isset($existingVarAtomicTypes['array'])
                && $existingVarAtomicTypes['array']->getId() !== 'array<empty, empty>'
            ) {
                $didRemoveType = true;
                $existingVarType->addType(new TArray(
                    [
                        new Type\Union([new TEmpty]),
                        new Type\Union([new TEmpty]),
                    ]
                ));
            }

            if (isset($existingVarAtomicTypes['scalar'])
                && $existingVarAtomicTypes['scalar']->getId() !== 'empty-scalar'
            ) {
                $didRemoveType = true;
                $existingVarType->addType(new Type\Atomic\TEmptyScalar);
            }

            foreach ($existingVarAtomicTypes as $typeKey => $type) {
                if ($type instanceof TNamedObject
                    || $type instanceof TResource
                    || $type instanceof TCallable
                ) {
                    $didRemoveType = true;

                    $existingVarType->removeType($typeKey);
                }
            }

            if (!$didRemoveType || empty($existingVarType->getTypes())) {
                if ($key && $codeLocation) {
                    self::triggerIssueForImpossible(
                        $existingVarType,
                        $oldVarTypeString,
                        $key,
                        $newVarType,
                        !$didRemoveType,
                        $codeLocation,
                        $suppressedIssues
                    );
                }
            }

            if ($existingVarType->getTypes()) {
                return $existingVarType;
            }

            $failedReconciliation = true;

            return Type::getMixed();
        }

        if ($newVarType === 'object' && !$existingVarType->isMixed()) {
            $objectTypes = [];
            $didRemoveType = false;

            foreach ($existingVarAtomicTypes as $type) {
                if ($type->isObjectType()) {
                    $objectTypes[] = $type;
                } else {
                    $didRemoveType = true;
                }
            }

            if ((!$objectTypes || !$didRemoveType) && !$isEquality) {
                if ($key && $codeLocation) {
                    self::triggerIssueForImpossible(
                        $existingVarType,
                        $oldVarTypeString,
                        $key,
                        $newVarType,
                        !$didRemoveType,
                        $codeLocation,
                        $suppressedIssues
                    );
                }
            }

            if ($objectTypes) {
                return new Type\Union($objectTypes);
            }

            $failedReconciliation = true;

            return Type::getMixed();
        }

        if ($newVarType === 'numeric' && !$existingVarType->isMixed()) {
            $numericTypes = [];
            $didRemoveType = false;

            if ($existingVarType->hasString()) {
                $didRemoveType = true;
                $existingVarType->removeType('string');
                $existingVarType->addType(new TNumericString);
            }

            foreach ($existingVarType->getTypes() as $type) {
                if ($type instanceof TNumeric || $type instanceof TNumericString) {
                    // this is a workaround for a possible issue running
                    // is_numeric($a) && is_string($a)
                    $didRemoveType = true;
                    $numericTypes[] = $type;
                } elseif ($type->isNumericType()) {
                    $numericTypes[] = $type;
                } elseif ($type instanceof TScalar) {
                    $didRemoveType = true;
                    $numericTypes[] = new TNumeric();
                } else {
                    $didRemoveType = true;
                }
            }

            if ((!$didRemoveType || !$numericTypes) && !$isEquality) {
                if ($key && $codeLocation) {
                    self::triggerIssueForImpossible(
                        $existingVarType,
                        $oldVarTypeString,
                        $key,
                        $newVarType,
                        !$didRemoveType,
                        $codeLocation,
                        $suppressedIssues
                    );
                }
            }

            if ($numericTypes) {
                return new Type\Union($numericTypes);
            }

            $failedReconciliation = true;

            return Type::getMixed();
        }

        if ($newVarType === 'scalar' && !$existingVarType->isMixed()) {
            $scalarTypes = [];
            $didRemoveType = false;

            foreach ($existingVarAtomicTypes as $type) {
                if ($type instanceof Scalar) {
                    $scalarTypes[] = $type;
                } else {
                    $didRemoveType = true;
                }
            }

            if ((!$didRemoveType || !$scalarTypes) && !$isEquality) {
                if ($key && $codeLocation) {
                    self::triggerIssueForImpossible(
                        $existingVarType,
                        $oldVarTypeString,
                        $key,
                        $newVarType,
                        !$didRemoveType,
                        $codeLocation,
                        $suppressedIssues
                    );
                }
            }

            if ($scalarTypes) {
                return new Type\Union($scalarTypes);
            }

            $failedReconciliation = true;

            return Type::getMixed();
        }

        if ($newVarType === 'bool' && !$existingVarType->isMixed()) {
            $boolTypes = [];
            $didRemoveType = false;

            foreach ($existingVarAtomicTypes as $type) {
                if ($type instanceof TBool) {
                    $boolTypes[] = $type;
                } else {
                    $didRemoveType = true;
                }
            }

            if ((!$didRemoveType || !$boolTypes) && !$isEquality) {
                if ($key && $codeLocation) {
                    self::triggerIssueForImpossible(
                        $existingVarType,
                        $oldVarTypeString,
                        $key,
                        $newVarType,
                        !$didRemoveType,
                        $codeLocation,
                        $suppressedIssues
                    );
                }
            }

            if ($boolTypes) {
                return new Type\Union($boolTypes);
            }

            $failedReconciliation = true;

            return Type::getMixed();
        }

        if (isset($existingVarAtomicTypes['int'])
            && $existingVarType->fromCalculation
            && ($newVarType === 'int' || $newVarType === 'float')
        ) {
            if ($newVarType === 'int') {
                return Type::getInt();
            }

            return Type::getFloat();
        }

        if (substr($newVarType, 0, 4) === 'isa-') {
            if ($existingVarType->isMixed()) {
                return Type::getMixed();
            }

            $newVarType = substr($newVarType, 4);

            $existingHasObject = $existingVarType->hasObjectType();
            $existingHasString = $existingVarType->hasString();

            if ($existingHasObject && !$existingHasString) {
                $newType = Type::parseString($newVarType);
            } elseif ($existingHasString && !$existingHasObject) {
                $newType = Type::getClassString($newVarType);
            } else {
                $newType = Type::getMixed();
            }
        } elseif (substr($newVarType, 0, 9) === 'getclass-') {
            $newVarType = substr($newVarType, 9);
            $newType = Type::parseString($newVarType);
        } else {
            $bracketPos = strpos($newVarType, '(');

            if ($bracketPos) {
                return self::handleLiteralEquality(
                    $newVarType,
                    $bracketPos,
                    $existingVarType,
                    $oldVarTypeString,
                    $key,
                    $codeLocation,
                    $suppressedIssues
                );
            }

            $newType = Type::parseString($newVarType);
        }

        if ($existingVarType->isMixed()) {
            return $newType;
        }

        $newTypeHasInterface = false;

        if ($newType->hasObjectType()) {
            foreach ($newType->getTypes() as $newTypePart) {
                if ($newTypePart instanceof TNamedObject &&
                    $codebase->interfaceExists($newTypePart->value)
                ) {
                    $newTypeHasInterface = true;
                    break;
                }
            }
        }

        $oldTypeHasInterface = false;

        if ($existingVarType->hasObjectType()) {
            foreach ($existingVarType->getTypes() as $existingTypePart) {
                if ($existingTypePart instanceof TNamedObject &&
                    $codebase->interfaceExists($existingTypePart->value)
                ) {
                    $oldTypeHasInterface = true;
                    break;
                }
            }
        }

        $newTypePart = Atomic::create($newVarType);

        if ($newTypePart instanceof TNamedObject
            && (($newTypeHasInterface
                    && !TypeChecker::isContainedBy(
                        $codebase,
                        $existingVarType,
                        $newType
                    )
                )
                || ($oldTypeHasInterface
                    && !TypeChecker::isContainedBy(
                        $codebase,
                        $newType,
                        $existingVarType
                    )
                ))
        ) {
            $acceptableAtomicTypes = [];

            foreach ($existingVarType->getTypes() as $existingVarTypePart) {
                if (TypeChecker::isAtomicContainedBy(
                    $codebase,
                    $existingVarTypePart,
                    $newTypePart,
                    $scalarTypeMatchFound,
                    $typeCoerced,
                    $typeCoercedFromMixed,
                    $atomicToStringCast
                )) {
                    $acceptableAtomicTypes[] = clone $existingVarTypePart;
                    continue;
                }

                if ($existingVarTypePart instanceof TNamedObject
                    && ($codebase->classExists($existingVarTypePart->value)
                        || $codebase->interfaceExists($existingVarTypePart->value))
                ) {
                    $existingVarTypePart = clone $existingVarTypePart;
                    $existingVarTypePart->addIntersectionType($newTypePart);
                    $acceptableAtomicTypes[] = $existingVarTypePart;
                }
            }

            if ($acceptableAtomicTypes) {
                return new Type\Union($acceptableAtomicTypes);
            }
        } elseif ($codeLocation && !$newType->isMixed()) {
            $hasMatch = true;

            if ($key
                && $newType->getId() === $existingVarType->getId()
                && !$isEquality
            ) {
                self::triggerIssueForImpossible(
                    $existingVarType,
                    $oldVarTypeString,
                    $key,
                    $newVarType,
                    true,
                    $codeLocation,
                    $suppressedIssues
                );
            }

            $anyScalarTypeMatchFound = false;

            $matchingAtomicTypes = [];

            foreach ($newType->getTypes() as $newTypePart) {
                $hasLocalMatch = false;

                foreach ($existingVarType->getTypes() as $existingVarTypePart) {
                    // special workaround because PHP allows floats to contain ints, but we don’t want this
                    // behaviour here
                    if ($existingVarTypePart instanceof Type\Atomic\TFloat
                        && $newTypePart instanceof Type\Atomic\TInt
                    ) {
                        $anyScalarTypeMatchFound = true;
                        continue;
                    }

                    $scalarTypeMatchFound = false;
                    $typeCoerced = false;
                    $typeCoercedFromMixed = false;
                    $atomicToStringCast = false;

                    if (TypeChecker::isAtomicContainedBy(
                        $projectChecker->codebase,
                        $newTypePart,
                        $existingVarTypePart,
                        $scalarTypeMatchFound,
                        $typeCoerced,
                        $typeCoercedFromMixed,
                        $atomicToStringCast
                    ) || $typeCoerced
                    ) {
                        $hasLocalMatch = true;
                        if ($typeCoerced) {
                            $matchingAtomicTypes[] = $existingVarTypePart;
                        }
                        continue;
                    }

                    if ($scalarTypeMatchFound) {
                        $anyScalarTypeMatchFound = true;
                    }

                    if ($newTypePart instanceof TCallable &&
                        (
                            $existingVarTypePart instanceof TString ||
                            $existingVarTypePart instanceof TArray ||
                            $existingVarTypePart instanceof ObjectLike ||
                            (
                                $existingVarTypePart instanceof TNamedObject &&
                                $codebase->classExists($existingVarTypePart->value) &&
                                $codebase->methodExists($existingVarTypePart->value . '::__invoke')
                            )
                        )
                    ) {
                        $hasLocalMatch = true;
                        continue;
                    }
                }

                if (!$hasLocalMatch) {
                    $hasMatch = false;
                    break;
                }
            }

            if ($matchingAtomicTypes) {
                $newType = new Type\Union($matchingAtomicTypes);
            }

            if (!$hasMatch && (!$isLooseEquality || !$anyScalarTypeMatchFound)) {
                if ($newVarType === 'null') {
                    if ($existingVarType->fromDocblock) {
                        if (IssueBuffer::accepts(
                            new DocblockTypeContradiction(
                                'Cannot resolve types for ' . $key . ' - docblock-defined type '
                                    . $existingVarType . ' does not contain null',
                                $codeLocation
                            ),
                            $suppressedIssues
                        )) {
                            // fall through
                        }
                    } else {
                        if (IssueBuffer::accepts(
                            new TypeDoesNotContainNull(
                                'Cannot resolve types for ' . $key . ' - ' . $existingVarType
                                    . ' does not contain null',
                                $codeLocation
                            ),
                            $suppressedIssues
                        )) {
                            // fall through
                        }
                    }
                } elseif ($key !== '$this'
                    || !($statementsChecker->getSource()->getSource() instanceof TraitChecker)
                ) {
                    if ($existingVarType->fromDocblock) {
                        if (IssueBuffer::accepts(
                            new DocblockTypeContradiction(
                                'Cannot resolve types for ' . $key . ' - docblock-defined type '
                                    . $existingVarType->getId() . ' does not contain ' . $newType->getId(),
                                $codeLocation
                            ),
                            $suppressedIssues
                        )) {
                            // fall through
                        }
                    } else {
                        if (IssueBuffer::accepts(
                            new TypeDoesNotContainType(
                                'Cannot resolve types for ' . $key . ' - ' . $existingVarType->getId() .
                                ' does not contain ' . $newType->getId(),
                                $codeLocation
                            ),
                            $suppressedIssues
                        )) {
                            // fall through
                        }
                    }
                }

                $failedReconciliation = true;
            }
        }

        if ($existingVarType->hasType($newVarType)) {
            $atomicType = clone $existingVarType->getTypes()[$newVarType];
            $atomicType->fromDocblock = false;

            return new Type\Union([$atomicType]);
        }

        return $newType;
    }

    /**
     * @param  string     $newVarType
     * @param  bool       $isStrictEquality
     * @param  bool       $isLooseEquality
     * @param  string     $oldVarTypeString
     * @param  string|null $key
     * @param  CodeLocation|null $codeLocation
     * @param  string[]   $suppressedIssues
     * @param  bool       $failedReconciliation
     *
     * @return Type\Union
     */
    private static function handleNegatedType(
        StatementsChecker $statementsChecker,
        $newVarType,
        $isStrictEquality,
        $isLooseEquality,
        Type\Union $existingVarType,
        $oldVarTypeString,
        $key,
        $codeLocation,
        $suppressedIssues,
        &$failedReconciliation
    ) {
        $isEquality = $isStrictEquality || $isLooseEquality;

        // this is a specific value comparison type that cannot be negated
        if ($isEquality && $bracketPos = strpos($newVarType, '(')) {
            return self::handleLiteralNegatedEquality(
                $newVarType,
                $bracketPos,
                $existingVarType,
                $oldVarTypeString,
                $key,
                $codeLocation,
                $suppressedIssues
            );
        }

        if (!$isEquality && ($newVarType === 'isset' || $newVarType === 'array-key-exists')) {
            return Type::getNull();
        }

        $existingVarAtomicTypes = $existingVarType->getTypes();

        if ($newVarType === 'object' && !$existingVarType->isMixed()) {
            $nonObjectTypes = [];
            $didRemoveType = false;

            foreach ($existingVarAtomicTypes as $type) {
                if (!$type->isObjectType()) {
                    $nonObjectTypes[] = $type;
                } else {
                    $didRemoveType = true;
                }
            }

            if ((!$didRemoveType || !$nonObjectTypes)) {
                if ($key && $codeLocation && !$isEquality) {
                    self::triggerIssueForImpossible(
                        $existingVarType,
                        $oldVarTypeString,
                        $key,
                        $newVarType,
                        !$didRemoveType,
                        $codeLocation,
                        $suppressedIssues
                    );
                }
            }

            if ($nonObjectTypes) {
                return new Type\Union($nonObjectTypes);
            }

            $failedReconciliation = true;

            return Type::getMixed();
        }

        if ($newVarType === 'scalar' && !$existingVarType->isMixed()) {
            $nonScalarTypes = [];
            $didRemoveType = false;

            foreach ($existingVarAtomicTypes as $type) {
                if (!($type instanceof Scalar)) {
                    $nonScalarTypes[] = $type;
                } else {
                    $didRemoveType = true;
                }
            }

            if ((!$didRemoveType || !$nonScalarTypes)) {
                if ($key && $codeLocation && !$isEquality) {
                    self::triggerIssueForImpossible(
                        $existingVarType,
                        $oldVarTypeString,
                        $key,
                        $newVarType,
                        !$didRemoveType,
                        $codeLocation,
                        $suppressedIssues
                    );
                }
            }

            if ($nonScalarTypes) {
                return new Type\Union($nonScalarTypes);
            }

            $failedReconciliation = true;

            return Type::getMixed();
        }

        if ($newVarType === 'bool' && !$existingVarType->isMixed()) {
            $nonBoolTypes = [];
            $didRemoveType = false;

            foreach ($existingVarAtomicTypes as $type) {
                if (!$type instanceof TBool
                    || ($isEquality && get_class($type) === TBool::class)
                ) {
                    $nonBoolTypes[] = $type;
                } else {
                    $didRemoveType = true;
                }
            }

            if (!$didRemoveType || !$nonBoolTypes) {
                if ($key && $codeLocation && !$isEquality) {
                    self::triggerIssueForImpossible(
                        $existingVarType,
                        $oldVarTypeString,
                        $key,
                        $newVarType,
                        !$didRemoveType,
                        $codeLocation,
                        $suppressedIssues
                    );
                }
            }

            if ($nonBoolTypes) {
                return new Type\Union($nonBoolTypes);
            }

            $failedReconciliation = true;

            return Type::getMixed();
        }

        if ($newVarType === 'numeric' && !$existingVarType->isMixed()) {
            $nonNumericTypes = [];
            $didRemoveType = $existingVarType->hasString();

            foreach ($existingVarAtomicTypes as $type) {
                if (!$type->isNumericType()) {
                    $nonNumericTypes[] = $type;
                } else {
                    $didRemoveType = true;
                }
            }

            if ((!$nonNumericTypes || !$didRemoveType)) {
                if ($key && $codeLocation && !$isEquality) {
                    self::triggerIssueForImpossible(
                        $existingVarType,
                        $oldVarTypeString,
                        $key,
                        $newVarType,
                        $didRemoveType,
                        $codeLocation,
                        $suppressedIssues
                    );
                }
            }

            if ($nonNumericTypes) {
                return new Type\Union($nonNumericTypes);
            }

            $failedReconciliation = true;

            return Type::getMixed();
        }

        if (($newVarType === 'falsy' || $newVarType === 'empty')) {
            if ($existingVarType->isMixed()) {
                if ($existingVarAtomicTypes['mixed'] instanceof Type\Atomic\TEmptyMixed) {
                    if ($codeLocation
                        && $key
                        && IssueBuffer::accepts(
                            new ParadoxicalCondition(
                                'Found a redundant condition when evaluating ' . $key,
                                $codeLocation
                            ),
                            $suppressedIssues
                        )
                    ) {
                        // fall through
                    }

                    return Type::getMixed();
                }

                return $existingVarType;
            }

            if ($isStrictEquality && $newVarType === 'empty') {
                $existingVarType->removeType('null');
                $existingVarType->removeType('false');

                if ($existingVarType->hasType('array')
                    && $existingVarType->getTypes()['array']->getId() === 'array<empty, empty>'
                ) {
                    $existingVarType->removeType('array');
                }

                $existingVarType->possiblyUndefined = false;
                $existingVarType->possiblyUndefinedFromTry = false;

                if ($existingVarType->getTypes()) {
                    return $existingVarType;
                }

                $failedReconciliation = true;

                return Type::getMixed();
            }

            $didRemoveType = $existingVarType->hasDefinitelyNumericType()
                || $existingVarType->isEmpty()
                || $existingVarType->hasType('bool')
                || $existingVarType->possiblyUndefined
                || $existingVarType->possiblyUndefinedFromTry;

            if ($existingVarType->hasType('null')) {
                $didRemoveType = true;
                $existingVarType->removeType('null');
            }

            if ($existingVarType->hasType('false')) {
                $didRemoveType = true;
                $existingVarType->removeType('false');
            }

            if ($existingVarType->hasType('bool')) {
                $didRemoveType = true;
                $existingVarType->removeType('bool');
                $existingVarType->addType(new TTrue);
            }

            if ($existingVarType->hasString()) {
                $existingStringTypes = $existingVarType->getLiteralStrings();

                if ($existingStringTypes) {
                    foreach ($existingStringTypes as $key => $literalType) {
                        if (!$literalType->value) {
                            $existingVarType->removeType($key);
                            $didRemoveType = true;
                        }
                    }
                } else {
                    $didRemoveType = true;
                }
            }

            if ($existingVarType->hasInt()) {
                $existingIntTypes = $existingVarType->getLiteralInts();

                if ($existingIntTypes) {
                    foreach ($existingIntTypes as $key => $literalType) {
                        if (!$literalType->value) {
                            $existingVarType->removeType($key);
                            $didRemoveType = true;
                        }
                    }
                } else {
                    $didRemoveType = true;
                }
            }

            if ($existingVarType->hasType('array')) {
                $arrayAtomicType = $existingVarType->getTypes()['array'];

                if (($arrayAtomicType instanceof Type\Atomic\TArray && !$arrayAtomicType->count)
                    || ($arrayAtomicType instanceof Type\Atomic\ObjectLike && !$arrayAtomicType->sealed)
                ) {
                    $didRemoveType = true;

                    if ($existingVarType->getTypes()['array']->getId() === 'array<empty, empty>') {
                        $existingVarType->removeType('array');
                    }
                }
            }

            $existingVarType->possiblyUndefined = false;
            $existingVarType->possiblyUndefinedFromTry = false;

            if (!$didRemoveType || empty($existingVarType->getTypes())) {
                if ($key && $codeLocation && !$isEquality) {
                    self::triggerIssueForImpossible(
                        $existingVarType,
                        $oldVarTypeString,
                        $key,
                        '!' . $newVarType,
                        !$didRemoveType,
                        $codeLocation,
                        $suppressedIssues
                    );
                }
            }

            if ($existingVarType->getTypes()) {
                return $existingVarType;
            }

            $failedReconciliation = true;

            return Type::getEmpty();
        }

        if ($newVarType === 'null' && !$existingVarType->isMixed()) {
            $didRemoveType = false;

            if ($existingVarType->hasType('null')) {
                $didRemoveType = true;
                $existingVarType->removeType('null');
            }

            if (!$didRemoveType || empty($existingVarType->getTypes())) {
                if ($key && $codeLocation && !$isEquality) {
                    self::triggerIssueForImpossible(
                        $existingVarType,
                        $oldVarTypeString,
                        $key,
                        $newVarType,
                        !$didRemoveType,
                        $codeLocation,
                        $suppressedIssues
                    );
                }
            }

            if ($existingVarType->getTypes()) {
                return $existingVarType;
            }

            $failedReconciliation = true;

            return Type::getMixed();
        }

        if (isset($existingVarAtomicTypes['int'])
            && $existingVarType->fromCalculation
            && ($newVarType === 'int' || $newVarType === 'float')
        ) {
            $existingVarType->removeType($newVarType);

            if ($newVarType === 'int') {
                $existingVarType->addType(new Type\Atomic\TFloat);
            } else {
                $existingVarType->addType(new Type\Atomic\TInt);
            }

            $existingVarType->fromCalculation = false;

            return $existingVarType;
        }

        if ($newVarType === 'false' && isset($existingVarAtomicTypes['bool'])) {
            $existingVarType->removeType('bool');
            $existingVarType->addType(new TTrue);
        } elseif ($newVarType === 'true' && isset($existingVarAtomicTypes['bool'])) {
            $existingVarType->removeType('bool');
            $existingVarType->addType(new TFalse);
        } elseif (strtolower($newVarType) === 'traversable'
            && isset($existingVarType->getTypes()['iterable'])
        ) {
            $existingVarType->removeType('iterable');
            $existingVarType->addType(new TArray(
                [
                    new Type\Union([new TMixed]),
                    new Type\Union([new TMixed]),
                ]
            ));
        } elseif (strtolower($newVarType) === 'array'
            && isset($existingVarType->getTypes()['iterable'])
        ) {
            $existingVarType->removeType('iterable');
            $existingVarType->addType(new TNamedObject('Traversable'));
        } elseif (substr($newVarType, 0, 9) === 'getclass-') {
            $newVarType = substr($newVarType, 9);
        } elseif (!$isEquality) {
            $newTypePart = new TNamedObject($newVarType);

            $codebase = $statementsChecker->getFileChecker()->projectChecker->codebase;

            // if there wasn't a direct hit, go deeper, eliminating subtypes
            if (!$existingVarType->removeType($newVarType)) {
                foreach ($existingVarType->getTypes() as $partName => $existingVarTypePart) {
                    if (!$existingVarTypePart->isObjectType()) {
                        continue;
                    }

                    if (TypeChecker::isAtomicContainedBy(
                        $codebase,
                        $existingVarTypePart,
                        $newTypePart,
                        $scalarTypeMatchFound,
                        $typeCoerced,
                        $typeCoercedFromMixed,
                        $atomicToStringCast
                    )) {
                        $existingVarType->removeType($partName);
                    }
                }
            }
        }

        if (empty($existingVarType->getTypes())) {
            if ($key !== '$this'
                || !($statementsChecker->getSource()->getSource() instanceof TraitChecker)
            ) {
                if ($key && $codeLocation && !$isEquality) {
                    self::triggerIssueForImpossible(
                        $existingVarType,
                        $oldVarTypeString,
                        $key,
                        $newVarType,
                        true,
                        $codeLocation,
                        $suppressedIssues
                    );
                }
            }

            $failedReconciliation = true;

            return new Type\Union([new Type\Atomic\TEmptyMixed]);
        }

        return $existingVarType;
    }

    /**
     * @param  string     $newVarType
     * @param  int        $bracketPos
     * @param  string     $oldVarTypeString
     * @param  string|null $varId
     * @param  CodeLocation|null $codeLocation
     * @param  string[]   $suppressedIssues
     *
     * @return Type\Union
     */
    private static function handleLiteralEquality(
        $newVarType,
        $bracketPos,
        Type\Union $existingVarType,
        $oldVarTypeString,
        $varId,
        $codeLocation,
        $suppressedIssues
    ) {
        $value = substr($newVarType, $bracketPos + 1, -1);

        $scalarType = substr($newVarType, 0, $bracketPos);

        $existingVarAtomicTypes = $existingVarType->getTypes();

        if ($scalarType === 'int') {
            $value = (int) $value;

            if ($existingVarType->hasInt()) {
                $existingIntTypes = $existingVarType->getLiteralInts();

                if ($existingIntTypes) {
                    $canBeEqual = false;
                    $didRemoveType = false;

                    foreach ($existingVarAtomicTypes as $key => $_) {
                        if ($key !== $newVarType) {
                            $existingVarType->removeType($key);
                            $didRemoveType = true;
                        } else {
                            $canBeEqual = true;
                        }
                    }

                    if ($varId
                        && $codeLocation
                        && (!$canBeEqual || (!$didRemoveType && count($existingVarAtomicTypes) === 1))
                    ) {
                        self::triggerIssueForImpossible(
                            $existingVarType,
                            $oldVarTypeString,
                            $varId,
                            $newVarType,
                            $canBeEqual,
                            $codeLocation,
                            $suppressedIssues
                        );
                    }
                } else {
                    $existingVarType = new Type\Union([new Type\Atomic\TLiteralInt($value)]);
                }
            }
        } elseif ($scalarType === 'string' || $scalarType === 'class-string') {
            if ($existingVarType->hasString()) {
                $existingStringTypes = $existingVarType->getLiteralStrings();

                if ($existingStringTypes) {
                    $canBeEqual = false;
                    $didRemoveType = false;

                    foreach ($existingVarAtomicTypes as $key => $_) {
                        if ($key !== $newVarType) {
                            $existingVarType->removeType($key);
                            $didRemoveType = true;
                        } else {
                            $canBeEqual = true;
                        }
                    }

                    if ($varId
                        && $codeLocation
                        && (!$canBeEqual || (!$didRemoveType && count($existingVarAtomicTypes) === 1))
                    ) {
                        self::triggerIssueForImpossible(
                            $existingVarType,
                            $oldVarTypeString,
                            $varId,
                            $newVarType,
                            $canBeEqual,
                            $codeLocation,
                            $suppressedIssues
                        );
                    }
                } else {
                    if ($scalarType === 'class-string') {
                        $existingVarType = new Type\Union([new Type\Atomic\TLiteralClassString($value)]);
                    } else {
                        $existingVarType = new Type\Union([new Type\Atomic\TLiteralString($value)]);
                    }
                }
            }
        } elseif ($scalarType === 'float') {
            $value = (float) $value;

            if ($existingVarType->hasFloat()) {
                $existingFloatTypes = $existingVarType->getLiteralFloats();

                if ($existingFloatTypes) {
                    $canBeEqual = false;
                    $didRemoveType = false;

                    foreach ($existingVarAtomicTypes as $key => $_) {
                        if ($key !== $newVarType) {
                            $existingVarType->removeType($key);
                            $didRemoveType = true;
                        } else {
                            $canBeEqual = true;
                        }
                    }

                    if ($varId
                        && $codeLocation
                        && (!$canBeEqual || (!$didRemoveType && count($existingVarAtomicTypes) === 1))
                    ) {
                        self::triggerIssueForImpossible(
                            $existingVarType,
                            $oldVarTypeString,
                            $varId,
                            $newVarType,
                            $canBeEqual,
                            $codeLocation,
                            $suppressedIssues
                        );
                    }
                } else {
                    $existingVarType = new Type\Union([new Type\Atomic\TLiteralFloat($value)]);
                }
            }
        }

        return $existingVarType;
    }

    /**
     * @param  string     $newVarType
     * @param  int        $bracketPos
     * @param  string     $oldVarTypeString
     * @param  string|null $key
     * @param  CodeLocation|null $codeLocation
     * @param  string[]   $suppressedIssues
     *
     * @return Type\Union
     */
    private static function handleLiteralNegatedEquality(
        $newVarType,
        $bracketPos,
        Type\Union $existingVarType,
        $oldVarTypeString,
        $key,
        $codeLocation,
        $suppressedIssues
    ) {
        $scalarType = substr($newVarType, 0, $bracketPos);

        $existingVarAtomicTypes = $existingVarType->getTypes();

        $didRemoveType = false;
        $didMatchLiteralType = false;

        if ($scalarType === 'int') {
            if ($existingVarType->hasInt() && $existingIntTypes = $existingVarType->getLiteralInts()) {
                $didMatchLiteralType = true;

                if (isset($existingIntTypes[$newVarType])) {
                    $existingVarType->removeType($newVarType);

                    $didRemoveType = true;
                }
            }
        } elseif ($scalarType === 'string' || $scalarType === 'class-string') {
            if ($existingVarType->hasString() && $existingStringTypes = $existingVarType->getLiteralStrings()) {
                $didMatchLiteralType = true;

                if (isset($existingStringTypes[$newVarType])) {
                    $existingVarType->removeType($newVarType);

                    $didRemoveType = true;
                }
            }
        } elseif ($scalarType === 'float') {
            if ($existingVarType->hasFloat() && $existingFloatTypes = $existingVarType->getLiteralFloats()) {
                $didMatchLiteralType = true;

                if (isset($existingFloatTypes[$newVarType])) {
                    $existingVarType->removeType($newVarType);

                    $didRemoveType = true;
                }
            }
        }

        if ($key
            && $codeLocation
            && $didMatchLiteralType
            && (!$didRemoveType || count($existingVarAtomicTypes) === 1)
        ) {
            self::triggerIssueForImpossible(
                $existingVarType,
                $oldVarTypeString,
                $key,
                $newVarType,
                !$didRemoveType,
                $codeLocation,
                $suppressedIssues
            );
        }

        return $existingVarType;
    }

    /**
     * @param  string       $key
     * @param  string       $oldVarTypeString
     * @param  string       $newVarType
     * @param  bool         $redundant
     * @param  string[]     $suppressedIssues
     *
     * @return void
     */
    private static function triggerIssueForImpossible(
        Union $existingVarType,
        $oldVarTypeString,
        $key,
        $newVarType,
        $redundant,
        CodeLocation $codeLocation,
        array $suppressedIssues
    ) {
        $reconciliation = ' and trying to reconcile type \'' . $oldVarTypeString . '\' to ' . $newVarType;

        $existingVarAtomicTypes = $existingVarType->getTypes();

        $fromDocblock = $existingVarType->fromDocblock
            || (isset($existingVarAtomicTypes[$newVarType])
                && $existingVarAtomicTypes[$newVarType]->fromDocblock);

        if ($redundant) {
            if ($fromDocblock) {
                if (IssueBuffer::accepts(
                    new RedundantConditionGivenDocblockType(
                        'Found a redundant condition when evaluating docblock-defined type '
                            . $key . $reconciliation,
                        $codeLocation
                    ),
                    $suppressedIssues
                )) {
                    // fall through
                }
            } else {
                if (IssueBuffer::accepts(
                    new RedundantCondition(
                        'Found a redundant condition when evaluating ' . $key . $reconciliation,
                        $codeLocation
                    ),
                    $suppressedIssues
                )) {
                    // fall through
                }
            }
        } else {
            if ($fromDocblock) {
                if (IssueBuffer::accepts(
                    new DocblockTypeContradiction(
                        'Found a contradiction with a docblock-defined type '
                            . 'when evaluating ' . $key . $reconciliation,
                        $codeLocation
                    ),
                    $suppressedIssues
                )) {
                    // fall through
                }
            } else {
                if (IssueBuffer::accepts(
                    new TypeDoesNotContainType(
                        'Found a contradiction when evaluating ' . $key . $reconciliation,
                        $codeLocation
                    ),
                    $suppressedIssues
                )) {
                    // fall through
                }
            }
        }
    }

    /**
     * @param  string[]                  $keyParts
     * @param  array<string,Type\Union>  $existingTypes
     * @param  array<string>             $changedVarIds
     *
     * @return void
     */
    private static function adjustObjectLikeType(
        array $keyParts,
        array &$existingTypes,
        array &$changedVarIds,
        Type\Union $resultType
    ) {
        array_pop($keyParts);
        $arrayKey = array_pop($keyParts);
        array_pop($keyParts);

        if ($arrayKey[0] === '$') {
            return;
        }

        $arrayKeyOffset = substr($arrayKey, 1, -1);

        $baseKey = implode($keyParts);

        if (isset($existingTypes[$baseKey])) {
            $baseAtomicTypes = $existingTypes[$baseKey]->getTypes();

            if (isset($baseAtomicTypes['array'])) {
                if ($baseAtomicTypes['array'] instanceof Type\Atomic\ObjectLike) {
                    $baseAtomicTypes['array']->properties[$arrayKeyOffset] = clone $resultType;
                    $changedVarIds[] = $baseKey . '[' . $arrayKey . ']';

                    if ($keyParts[count($keyParts) - 1] === ']') {
                        self::adjustObjectLikeType(
                            $keyParts,
                            $existingTypes,
                            $changedVarIds,
                            $existingTypes[$baseKey]
                        );
                    }

                    $existingTypes[$baseKey]->bustCache();
                }
            }
        }
    }

    /**
     * @param  string $path
     *
     * @return array<int, string>
     */
    public static function breakUpPathIntoParts($path)
    {
        if (isset(self::$brokenPaths[$path])) {
            return self::$brokenPaths[$path];
        }

        $chars = str_split($path);

        $stringChar = null;
        $escapeChar = false;

        $parts = [''];
        $partsOffset = 0;

        for ($i = 0, $charCount = count($chars); $i < $charCount; ++$i) {
            $char = $chars[$i];

            if ($stringChar) {
                if ($char === $stringChar && !$escapeChar) {
                    $stringChar = null;
                }

                if ($char === '\\') {
                    $escapeChar = !$escapeChar;
                }

                $parts[$partsOffset] .= $char;
                continue;
            }

            switch ($char) {
                case '[':
                case ']':
                    $partsOffset++;
                    $parts[$partsOffset] = $char;
                    $partsOffset++;
                    continue 2;

                case '\'':
                case '"':
                    if (!isset($parts[$partsOffset])) {
                        $parts[$partsOffset] = '';
                    }
                    $parts[$partsOffset] .= $char;
                    $stringChar = $char;

                    continue 2;

                case '-':
                    if ($i < $charCount - 1 && $chars[$i + 1] === '>') {
                        ++$i;

                        $partsOffset++;
                        $parts[$partsOffset] = '->';
                        $partsOffset++;
                        continue 2;
                    }
                    // fall through

                default:
                    if (!isset($parts[$partsOffset])) {
                        $parts[$partsOffset] = '';
                    }
                    $parts[$partsOffset] .= $char;
            }
        }

        self::$brokenPaths[$path] = $parts;

        return $parts;
    }

    /**
     * Gets the type for a given (non-existent key) based on the passed keys
     *
     * @param  ProjectChecker            $projectChecker
     * @param  string                    $key
     * @param  array<string,Type\Union>  $existingKeys
     *
     * @return Type\Union|null
     */
    private static function getValueForKey(ProjectChecker $projectChecker, $key, array &$existingKeys)
    {
        $keyParts = self::breakUpPathIntoParts($key);

        if (count($keyParts) === 1) {
            return isset($existingKeys[$keyParts[0]]) ? clone $existingKeys[$keyParts[0]] : null;
        }

        $baseKey = array_shift($keyParts);

        if (!isset($existingKeys[$baseKey])) {
            return null;
        }

        $codebase = $projectChecker->codebase;

        while ($keyParts) {
            $divider = array_shift($keyParts);

            if ($divider === '[') {
                $arrayKey = array_shift($keyParts);
                array_shift($keyParts);

                $newBaseKey = $baseKey . '[' . $arrayKey . ']';

                if (!isset($existingKeys[$newBaseKey])) {
                    $newBaseType = null;

                    foreach ($existingKeys[$baseKey]->getTypes() as $existingKeyTypePart) {
                        if ($existingKeyTypePart instanceof Type\Atomic\TArray) {
                            $newBaseTypeCandidate = clone $existingKeyTypePart->typeParams[1];
                        } elseif (!$existingKeyTypePart instanceof Type\Atomic\ObjectLike) {
                            return Type::getMixed();
                        } elseif ($arrayKey[0] === '$') {
                            $newBaseTypeCandidate = $existingKeyTypePart->getGenericValueType();
                        } else {
                            $arrayProperties = $existingKeyTypePart->properties;

                            $keyPartsKey = str_replace('\'', '', $arrayKey);

                            if (!isset($arrayProperties[$keyPartsKey])) {
                                return null;
                            }

                            $newBaseTypeCandidate = clone $arrayProperties[$keyPartsKey];
                        }

                        if (!$newBaseType) {
                            $newBaseType = $newBaseTypeCandidate;
                        } else {
                            $newBaseType = Type::combineUnionTypes(
                                $newBaseType,
                                $newBaseTypeCandidate
                            );
                        }

                        $existingKeys[$newBaseKey] = $newBaseType;
                    }
                }

                $baseKey = $newBaseKey;
            } elseif ($divider === '->') {
                $propertyName = array_shift($keyParts);
                $newBaseKey = $baseKey . '->' . $propertyName;

                if (!isset($existingKeys[$newBaseKey])) {
                    $newBaseType = null;

                    foreach ($existingKeys[$baseKey]->getTypes() as $existingKeyTypePart) {
                        if ($existingKeyTypePart instanceof TNull) {
                            $classPropertyType = Type::getNull();
                        } elseif ($existingKeyTypePart instanceof TMixed
                            || $existingKeyTypePart instanceof TGenericParam
                            || $existingKeyTypePart instanceof TObject
                            || ($existingKeyTypePart instanceof TNamedObject
                                && strtolower($existingKeyTypePart->value) === 'stdclass')
                        ) {
                            $classPropertyType = Type::getMixed();
                        } elseif ($existingKeyTypePart instanceof TNamedObject) {
                            if (!$codebase->classOrInterfaceExists($existingKeyTypePart->value)) {
                                continue;
                            }

                            $propertyId = $existingKeyTypePart->value . '::$' . $propertyName;

                            if (!$codebase->properties->propertyExists($propertyId)) {
                                return null;
                            }

                            $declaringPropertyClass = $codebase->properties->getDeclaringClassForProperty(
                                $propertyId
                            );

                            $classStorage = $projectChecker->classlikeStorageProvider->get(
                                (string)$declaringPropertyClass
                            );

                            $classPropertyType = $classStorage->properties[$propertyName]->type;

                            $classPropertyType = $classPropertyType ? clone $classPropertyType : Type::getMixed();
                        } else {
                            // @todo handle this
                            continue;
                        }

                        if ($newBaseType instanceof Type\Union) {
                            $newBaseType = Type::combineUnionTypes($newBaseType, $classPropertyType);
                        } else {
                            $newBaseType = $classPropertyType;
                        }

                        $existingKeys[$newBaseKey] = $newBaseType;
                    }
                }

                $baseKey = $newBaseKey;
            } else {
                throw new \InvalidArgumentException('Unexpected divider ' . $divider);
            }
        }

        return $existingKeys[$baseKey];
    }
}
