<?php
namespace Psalm\Scope;

use Psalm\CodeLocation;
use Psalm\Clause;
use Psalm\Type;

class IfScope
{
    /**
     * @var array<string, Type\Union>|null
     */
    public $newVars = null;

    /**
     * @var array<string, bool>
     */
    public $newVarsPossiblyInScope = [];

    /**
     * @var array<string, Type\Union>|null
     */
    public $redefinedVars = null;

    /**
     * @var array<string, bool>|null
     */
    public $assignedVarIds = null;

    /**
     * @var array<string, bool>
     */
    public $possiblyAssignedVarIds = [];

    /**
     * @var array<string, Type\Union>
     */
    public $possiblyRedefinedVars = [];

    /**
     * @var array<string, bool>
     */
    public $updatedVars = [];

    /**
     * @var array<string, array<int, array<int, string>>>
     */
    public $negatedTypes = [];

    /**
     * @var array<mixed, string>
     */
    public $ifCondChangedVarIds = [];

    /**
     * @var array<string, string>|null
     */
    public $negatableIfTypes = null;

    /**
     * @var array<int, Clause>
     */
    public $negatedClauses = [];

    /**
     * These are the set of clauses that could be applied after the `if`
     * statement, if the `if` statement contains branches with leaving statments,
     * and the else leaves too
     *
     * @var array<int, Clause>
     */
    public $reasonableClauses = [];

    /**
     * Variables that were mixed, but are no longer
     *
     * @var array<string, Type\Union>|null
     */
    public $possibleParamTypes = null;

    /**
     * @var string[]
     */
    public $finalActions = [];

    /**
     * @var array<string, array<string, CodeLocation>>
     */
    public $newUnreferencedVars = [];
}
