<?php
namespace Psalm\Checker;

use PhpParser;
use Psalm\Context;
use Psalm\Exception\UnpreparedAnalysisException;
use Psalm\FileManipulation\FileManipulationBuffer;
use Psalm\IssueBuffer;
use Psalm\StatementsSource;
use Psalm\Type;

class FileChecker extends SourceChecker implements StatementsSource
{
    use CanAlias;

    /**
     * @var string
     */
    protected $fileName;

    /**
     * @var string
     */
    protected $filePath;

    /**
     * @var string|null
     */
    protected $rootFilePath;

    /**
     * @var string|null
     */
    protected $rootFileName;

    /**
     * @var array<string, bool>
     */
    protected $requiredFilePaths = [];

    /**
     * @var array<string, bool>
     */
    protected $parentFilePaths = [];

    /**
     * @var array<int, string>
     */
    protected $suppressedIssues = [];

    /**
     * @var array<string, array<string, string>>
     */
    protected $namespaceAliasedClasses = [];

    /**
     * @var array<string, array<string, string>>
     */
    protected $namespaceAliasedClassesFlipped = [];

    /**
     * @var array<string, InterfaceChecker>
     */
    protected $interfaceCheckersToAnalyze = [];

    /**
     * @var array<string, ClassChecker>
     */
    protected $classCheckersToAnalyze = [];

    /**
     * @var null|Context
     */
    public $context;

    /**
     * @var ProjectChecker
     */
    public $projectChecker;

    /**
     * @param ProjectChecker  $projectChecker
     * @param string  $filePath
     * @param string  $fileName
     */
    public function __construct(ProjectChecker $projectChecker, $filePath, $fileName)
    {
        $this->filePath = $filePath;
        $this->fileName = $fileName;
        $this->projectChecker = $projectChecker;
    }

    /**
     * @param  bool $preserveCheckers
     *
     * @return void
     */
    public function analyze(Context $fileContext = null, $preserveCheckers = false, Context $globalContext = null)
    {
        $codebase = $this->projectChecker->codebase;

        $fileStorage = $codebase->fileStorageProvider->get($this->filePath);

        if (!$fileStorage->deepScan) {
            throw new UnpreparedAnalysisException('File ' . $this->filePath . ' has not been properly scanned');
        }

        if ($fileContext) {
            $this->context = $fileContext;
        }

        if (!$this->context) {
            $this->context = new Context();
            $this->context->collectReferences = $codebase->collectReferences;
        }

        $this->context->isGlobal = true;

        $stmts = $codebase->getStatementsForFile($this->filePath);

        $statementsChecker = new StatementsChecker($this);

        $leftoverStmts = $this->populateCheckers($stmts);

        // if there are any leftover statements, evaluate them,
        // in turn causing the classes/interfaces be evaluated
        if ($leftoverStmts) {
            $statementsChecker->analyze($leftoverStmts, $this->context, $globalContext, true);
        }

        // check any leftover interfaces not already evaluated
        foreach ($this->interfaceCheckersToAnalyze as $interfaceChecker) {
            $interfaceChecker->analyze();
        }

        // check any leftover classes not already evaluated
        foreach ($this->classCheckersToAnalyze as $classChecker) {
            $classChecker->analyze(null, $this->context);
        }

        if (!$preserveCheckers) {
            $this->classCheckersToAnalyze = [];
        }
    }

    /**
     * @param  array<int, PhpParser\Node\Stmt>  $stmts
     *
     * @return array<int, PhpParser\Node\Stmt>
     */
    public function populateCheckers(array $stmts)
    {
        $leftoverStmts = [];

        foreach ($stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\ClassLike) {
                $this->populateClassLikeCheckers($stmt);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Namespace_) {
                $namespaceName = $stmt->name ? implode('\\', $stmt->name->parts) : '';

                $namespaceChecker = new NamespaceChecker($stmt, $this);
                $namespaceChecker->collectAnalyzableInformation();

                $this->namespaceAliasedClasses[$namespaceName] = $namespaceChecker->getAliases()->uses;
                $this->namespaceAliasedClassesFlipped[$namespaceName] =
                    $namespaceChecker->getAliasedClassesFlipped();
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Use_) {
                $this->visitUse($stmt);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\GroupUse) {
                $this->visitGroupUse($stmt);
            } else {
                if ($stmt instanceof PhpParser\Node\Stmt\If_) {
                    foreach ($stmt->stmts as $ifStmt) {
                        if ($ifStmt instanceof PhpParser\Node\Stmt\ClassLike) {
                            $this->populateClassLikeCheckers($ifStmt);
                        }
                    }
                }

                $leftoverStmts[] = $stmt;
            }
        }

