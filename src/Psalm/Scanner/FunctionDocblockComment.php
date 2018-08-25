<?php
namespace Psalm\Scanner;

class FunctionDocblockComment
{
    /**
     * @var string|null
     */
    public $returnType = null;

    /**
     * @var array<int, array{name:string, type:string, line_number: int}>
     */
    public $params = [];

    /**
     * @var array<int, array{name:string, type:string, line_number: int}>
     */
    public $globals = [];

    /**
     * Whether or not the function is deprecated
     *
     * @var bool
     */
    public $deprecated = false;

    /**
     * Whether or not the function uses get_args
     *
     * @var bool
     */
    public $variadic = false;

    /**
     * Whether or not to ignore the nullability of this function's return type
     *
     * @var bool
     */
    public $ignoreNullableReturn = false;

    /**
     * Whether or not to ignore the nullability of this function's return type
     *
     * @var bool
     */
    public $ignoreFalsableReturn = false;

    /**
     * @var array<int, string>
     */
    public $suppress = [];

    /**
     * @var array<int, string>
     */
    public $throws = [];

    /** @var int */
    public $returnTypeLineNumber;

    /**
     * @var array<int, array<int, string>>
     */
    public $templateTypeNames = [];

    /**
     * @var array<int, array{template_type: string, param_name: string, line_number?: int}>
     */
    public $templateTypeofs = [];

    /**
     * @var array<int, array{type: string, param_name: string}>
     */
    public $assertions = [];

    /**
     * @var array<int, array{type: string, param_name: string}>
     */
    public $ifTrueAssertions = [];

    /**
     * @var array<int, array{type: string, param_name: string}>
     */
    public $ifFalseAssertions = [];
}
