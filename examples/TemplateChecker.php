<?php
namespace Psalm\Examples\Template;

use PhpParser;
use Psalm;
use Psalm\Checker\ClassChecker;
use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\CommentChecker;
use Psalm\Checker\MethodChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Type;

class TemplateChecker extends Psalm\Checker\FileChecker
{
    const VIEW_CLASS = 'Your\\View\\Class';

    public function analyze(Context $context = null, $updateDocblocks = false, Context $globalContext = null)
    {
        $codebase = $this->projectChecker->getCodebase();
        $stmts = $codebase->getStatementsForFile($this->filePath);

        if (empty($stmts)) {
            return;
        }

        $firstStmt = $stmts[0];

        $thisParams = null;

        if (($firstStmt instanceof PhpParser\Node\Stmt\Nop) && ($docComment = $firstStmt->getDocComment())) {
            $commentBlock = CommentChecker::parseDocComment(trim($docComment->getText()));

            if (isset($commentBlock['specials']['variablesfrom'])) {
                $variablesFrom = trim($commentBlock['specials']['variablesfrom'][0]);

                $firstLineRegex = '/([A-Za-z\\\0-9]+::[a-z_A-Z]+)(\s+weak)?/';

                $matches = [];

                if (!preg_match($firstLineRegex, $variablesFrom, $matches)) {
                    throw new \InvalidArgumentException('Could not interpret doc comment correctly');
                }

                /** @psalm-suppress MixedArgument */
                $thisParams = $this->checkMethod($matches[1], $firstStmt);

                if ($thisParams === false) {
                    return;
                }

                $thisParams->varsInScope['$this'] = new Type\Union([
                    new Type\Atomic\TNamedObject(self::VIEW_CLASS),
                ]);
            }
        }

        if (!$thisParams) {
            $thisParams = new Context();
            $thisParams->checkVariables = false;
            $thisParams->self = self::VIEW_CLASS;
            $thisParams->varsInScope['$this'] = new Type\Union([
                new Type\Atomic\TNamedObject(self::VIEW_CLASS),
            ]);
        }

        $this->checkWithViewClass($thisParams, $stmts);
    }

    /**
     * @param  string         $methodId
     * @param  PhpParser\Node $stmt
     *
     * @return Context|false
     */
    private function checkMethod($methodId, PhpParser\Node $stmt)
    {
        $class = explode('::', $methodId)[0];

        if (ClassLikeChecker::checkFullyQualifiedClassLikeName(
            $this,
            $class,
            new CodeLocation($this, $stmt),
            [],
            true
        ) === false
        ) {
            return false;
        }

        $thisContext = new Context();
        $thisContext->self = $class;
        $thisContext->varsInScope['$this'] = new Type\Union([new Type\Atomic\TNamedObject($class)]);

        $constructorId = $class . '::__construct';

        $this->projectChecker->getMethodMutations($constructorId, $thisContext);

        $thisContext->varsInScope['$this'] = new Type\Union([new Type\Atomic\TNamedObject($class)]);

        // check the actual method
        $this->projectChecker->getMethodMutations($methodId, $thisContext);

        $viewContext = new Context();
        $viewContext->self = self::VIEW_CLASS;

        // add all $this-> vars to scope
        foreach ($thisContext->varsPossiblyInScope as $var => $_) {
            $viewContext->varsInScope[str_replace('$this->', '$', $var)] = Type::getMixed();
        }

        foreach ($thisContext->varsInScope as $var => $type) {
            $viewContext->varsInScope[str_replace('$this->', '$', $var)] = $type;
        }

        return $viewContext;
    }

    /**
     * @param  Context $context
     * @param  array<PhpParser\Node\Stmt> $stmts
     *
     * @return void
     */
    protected function checkWithViewClass(Context $context, array $stmts)
    {
        $pseudoMethodStmts = [];

        foreach ($stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\Use_) {
                $this->visitUse($stmt);
            } else {
                $pseudoMethodStmts[] = $stmt;
            }
        }

        $pseudoMethodName = preg_replace('/[^a-zA-Z0-9_]+/', '_', $this->fileName);

        $classMethod = new PhpParser\Node\Stmt\ClassMethod($pseudoMethodName, ['stmts' => []]);

        $class = new PhpParser\Node\Stmt\Class_(self::VIEW_CLASS);

        $classChecker = new ClassChecker($class, $this, self::VIEW_CLASS);

        $viewMethodChecker = new MethodChecker($classMethod, $classChecker);

        if (!$context->checkVariables) {
            $viewMethodChecker->addSuppressedIssue('UndefinedVariable');
        }

        $statementsChecker = new StatementsChecker($viewMethodChecker);

        $statementsChecker->analyze($pseudoMethodStmts, $context);
    }
}
