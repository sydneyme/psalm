<?php
namespace Psalm\Storage;

class FileStorage
{
    /**
     * @var array<string, string>
     */
    public $classlikesInFile = [];

    /**
     * @var array<string>
     */
    public $referencedClasslikes = [];

    /**
     * @var array<string>
     */
    public $requiredClasses = [];

    /**
     * @var array<string>
     */
    public $requiredInterfaces = [];

    /**
     * @var bool
     */
    public $hasTrait = false;

    /** @var string */
    public $filePath;

    /**
     * @var array<string, FunctionLikeStorage>
     */
    public $functions = [];

    /** @var array<string, string> */
    public $declaringFunctionIds = [];

    /**
     * @var array<string, \Psalm\Type\Union>
     */
    public $constants = [];

    /** @var array<string, string> */
    public $declaringConstants = [];

    /** @var array<string, string> */
    public $requiredFilePaths = [];

    /** @var array<string, string> */
    public $requiredByFilePaths = [];

    /** @var bool */
    public $populated = false;

    /** @var bool */
    public $deepScan = false;

    /** @var bool */
    public $hasExtraStatements = false;

    /**
     * @var string
     */
    public $hash = '';

    /**
     * @var bool
     */
    public $hasVisitorIssues = false;

    /**
     * @param string $filePath
     */
    public function __construct($filePath)
    {
        $this->filePath = $filePath;
    }
}
