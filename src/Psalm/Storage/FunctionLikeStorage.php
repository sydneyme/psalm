<?php
namespace Psalm\Storage;

use Psalm\CodeLocation;
use Psalm\Type;

class FunctionLikeStorage
{
    /**
     * @var CodeLocation|null
     */
    public $location;

    /**
     * @var array<int, FunctionLikeParameter>
     */
    public $params = [];

    /**
     * @var array<string, Type\Union|null>
     */
    public $paramTypes = [];

    /**
     * @var Type\Union|null
     */
    public $returnType;

    /**
     * @var CodeLocation|null
     */
    public $returnTypeLocation;

    /**
     * @var Type\Union|null
     */
    public $signatureReturnType;

    /**
     * @var CodeLocation|null
     */
    public $signatureReturnTypeLocation;

    /**
     * @var string
     */
    public $casedName;

    /**
     * @var array<int, string>
     */
    public $suppressedIssues = [];

    /**
     * @var bool
     */
    public $deprecated;

    /**
     * @var bool
     */
    public $variadic;

    /**
     * @var bool
     */
    public $returnsByRef = false;

    /**
     * @var int
     */
    public $requiredParamCount;

    /**
     * @var array<string, Type\Union>
     */
    public $definedConstants = [];

    /**
     * @var array<string, bool>
     */
    public $globalVariables = [];

    /**
     * @var array<string, Type\Union>
     */
    public $globalTypes = [];

    /**
     * @var array<string, Type\Union>|null
     */
    public $templateTypes;

    /**
     * @var array<int, string>|null
     */
    public $templateTypeofParams;

    /**
     * @var bool
     */
    public $hasTemplateReturnType;

    /**
     * @var array<string, array<int, CodeLocation>>|null
     */
    public $referencingLocations;

    /**
     * @var array<int, Assertion>
     */
    public $assertions = [];

    /**
     * @var array<int, Assertion>
     */
    public $ifTrueAssertions = [];

    /**
     * @var array<int, Assertion>
     */
    public $ifFalseAssertions = [];

    /**
     * @var bool
     */
    public $hasVisitorIssues = false;

    /**
     * @var array<string, bool>
     */
    public $throws = [];

    /**
     * @var bool
     */
    public $hasYield = false;
}
