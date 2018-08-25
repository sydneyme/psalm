<?php
namespace Psalm\Checker;

use PhpParser;
use Psalm\Aliases;
use Psalm\StatementsSource;

class TraitChecker extends ClassLikeChecker
{
    /**
     * @var Aliases
     */
    private $aliases;

    /**
     * @param string $fqClassName
     */
    public function __construct(
        PhpParser\Node\Stmt\Trait_ $class,
        StatementsSource $source,
        $fqClassName,
        Aliases $aliases
    ) {
        $this->source = $source;
        $this->fileChecker = $source->getFileChecker();
        $this->class = $class;
        $this->fqClassName = $fqClassName;
        $this->storage = $this->fileChecker->projectChecker->classlikeStorageProvider->get($fqClassName);
        $this->aliases = $aliases;
    }

    /**
     * @return null|string
     */
    public function getNamespace()
    {
        return $this->aliases->namespace;
    }

    /**
     * @return Aliases
     */
    public function getAliases()
    {
        return $this->aliases;
    }

    /**
     * @return array<string, string>
     */
    public function getAliasedClassesFlipped()
    {
        return [];
    }
}
