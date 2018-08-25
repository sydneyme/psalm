<?php
namespace Psalm\Checker;

use PhpParser;
use Psalm\Aliases;
use Psalm\CodeLocation;

trait CanAlias
{
    /**
     * @var array<string, string>
     */
    private $aliasedClasses = [];

    /**
     * @var array<string, CodeLocation>
     */
    private $aliasedClassLocations = [];

    /**
     * @var array<string, string>
     */
    private $aliasedClassesFlipped = [];

    /**
     * @var array<string, string>
     */
    private $aliasedFunctions = [];

    /**
     * @var array<string, string>
     */
    private $aliasedConstants = [];

    /**
     * @param  PhpParser\Node\Stmt\Use_ $stmt
     *
     * @return void
     */
    public function visitUse(PhpParser\Node\Stmt\Use_ $stmt)
    {
        foreach ($stmt->uses as $use) {
            $usePath = implode('\\', $use->name->parts);
            $useAlias = $use->alias ? $use->alias->name : $use->name->getLast();

            switch ($use->type !== PhpParser\Node\Stmt\Use_::TYPE_UNKNOWN ? $use->type : $stmt->type) {
                case PhpParser\Node\Stmt\Use_::TYPE_FUNCTION:
                    $this->aliasedFunctions[strtolower($useAlias)] = $usePath;
                    break;

                case PhpParser\Node\Stmt\Use_::TYPE_CONSTANT:
                    $this->aliasedConstants[$useAlias] = $usePath;
                    break;

                case PhpParser\Node\Stmt\Use_::TYPE_NORMAL:
                    if ($this->getFileChecker()->projectChecker->getCodeBase()->collectReferences) {
                        // register the path
                        $codebase = $this->getFileChecker()->projectChecker->codebase;

                        $codebase->useReferencingLocations[strtolower($usePath)][$this->getFilePath()][] =
                            new \Psalm\CodeLocation($this, $use);

                        $codebase->useReferencingFiles[$this->getFilePath()][strtolower($usePath)] = true;
                    }

                    $this->aliasedClasses[strtolower($useAlias)] = $usePath;
                    $this->aliasedClassLocations[strtolower($useAlias)] = new CodeLocation($this, $stmt);
                    $this->aliasedClassesFlipped[strtolower($usePath)] = $useAlias;
                    break;
            }
        }
    }

    /**
     * @param  PhpParser\Node\Stmt\GroupUse $stmt
     *
     * @return void
     */
    public function visitGroupUse(PhpParser\Node\Stmt\GroupUse $stmt)
    {
        $usePrefix = implode('\\', $stmt->prefix->parts);

        foreach ($stmt->uses as $use) {
            $usePath = $usePrefix . '\\' . implode('\\', $use->name->parts);
            $useAlias = $use->alias ? $use->alias->name : $use->name->getLast();

            switch ($use->type !== PhpParser\Node\Stmt\Use_::TYPE_UNKNOWN ? $use->type : $stmt->type) {
                case PhpParser\Node\Stmt\Use_::TYPE_FUNCTION:
                    $this->aliasedFunctions[strtolower($useAlias)] = $usePath;
                    break;

                case PhpParser\Node\Stmt\Use_::TYPE_CONSTANT:
                    $this->aliasedConstants[$useAlias] = $usePath;
                    break;

                case PhpParser\Node\Stmt\Use_::TYPE_NORMAL:
                    if ($this->getFileChecker()->projectChecker->getCodeBase()->collectReferences) {
                        // register the path
                        $codebase = $this->getFileChecker()->projectChecker->codebase;

                        $codebase->useReferencingLocations[$usePath][$this->getFilePath()][] =
                            new \Psalm\CodeLocation($this, $use);
                    }

                    $this->aliasedClasses[strtolower($useAlias)] = $usePath;
                    $this->aliasedClassesFlipped[strtolower($usePath)] = $useAlias;
                    break;
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function getAliasedClassesFlipped()
    {
        return $this->aliasedClassesFlipped;
    }

    /**
     * @return Aliases
     */
    public function getAliases()
    {
        return new Aliases(
            $this->getNamespace(),
            $this->aliasedClasses,
            $this->aliasedFunctions,
            $this->aliasedConstants
        );
    }
}
