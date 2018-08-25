<?php
namespace Psalm\Checker;

use PhpParser;
use Psalm\CodeLocation;
use Psalm\StatementsSource;

class InterfaceChecker extends ClassLikeChecker
{
    /**
     * @param PhpParser\Node\Stmt\Interface_ $interface
     * @param StatementsSource               $source
     * @param string                         $fqInterfaceName
     */
    public function __construct(PhpParser\Node\Stmt\Interface_ $interface, StatementsSource $source, $fqInterfaceName)
    {
        parent::__construct($interface, $source, $fqInterfaceName);
    }

    /**
     * @return void
     */
    public function analyze()
    {
        if (!$this->class instanceof PhpParser\Node\Stmt\Interface_) {
            throw new \LogicException('Something went badly wrong');
        }

        if ($this->class->extends) {
            foreach ($this->class->extends as $extendedInterface) {
                $extendedInterfaceName = self::getFQCLNFromNameObject(
                    $extendedInterface,
                    $this->getAliases()
                );

                $parentReferenceLocation = new CodeLocation($this, $extendedInterface);

                $projectChecker = $this->fileChecker->projectChecker;

                if (!$projectChecker->codebase->classOrInterfaceExists(
                    $extendedInterfaceName,
                    $parentReferenceLocation
                )) {
                    // we should not normally get here
                    return;
                }
            }
        }
    }
}
