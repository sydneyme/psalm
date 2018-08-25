<?php
namespace Psalm;

use PhpParser;
use Psalm\Checker\StatementsChecker;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Type\Reconciler;
use Psalm\Type\Union;

class Context
{
    /**
     * @var array<string, Type\Union>
     */
    public $varsInScope = [];

    /**
     * @var array<string, bool>
     */
    public $varsPossiblyInScope = [];

    /**
     * Whether or not we're inside the conditional of an if/where etc.
     *
     * This changes whether or not the context is cloned
     *
     * @var bool
     */
    public $insideConditional = false;

    /**
     * Whether or not we're inside a __construct function
     *
     * @var bool
     */
    public $insideConstructor = false;

    /**
     * Whether or not we're inside an isset call
     *
     * Inside isssets Psalm is more lenient about certain things
     *
     * @var bool
     */
    public $insideIsset = false;

    /**
     * Whether or not we're inside an unset call, where
     * we don't care about possibly undefined variables
     *
     * @var bool
     */
    public $insideUnset = false;

    /**
     * Whether or not we're inside an class_exists call, where
     * we don't care about possibly undefined classes
     *
     * @var bool
     */
    public $insideClassExists = false;

    /**
     * @var null|CodeLocation
     */
    public $includeLocation = null;

    /**
     * @var string|null
     */
    public $self;

    /**
     * @var string|null
     */
    public $parent;

    /**
     * @var bool
     */
    public $checkClasses = true;

    /**
     * @var bool
     */
    public $checkVariables = true;

    /**
     * @var bool
     */
    public $checkMethods = true;

    /**
     * @var bool
     */
    public $checkConsts = true;

    /**
     * @var bool
     */
    public $checkFunctions = true;

    /**
     * A list of classes checked with class_exists
     *
     * @var array<string,bool>
     */
    public $phantomClasses = [];

    /**
     * A list of files checked with file_exists
     *
     * @var array<string,bool>
     */
    public $phantomFiles = [];

    /**
     * A list of clauses in Conjunctive Normal Form
     *
     * @var array<int, Clause>
     */
    public $clauses = [];

    /**
     * Whether or not to do a deep analysis and collect mutations to this context
     *
     * @var bool
     */
    public $collectMutations = false;

    /**
     * Whether or not to do a deep analysis and collect initializations from private methods
     *
     * @var bool
     */
    public $collectInitializations = false;

    /**
     * Stored to prevent re-analysing methods when checking for initialised properties
     *
     * @var array<string, bool>|null
     */
    public $initializedMethods = null;

    /**
     * @var array<string, Type\Union>
     */
    public $constants = [];

    /**
     * Whether or not to track how many times a variable is used
     *
     * @var bool
     */
    public $collectReferences = false;

    /**
     * Whether or not to track exceptions
     *
     * @var bool
     */
    public $collectExceptions = false;

    /**
     * A list of variables that have been referenced
     *
     * @var array<string, bool>
     */
    public $referencedVarIds = [];

    /**
     * A list of variables that have never been referenced
     *
     * @var array<string, array<string, CodeLocation>>
     */
    public $unreferencedVars = [];

    /**
     * A list of variables that have been passed by reference (where we know their type)
     *
     * @var array<string, \Psalm\ReferenceConstraint>|null
     */
    public $byrefConstraints;

    /**
     * If this context inherits from a context, it is here
     *
     * @var Context|null
     */
    public $parentContext;

    /**
     * @var array<string, Type\Union>
     */
    public $possibleParamTypes = [];

    /**
     * A list of vars that have been assigned to
     *
     * @var array<string, bool>
     */
    public $assignedVarIds = [];

    /**
     * A list of vars that have been may have been assigned to
     *
     * @var array<string, bool>
     */
    public $possiblyAssignedVarIds = [];

    /**
     * A list of classes or interfaces that may have been thrown
     *
     * @var array<string, bool>
     */
    public $possiblyThrownExceptions = [];

    /**
     * @var bool
     */
    public $isGlobal = false;

    /**
     * @var array<string, bool>
     */
    public $protectedVarIds = [];

