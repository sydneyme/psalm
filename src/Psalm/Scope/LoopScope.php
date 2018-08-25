<?php
namespace Psalm\Scope;

use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Type;

class LoopScope
{
    /**
     * @var Context
     */
    public $loopContext;

    /**
     * @var Context
     */
    public $loopParentContext;

    /**
     * @var array<string, Type\Union>|null
     */
    public $redefinedLoopVars = [];

    /**
     * @var array<string, Type\Union>
     */
    public $possiblyRedefinedLoopVars = [];

    /**
     * @var array<string, Type\Union>|null
     */
    public $possiblyRedefinedLoopParentVars = null;

    /**
     * @var array<string, bool>
     */
    public $varsPossiblyInScope = [];

    /**
     * @var array<string, bool>
     */
    public $protectedVarIds = [];

    /**
     * @var array<string, array<string, CodeLocation>>
     */
    public $unreferencedVars = [];

    /**
     * @var array<string, array<string, CodeLocation>>
     */
    public $possiblyUnreferencedVars = [];

    /**
     * @var string[]
     */
    public $finalActions = [];

    public function __construct(Context $loopContext, Context $parentContext)
    {
        $this->loopContext = $loopContext;
        $this->loopParentContext = $parentContext;
    }
}
