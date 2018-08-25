<?php
namespace Psalm\Scanner;

class ClassLikeDocblockComment
{
    /**
     * Whether or not the class is deprecated
     *
     * @var bool
     */
    public $deprecated = false;

    /**
     * @var array<int, array<int, string>>
     */
    public $templateTypeNames = [];

    /**
     * @var array<int, string>
     */
    public $templateParents = [];

    /**
     * @var array<int, array{name:string, type:string, tag:string, line_number:int}>
     */
    public $properties = [];

    /**
     * @var array<int, \PhpParser\Node\Stmt\ClassMethod>
     */
    public $methods = [];

    /**
     * @var bool
     */
    public $sealedProperties = false;

    /**
     * @var bool
     */
    public $sealedMethods = false;

    /**
     * @var array<int, string>
     */
    public $suppressedIssues = [];
}