    /**
     * If we've branched from the main scope, a byte offset for where that branch happened
     *
     * @var int|null
     */
    public $branchPoint;

    /**
     * If we're inside case statements we allow continue; statements as an alias of break;
     *
     * @var bool
     */
    public $insideCase = false;

    /**
     * @var bool
     */
    public $insideLoop = false;

    /**
     * @var Scope\LoopScope|null
     */
    public $loopScope = null;

    /**
     * @var Scope\SwitchScope|null
     */
    public $switchScope = null;

    /**
     * @param string|null $self
     */
    public function __construct($self = null)
    {
        $this->self = $self;
    }

    /**
     * @return void
     */
    public function __clone()
    {
        foreach ($this->varsInScope as &$type) {
            $type = clone $type;
        }

        foreach ($this->clauses as &$clause) {
            $clause = clone $clause;
        }

        foreach ($this->constants as &$constant) {
            $constant = clone $constant;
        }
    }

    /**
     * Updates the parent context, looking at the changes within a block and then applying those changes, where
     * necessary, to the parent context
     *
     * @param  Context     $startContext
     * @param  Context     $endContext
     * @param  bool        $hasLeavingStatements   whether or not the parent scope is abandoned between
     *                                               $startContext and $endContext
     * @param  array       $varsToUpdate
     * @param  array       $updatedVars
     *
     * @return void
     */
    public function update(
        Context $startContext,
        Context $endContext,
        $hasLeavingStatements,
        array $varsToUpdate,
        array &$updatedVars
    ) {
        foreach ($startContext->varsInScope as $varId => $oldType) {
            // this is only true if there was some sort of type negation
            if (in_array($varId, $varsToUpdate, true)) {
                // if we're leaving, we're effectively deleting the possibility of the if types
                $newType = !$hasLeavingStatements && $endContext->hasVariable($varId)
                    ? $endContext->varsInScope[$varId]
                    : null;

                $existingType = isset($this->varsInScope[$varId]) ? $this->varsInScope[$varId] : null;

                if (!$existingType) {
                    if ($newType) {
                        $this->varsInScope[$varId] = clone $newType;
                        $updatedVars[$varId] = true;
                    }

                    continue;
                }

                // if the type changed within the block of statements, process the replacement
                // also never allow ourselves to remove all types from a union
                if ((!$newType || !$oldType->equals($newType))
                    && ($newType || count($existingType->getTypes()) > 1)
                ) {
                    $existingType->substitute($oldType, $newType);

                    if ($newType && $newType->fromDocblock) {
                        $existingType->setFromDocblock();
                    }

                    $updatedVars[$varId] = true;
                }
            }
        }
    }

    /**
     * @param  array<string, Type\Union> $newVarsInScope
     * @param  bool $includeNewVars
     *
     * @return array<string,Type\Union>
     */
    public function getRedefinedVars(array $newVarsInScope, $includeNewVars = false)
    {
        $redefinedVars = [];

        foreach ($this->varsInScope as $varId => $thisType) {
            if (!isset($newVarsInScope[$varId])) {
                if ($includeNewVars) {
                    $redefinedVars[$varId] = $thisType;
                }
                continue;
            }

            $newType = $newVarsInScope[$varId];

            if (!$thisType->failedReconciliation
                && !$thisType->isEmpty()
                && !$newType->isEmpty()
                && !$thisType->equals($newType)
            ) {
                $redefinedVars[$varId] = $thisType;
            }
        }

        return $redefinedVars;
    }

    /**
     * @return void
     */
    public function inferType(
        PhpParser\Node\Expr $expr,
        FunctionLikeStorage $functionStorage,
        Type\Union $inferredType
    ) {
        if (!isset($expr->inferredType)) {
            return;
        }

        $exprType = $expr->inferredType;

        if (($exprType->isMixed() || $exprType->getId() === $inferredType->getId())
            && $expr instanceof PhpParser\Node\Expr\Variable
            && is_string($expr->name)
            && !isset($this->assignedVarIds['$' . $expr->name])
            && array_key_exists($expr->name, $functionStorage->paramTypes)
            && !$functionStorage->paramTypes[$expr->name]
        ) {
            if (isset($this->possibleParamTypes[$expr->name])) {
                $this->possibleParamTypes[$expr->name] = Type::combineUnionTypes(
                    $this->possibleParamTypes[$expr->name],
                    $inferredType
                );
            } else {
                $this->possibleParamTypes[$expr->name] = $inferredType;
                $this->varsInScope['$' . $expr->name] = clone $inferredType;
            }
        }
    }

