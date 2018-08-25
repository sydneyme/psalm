<?php
namespace Psalm\Scanner;

use Psalm\Type;

class VarDocblockComment
{
    /**
     * @var Type\Union
     */
    public $type;

    /**
     * @var string
     */
    public $originalType;

    /**
     * @var string|null
     */
    public $varId = null;

    /**
     * @var int|null
     */
    public $lineNumber;

    /**
     * Whether or not the function is deprecated
     *
     * @var bool
     */
    public $deprecated = false;
}
