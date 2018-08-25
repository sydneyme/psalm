<?php
namespace Psalm\Example\Plugin;

use PhpParser;
use Psalm\Checker;
use Psalm\Checker\StatementsChecker;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\FileManipulation\FileManipulation;

/**
 * Checks all strings to see if they contain references to classes
 * and, if so, checks that those classes exist.
 */
class StringChecker extends \Psalm\Plugin
{
    /**
     * Called after an expression has been checked
     *
     * @param  StatementsChecker    $statementsChecker
     * @param  PhpParser\Node\Expr  $stmt
     * @param  Context              $context
     * @param  CodeLocation         $codeLocation
     * @param  string[]             $suppressedIssues
     * @param  FileManipulation[]   $fileReplacements
     *
     * @return null|false
     */
    public static function afterExpressionCheck(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr $stmt,
        Context $context,
        CodeLocation $codeLocation,
        array $suppressedIssues,
        array &$fileReplacements = []
    ) {
        if ($stmt instanceof \PhpParser\Node\Scalar\String_) {
            // Replace "Psalm" with your namespace
            $classOrClassMethod = '/^\\\?Psalm(\\\[A-Z][A-Za-z0-9]+)+(::[A-Za-z0-9]+)?$/';

            if (preg_match($classOrClassMethod, $stmt->value)) {
                $fqClassName = preg_split('/[:]/', $stmt->value)[0];

                $projectChecker = $statementsChecker->getFileChecker()->projectChecker;
                if (Checker\ClassChecker::checkFullyQualifiedClassLikeName(
                    $statementsChecker,
                    $fqClassName,
                    $codeLocation,
                    $suppressedIssues
                ) === false
                ) {
                    return false;
                }

                if ($fqClassName !== $stmt->value) {
                    if (Checker\MethodChecker::checkMethodExists(
                        $projectChecker,
                        $stmt->value,
                        $codeLocation,
                        $suppressedIssues
                    )
                    ) {
                        return false;
                    }
                }
            }
        }
    }
}