    /**
     * @param  Context $originalContext
     * @param  Context $newContext
     *
     * @return array<int, string>
     */
    public static function getNewOrUpdatedVarIds(Context $originalContext, Context $newContext)
    {
        $redefinedVarIds = [];

        foreach ($newContext->varsInScope as $varId => $contextType) {
            if (!isset($originalContext->varsInScope[$varId])
                || !$originalContext->varsInScope[$varId]->equals($contextType)
            ) {
                $redefinedVarIds[] = $varId;
            }
        }

        return $redefinedVarIds;
    }

    /**
     * @param  string $removeVarId
     *
     * @return void
     */
    public function remove($removeVarId)
    {
        unset(
            $this->referencedVarIds[$removeVarId],
            $this->varsPossiblyInScope[$removeVarId]
        );

        if (isset($this->varsInScope[$removeVarId])) {
            $existingType = $this->varsInScope[$removeVarId];
            unset($this->varsInScope[$removeVarId]);

            $this->removeDescendents($removeVarId, $existingType);
        }
    }

    /**
     * @param  string[]             $changedVarIds
     *
     * @return void
     */
    public function removeReconciledClauses(array $changedVarIds)
    {
        $this->clauses = array_filter(
            $this->clauses,
            /** @return bool */
            function (Clause $c) use ($changedVarIds) {
                return count($c->possibilities) > 1
                    || $c->wedge
                    || !in_array(array_keys($c->possibilities)[0], $changedVarIds, true);
            }
        );
    }

    /**
     * @param  string                 $removeVarId
     * @param  Clause[]               $clauses
     * @param  Union|null             $newType
     * @param  StatementsChecker|null $statementsChecker
     *
     * @return array<int, Clause>
     */
    public static function filterClauses(
        $removeVarId,
        array $clauses,
        Union $newType = null,
        StatementsChecker $statementsChecker = null
    ) {
        $newTypeString = $newType ? $newType->getId() : '';

        $clausesToKeep = [];

        foreach ($clauses as $clause) {
            \Psalm\Type\Algebra::calculateNegation($clause);

            $quotedRemoveVarId = preg_quote($removeVarId, '/');

            foreach ($clause->possibilities as $varId => $_) {
                if (preg_match('/' . $quotedRemoveVarId . '[\]\[\-]/', $varId)) {
                    break 2;
                }
            }

            if (!isset($clause->possibilities[$removeVarId]) ||
                $clause->possibilities[$removeVarId] === [$newTypeString]
            ) {
                $clausesToKeep[] = $clause;
            } elseif ($statementsChecker &&
                $newType &&
                !$newType->isMixed()
            ) {
                $typeChanged = false;

                // if the clause contains any possibilities that would be altered
                // by the new type
                foreach ($clause->possibilities[$removeVarId] as $type) {
                    // empty and !empty are not definitive for arrays and scalar types
                    if (($type === '!falsy' || $type === 'falsy') &&
                        ($newType->hasArray() || $newType->hasPossiblyNumericType())
                    ) {
                        $typeChanged = true;
                        break;
                    }

                    $resultType = Reconciler::reconcileTypes(
                        $type,
                        clone $newType,
                        null,
                        $statementsChecker,
                        null,
                        [],
                        $failedReconciliation
                    );

                    if ($resultType->getId() !== $newTypeString) {
                        $typeChanged = true;
                        break;
                    }
                }

                if (!$typeChanged) {
                    $clausesToKeep[] = $clause;
                }
            }
        }

        return $clausesToKeep;
    }

