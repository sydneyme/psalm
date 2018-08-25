<?php
namespace Psalm;

use PhpParser;
use Psalm\Checker\StatementsChecker;
use Psalm\FileManipulation\FileManipulation;
use Psalm\Scanner\FileScanner;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Union;

abstract class Plugin
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
        return null;
    }

    /**
     * Called after a statement has been checked
     *
     * @param  StatementsChecker                        $statementsChecker
     * @param  PhpParser\Node\Stmt|PhpParser\Node\Expr  $stmt
     * @param  Context                                  $context
     * @param  CodeLocation                             $codeLocation
     * @param  string[]                                 $suppressedIssues
     * @param  FileManipulation[]                       $fileReplacements
     *
     * @return null|false
     */
    public static function afterStatementCheck(
        StatementsChecker $statementsChecker,
        PhpParser\Node $stmt,
        Context $context,
        CodeLocation $codeLocation,
        array $suppressedIssues,
        array &$fileReplacements = []
    ) {
        return null;
    }

    /**
     * @param  FileManipulation[] $fileReplacements
     *
     * @return void
     */
    public static function afterVisitClassLike(
        PhpParser\Node\Stmt\ClassLike $stmt,
        ClassLikeStorage $storage,
        FileScanner $file,
        Aliases $aliases,
        array &$fileReplacements = []
    ) {
    }

    /**
     * @param  string             $fqClassName
     * @param  FileManipulation[] $fileReplacements
     *
     * @return void
     */
    public static function afterClassLikeExistsCheck(
        StatementsSource $statementsSource,
        $fqClassName,
        CodeLocation $codeLocation,
        array &$fileReplacements = []
    ) {
    }

    /**
     * @param  string $methodId - the method id being checked
     * @param  string $appearingMethodId - the method id of the class that the method appears in
     * @param  string $declaringMethodId - the method id of the class or trait that declares the method
     * @param  string|null $varId - a reference to the LHS of the variable
     * @param  PhpParser\Node\Arg[] $args
     * @param  FileManipulation[] $fileReplacements
     *
     * @return void
     */
    public static function afterMethodCallCheck(
        StatementsSource $statementsSource,
        $methodId,
        $appearingMethodId,
        $declaringMethodId,
        $varId,
        array $args,
        CodeLocation $codeLocation,
        Context $context,
        array &$fileReplacements = [],
        Union &$returnTypeCandidate = null
    ) {
    }

    /**
     * @param  string $functionId - the method id being checked
     * @param  PhpParser\Node\Arg[] $args
     * @param  FileManipulation[] $fileReplacements
     *
     * @return void
     */
    public static function afterFunctionCallCheck(
        StatementsSource $statementsSource,
        $functionId,
        array $args,
        CodeLocation $codeLocation,
        Context $context,
        array &$fileReplacements = [],
        Union &$returnTypeCandidate = null
    ) {
    }
}
