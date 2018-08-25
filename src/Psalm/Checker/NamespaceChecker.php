<?php
namespace Psalm\Checker;

use PhpParser;
use PhpParser\Node\Stmt\Namespace_;
use Psalm\Context;
use Psalm\StatementsSource;
use Psalm\Type;

class NamespaceChecker extends SourceChecker implements StatementsSource
{
    use CanAlias;

    /**
     * @var FileChecker
     */
    protected $source;

    /**
     * @var Namespace_
     */
    private $namespace;

    /**
     * @var string
     */
    private $namespaceName;

    /**
     * A lookup table for public namespace constants
     *
     * @var array<string, array<string, Type\Union>>
     */
    protected static $publicNamespaceConstants = [];

    /**
     * @param Namespace_        $namespace
     * @param FileChecker       $source
     */
    public function __construct(Namespace_ $namespace, FileChecker $source)
    {
        $this->source = $source;
        $this->namespace = $namespace;
        $this->namespaceName = $this->namespace->name ? implode('\\', $this->namespace->name->parts) : '';
    }

    /**
     * @return  void
     */
    public function collectAnalyzableInformation()
    {
        $leftoverStmts = [];

        if (!isset(self::$publicNamespaceConstants[$this->namespaceName])) {
            self::$publicNamespaceConstants[$this->namespaceName] = [];
        }

        $codebase = $this->getFileChecker()->projectChecker->codebase;

        $namespaceContext = new Context();
        $namespaceContext->collectReferences = $codebase->collectReferences;

        foreach ($this->namespace->stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\ClassLike) {
                $this->collectAnalyzableClassLike($stmt);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Use_) {
                $this->visitUse($stmt);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\GroupUse) {
                $this->visitGroupUse($stmt);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Const_) {
                foreach ($stmt->consts as $const) {
                    self::$publicNamespaceConstants[$this->namespaceName][$const->name->name] = Type::getMixed();
                }

                $leftoverStmts[] = $stmt;
            } else {
                $leftoverStmts[] = $stmt;
            }
        }

        if ($leftoverStmts) {
            $statementsChecker = new StatementsChecker($this);
            $context = new Context();
            $context->collectReferences = $codebase->collectReferences;
            $context->isGlobal = true;
            $statementsChecker->analyze($leftoverStmts, $context);
        }
    }

    /**
     * @param  PhpParser\Node\Stmt\ClassLike $stmt
     *
     * @return void
     */
    public function collectAnalyzableClassLike(PhpParser\Node\Stmt\ClassLike $stmt)
    {
        if (!$stmt->name) {
            throw new \UnexpectedValueException('Did not expect anonymous class here');
        }

        $fqClassName = Type::getFQCLNFromString($stmt->name->name, $this->getAliases());

        if ($stmt instanceof PhpParser\Node\Stmt\Class_) {
            $this->source->addNamespacedClassChecker(
                $fqClassName,
                new ClassChecker($stmt, $this, $fqClassName)
            );
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Interface_) {
            $this->source->addNamespacedInterfaceChecker(
                $fqClassName,
                new InterfaceChecker($stmt, $this, $fqClassName)
            );
        }
    }

    /**
     * @return string
     */
    public function getNamespace()
    {
        return $this->namespaceName;
    }

    /**
     * @param string     $constName
     * @param Type\Union $constType
     *
     * @return void
     */
    public function setConstType($constName, Type\Union $constType)
    {
        self::$publicNamespaceConstants[$this->namespaceName][$constName] = $constType;
    }

    /**
     * @param  string $namespaceName
     * @param  mixed  $visibility
     *
     * @return array<string,Type\Union>
     */
    public static function getConstantsForNamespace($namespaceName, $visibility)
    {
        // @todo this does not allow for loading in namespace constants not already defined in the current sweep
        if (!isset(self::$publicNamespaceConstants[$namespaceName])) {
            self::$publicNamespaceConstants[$namespaceName] = [];
        }

        if ($visibility === \ReflectionProperty::IS_PUBLIC) {
            return self::$publicNamespaceConstants[$namespaceName];
        }

        throw new \InvalidArgumentException('Given $visibility not supported');
    }

    /**
     * @return FileChecker
     */
    public function getFileChecker()
    {
        return $this->source;
    }
}