    /**
     * @param  string               $removeVarId
     * @param  Union|null           $newType
     * @param  null|StatementsChecker   $statementsChecker
     *
     * @return void
     */
    public function removeVarFromConflictingClauses(
        $removeVarId,
        Union $newType = null,
        StatementsChecker $statementsChecker = null
    ) {
        $this->clauses = self::filterClauses($removeVarId, $this->clauses, $newType, $statementsChecker);

        if ($this->parentContext) {
            $this->parentContext->removeVarFromConflictingClauses($removeVarId);
        }
    }

    /**
     * @param  string                 $removeVarId
     * @param  \Psalm\Type\Union|null $existingType
     * @param  \Psalm\Type\Union|null $newType
     * @param  null|StatementsChecker     $statementsChecker
     *
     * @return void
     */
    public function removeDescendents(
        $removeVarId,
        Union $existingType = null,
        Union $newType = null,
        StatementsChecker $statementsChecker = null
    ) {
        if (!$existingType && isset($this->varsInScope[$removeVarId])) {
            $existingType = $this->varsInScope[$removeVarId];
        }

        if (!$existingType) {
            return;
        }

        if ($this->clauses) {
            $this->removeVarFromConflictingClauses(
                $removeVarId,
                $existingType->isMixed()
                    || ($newType && $existingType->fromDocblock !== $newType->fromDocblock)
                    ? null
                    : $newType,
                $statementsChecker
            );
        }

        $varsToRemove = [];

        foreach ($this->varsInScope as $varId => $_) {
            if (preg_match('/' . preg_quote($removeVarId, '/') . '[\]\[\-]/', $varId)) {
                $varsToRemove[] = $varId;
            }
        }

        foreach ($varsToRemove as $varId) {
            unset($this->varsInScope[$varId]);
        }
    }

    /**
     * @return void
     */
    public function removeAllObjectVars()
    {
        $varsToRemove = [];

        foreach ($this->varsInScope as $varId => $_) {
            if (strpos($varId, '->') !== false || strpos($varId, '::') !== false) {
                $varsToRemove[] = $varId;
            }
        }

        if (!$varsToRemove) {
            return;
        }

        foreach ($varsToRemove as $varId) {
            unset($this->varsInScope[$varId], $this->varsPossiblyInScope[$varId]);
        }

        $clausesToKeep = [];

        foreach ($this->clauses as $clause) {
            $abandonClause = false;

            foreach (array_keys($clause->possibilities) as $key) {
                if (strpos($key, '->') !== false || strpos($key, '::') !== false) {
                    $abandonClause = true;
                    break;
                }
            }

            if (!$abandonClause) {
                $clausesToKeep[] = $clause;
            }
        }

        $this->clauses = $clausesToKeep;
    }

    /**
     * @param   Context $opContext
     *
     * @return  void
     */
    public function updateChecks(Context $opContext)
    {
        $this->checkClasses = $this->checkClasses && $opContext->checkClasses;
        $this->checkVariables = $this->checkVariables && $opContext->checkVariables;
        $this->checkMethods = $this->checkMethods && $opContext->checkMethods;
        $this->checkFunctions = $this->checkFunctions && $opContext->checkFunctions;
        $this->checkConsts = $this->checkConsts && $opContext->checkConsts;
    }

    /**
     * @param   string $className
     *
     * @return  bool
     */
    public function isPhantomClass($className)
    {
        return isset($this->phantomClasses[strtolower($className)]);
    }

    /**
     * @param  string|null  $varName
     *
     * @return bool
     */
    public function hasVariable($varName, StatementsChecker $statementsChecker = null)
    {
        if (!$varName ||
            (!isset($this->varsPossiblyInScope[$varName]) &&
                !isset($this->varsInScope[$varName]))
        ) {
            return false;
        }

        $strippedVar = preg_replace('/(->|\[).*$/', '', $varName);

        if ($strippedVar[0] === '$' && ($strippedVar !== '$this' || $varName !== $strippedVar)) {
            $this->referencedVarIds[$varName] = true;

            if ($this->collectReferences && $statementsChecker) {
                if (isset($this->unreferencedVars[$varName])) {
                    $statementsChecker->registerVariableUses($this->unreferencedVars[$varName]);
                }

                unset($this->unreferencedVars[$varName]);
            }
        }

        return isset($this->varsInScope[$varName]);
    }
}
