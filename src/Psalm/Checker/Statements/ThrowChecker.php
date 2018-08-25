<?php
namespace Psalm\Checker\Statements;

use PhpParser;
use Psalm\Checker\StatementsChecker;
use Psalm\Checker\TypeChecker;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Issue\InvalidThrow;
use Psalm\IssueBuffer;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

class ThrowChecker
{
    /**
     * @return  false|null
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Stmt\Throw_ $stmt,
        Context $context
    ) {
        if (ExpressionChecker::analyze($statementsChecker, $stmt->expr, $context) === false) {
            return false;
        }

        if ($context->checkClasses && isset($stmt->expr->inferredType) && !$stmt->expr->inferredType->isMixed()) {
            $throwType = $stmt->expr->inferredType;

            $exceptionType = new Union([new TNamedObject('Exception'), new TNamedObject('Throwable')]);

            $fileChecker = $statementsChecker->getFileChecker();
            $projectChecker = $fileChecker->projectChecker;

            if (!TypeChecker::isContainedBy($projectChecker->codebase, $throwType, $exceptionType)) {
                if (IssueBuffer::accepts(
                    new InvalidThrow(
                        'Cannot throw ' . $throwType . ' as it does not extend Exception or implement Throwable',
                        new CodeLocation($fileChecker, $stmt)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    return false;
                }
            } elseif ($context->collectExceptions) {
                foreach ($throwType->getTypes() as $throwAtomicType) {
                    if ($throwAtomicType instanceof TNamedObject) {
                        $context->possiblyThrownExceptions[$throwAtomicType->value] = true;
                    }
                }
            }
        }
    }
}