        return $leftoverStmts;
    }

    /**
     * @return void
     */
    private function populateClassLikeCheckers(PhpParser\Node\Stmt\ClassLike $stmt)
    {
        if (!$stmt->name) {
            return;
        }

        if ($stmt instanceof PhpParser\Node\Stmt\Class_) {
            $classChecker = new ClassChecker($stmt, $this, $stmt->name->name);

            $fqClassName = $classChecker->getFQCLN();

            $this->classCheckersToAnalyze[strtolower($fqClassName)] = $classChecker;
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Interface_) {
            $classChecker = new InterfaceChecker($stmt, $this, $stmt->name->name);

            $fqClassName = $classChecker->getFQCLN();

            $this->interfaceCheckersToAnalyze[$fqClassName] = $classChecker;
        }
    }

    /**
     * @param string       $fqClassName
     * @param ClassChecker $classChecker
     *
     * @return  void
     */
    public function addNamespacedClassChecker($fqClassName, ClassChecker $classChecker)
    {
        $this->classCheckersToAnalyze[strtolower($fqClassName)] = $classChecker;
    }

    /**
     * @param string            $fqClassName
     * @param InterfaceChecker  $interfaceChecker
     *
     * @return  void
     */
    public function addNamespacedInterfaceChecker($fqClassName, InterfaceChecker $interfaceChecker)
    {
        $this->interfaceCheckersToAnalyze[strtolower($fqClassName)] = $interfaceChecker;
    }

    /**
     * @param  string   $methodId
     * @param  Context  $thisContext
     *
     * @return void
     */
    public function getMethodMutations($methodId, Context $thisContext)
    {
        list($fqClassName, $methodName) = explode('::', $methodId);

        if (isset($this->classCheckersToAnalyze[strtolower($fqClassName)])) {
            $classCheckerToExamine = $this->classCheckersToAnalyze[strtolower($fqClassName)];
        } else {
            $this->projectChecker->getMethodMutations($methodId, $thisContext);

            return;
        }

        $callContext = new Context($thisContext->self);
        $callContext->collectMutations = true;
        $callContext->collectInitializations = $thisContext->collectInitializations;
        $callContext->initializedMethods = $thisContext->initializedMethods;
        $callContext->includeLocation = $thisContext->includeLocation;

        foreach ($thisContext->varsPossiblyInScope as $var => $_) {
            if (strpos($var, '$this->') === 0) {
                $callContext->varsPossiblyInScope[$var] = true;
            }
        }

        foreach ($thisContext->varsInScope as $var => $type) {
            if (strpos($var, '$this->') === 0) {
                $callContext->varsInScope[$var] = $type;
            }
        }

        $callContext->varsInScope['$this'] = $thisContext->varsInScope['$this'];

        $classCheckerToExamine->getMethodMutations($methodName, $callContext);

        foreach ($callContext->varsPossiblyInScope as $var => $_) {
            $thisContext->varsPossiblyInScope[$var] = true;
        }

        foreach ($callContext->varsInScope as $var => $type) {
            $thisContext->varsInScope[$var] = $type;
        }
    }

    /**
     * @return null|string
     */
    public function getNamespace()
    {
        return null;
    }

    /**
     * @param  string|null $namespaceName
     *
     * @return array<string, string>
     */
    public function getAliasedClassesFlipped($namespaceName = null)
    {
        if ($namespaceName && isset($this->namespaceAliasedClassesFlipped[$namespaceName])) {
            return $this->namespaceAliasedClassesFlipped[$namespaceName];
        }

        return $this->aliasedClassesFlipped;
    }

    /**
     * @return void
     */
    public static function clearCache()
    {
        IssueBuffer::clearCache();
        FileManipulationBuffer::clearCache();
        FunctionLikeChecker::clearCache();
        \Psalm\Provider\ClassLikeStorageProvider::deleteAll();
        \Psalm\Provider\FileStorageProvider::deleteAll();
    }

    /**
     * @return string
     */
    public function getFileName()
    {
        return $this->fileName;
    }

    /**
     * @return string
     */
    public function getFilePath()
    {
        return $this->filePath;
    }

    /**
     * @return string
     */
    public function getRootFileName()
    {
        return $this->rootFileName ?: $this->fileName;
    }

    /**
     * @return string
     */
    public function getRootFilePath()
    {
        return $this->rootFilePath ?: $this->filePath;
    }

    /**
     * @param string $filePath
     * @param string $fileName
     *
     * @return void
     */
    public function setRootFilePath($filePath, $fileName)
    {
        $this->rootFileName = $fileName;
        $this->rootFilePath = $filePath;
    }

    /**
     * @param string $filePath
     *
     * @return void
     */
    public function addRequiredFilePath($filePath)
    {
        $this->requiredFilePaths[$filePath] = true;
    }

    /**
     * @param string $filePath
     *
     * @return void
     */
    public function addParentFilePath($filePath)
    {
        $this->parentFilePaths[$filePath] = true;
    }

    /**
     * @param string $filePath
     *
     * @return bool
     */
    public function hasParentFilePath($filePath)
    {
        return $this->filePath === $filePath || isset($this->parentFilePaths[$filePath]);
    }

    /**
     * @param string $filePath
     *
     * @return bool
     */
    public function hasAlreadyRequiredFilePath($filePath)
    {
        return isset($this->requiredFilePaths[$filePath]);
    }

    /**
     * @return array<int, string>
     */
    public function getRequiredFilePaths()
    {
        return array_keys($this->requiredFilePaths);
    }

    /**
     * @return array<int, string>
     */
    public function getParentFilePaths()
    {
        return array_keys($this->parentFilePaths);
    }

    /**
     * @return int
     */
    public function getRequireNesting()
    {
        return count($this->parentFilePaths);
    }

    /**
     * @return array<int, string>
     */
    public function getSuppressedIssues()
    {
        return $this->suppressedIssues;
    }

    /**
     * @param array<int, string> $newIssues
     *
     * @return void
     */
    public function addSuppressedIssues(array $newIssues)
    {
        $this->suppressedIssues = array_merge($newIssues, $this->suppressedIssues);
    }

    /**
     * @param array<int, string> $newIssues
     *
     * @return void
     */
    public function removeSuppressedIssues(array $newIssues)
    {
        $this->suppressedIssues = array_diff($this->suppressedIssues, $newIssues);
    }

    /**
     * @return null|string
     */
    public function getFQCLN()
    {
        return null;
    }

    /**
     * @return null|string
     */
    public function getClassName()
    {
        return null;
    }

    /**
     * @return bool
     */
    public function isStatic()
    {
        return false;
    }

    /**
     * @return FileChecker
     */
    public function getFileChecker()
    {
        return $this;
    }
}
