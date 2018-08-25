<?php
namespace Psalm\Checker\Statements\Expression\Fetch;

use PhpParser;
use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Checker\TraitChecker;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Issue\DeprecatedConstant;
use Psalm\Issue\InaccessibleClassConstant;
use Psalm\Issue\ParentNotFound;
use Psalm\Issue\UndefinedConstant;
use Psalm\IssueBuffer;
use Psalm\Type;

class ConstFetchChecker
{
    /**
     * @param   StatementsChecker               $statementsChecker
     * @param   PhpParser\Node\Expr\ConstFetch  $stmt
     * @param   Context                         $context
     *
     * @return  void
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\ConstFetch $stmt,
        Context $context
    ) {
        $constName = implode('\\', $stmt->name->parts);
        switch (strtolower($constName)) {
            case 'null':
                $stmt->inferredType = Type::getNull();
                break;

            case 'false':
                // false is a subtype of bool
                $stmt->inferredType = Type::getFalse();
                break;

            case 'true':
                $stmt->inferredType = Type::getTrue();
                break;

            case 'stdin':
                $stmt->inferredType = Type::getResource();
                break;

            default:
                $constType = $statementsChecker->getConstType(
                    $statementsChecker,
                    $constName,
                    $stmt->name instanceof PhpParser\Node\Name\FullyQualified,
                    $context
                );

                if ($constType) {
                    $stmt->inferredType = clone $constType;
                } elseif ($context->checkConsts) {
                    if (IssueBuffer::accepts(
                        new UndefinedConstant(
                            'Const ' . $constName . ' is not defined',
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }
        }
    }

    /**
     * @param   StatementsChecker                   $statementsChecker
     * @param   PhpParser\Node\Expr\ClassConstFetch $stmt
     * @param   Context                             $context
     *
     * @return  null|false
     */
    public static function analyzeClassConst(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\ClassConstFetch $stmt,
        Context $context
    ) {
        if ($context->checkConsts
            && $stmt->class instanceof PhpParser\Node\Name
            && $stmt->name instanceof PhpParser\Node\Identifier
        ) {
            $firstPartLc = strtolower($stmt->class->parts[0]);

            if ($firstPartLc === 'self' || $firstPartLc === 'static') {
                if (!$context->self) {
                    throw new \UnexpectedValueException('$context->self cannot be null');
                }

                $fqClassName = (string)$context->self;
            } elseif ($firstPartLc === 'parent') {
                $fqClassName = $statementsChecker->getParentFQCLN();

                if ($fqClassName === null) {
                    if (IssueBuffer::accepts(
                        new ParentNotFound(
                            'Cannot check property fetch on parent as this class does not extend another',
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }

                    return;
                }
            } else {
                $fqClassName = ClassLikeChecker::getFQCLNFromNameObject(
                    $stmt->class,
                    $statementsChecker->getAliases()
                );

                if (!$context->insideClassExists || $stmt->name->name !== 'class') {
                    if (ClassLikeChecker::checkFullyQualifiedClassLikeName(
                        $statementsChecker,
                        $fqClassName,
                        new CodeLocation($statementsChecker->getSource(), $stmt->class),
                        $statementsChecker->getSuppressedIssues(),
                        false
                    ) === false) {
                        return false;
                    }
                }
            }

            if ($stmt->name->name === 'class') {
                $stmt->inferredType = Type::getClassString($fqClassName);

                return null;
            }

            $projectChecker = $statementsChecker->getFileChecker()->projectChecker;
            $codebase = $projectChecker->codebase;

            // if we're ignoring that the class doesn't exist, exit anyway
            if (!$codebase->classOrInterfaceExists($fqClassName)) {
                $stmt->inferredType = Type::getMixed();

                return null;
            }

            $constId = $fqClassName . '::' . $stmt->name;

            if ($fqClassName === $context->self
                || (
                    $statementsChecker->getSource()->getSource() instanceof TraitChecker &&
                    $fqClassName === $statementsChecker->getSource()->getFQCLN()
                )
            ) {
                $classVisibility = \ReflectionProperty::IS_PRIVATE;
            } elseif ($context->self &&
                $codebase->classExtends($context->self, $fqClassName)
            ) {
                $classVisibility = \ReflectionProperty::IS_PROTECTED;
            } else {
                $classVisibility = \ReflectionProperty::IS_PUBLIC;
            }

            $classConstants = $codebase->classlikes->getConstantsForClass(
                $fqClassName,
                $classVisibility
            );

            if (!isset($classConstants[$stmt->name->name]) && $firstPartLc !== 'static') {
                $allClassConstants = [];

                if ($fqClassName !== $context->self) {
                    $allClassConstants = $codebase->classlikes->getConstantsForClass(
                        $fqClassName,
                        \ReflectionProperty::IS_PRIVATE
                    );
                }

                if ($allClassConstants && isset($allClassConstants[$stmt->name->name])) {
                    if (IssueBuffer::accepts(
                        new InaccessibleClassConstant(
                            'Constant ' . $constId . ' is not visible in this context',
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                } else {
                    if (IssueBuffer::accepts(
                        new UndefinedConstant(
                            'Constant ' . $constId . ' is not defined',
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }

                return false;
            }

            $classConstStorage = $codebase->classlikeStorageProvider->get($fqClassName);

            if (isset($classConstStorage->deprecatedConstants[$stmt->name->name])) {
                if (IssueBuffer::accepts(
                    new DeprecatedConstant(
                        'Constant ' . $constId . ' is deprecated',
                        new CodeLocation($statementsChecker->getSource(), $stmt)
                    ),
                    $statementsChecker->getSuppressedIssues()
                )) {
                    // fall through
                }
            }

            if (isset($classConstants[$stmt->name->name]) && $firstPartLc !== 'static') {
                $stmt->inferredType = clone $classConstants[$stmt->name->name];
            } else {
                $stmt->inferredType = Type::getMixed();
            }

            return null;
        }

        $stmt->inferredType = Type::getMixed();

        if ($stmt->class instanceof PhpParser\Node\Expr) {
            if (ExpressionChecker::analyze($statementsChecker, $stmt->class, $context) === false) {
                return false;
            }
        }

        return null;
    }

    /**
     * @param  Codebase $codebase
     * @param  ?string  $fqConstName
     * @param  string   $constName
     *
     * @return Type\Union|null
     */
    public static function getGlobalConstType(
        Codebase $codebase,
        $fqConstName,
        $constName
    ) {
        if ($constName === 'STDERR'
            || $constName === 'STDOUT'
            || $constName === 'STDIN'
        ) {
            return Type::getResource();
        }

        $predefinedConstants = $codebase->config->getPredefinedConstants();

        if (isset($predefinedConstants[$fqConstName ?: $constName])) {
            switch ($fqConstName ?: $constName) {
                case 'PHP_VERSION':
                case 'DIRECTORY_SEPARATOR':
                case 'PATH_SEPARATOR':
                case 'PEAR_EXTENSION_DIR':
                case 'PEAR_INSTALL_DIR':
                case 'PHP_BINARY':
                case 'PHP_BINDIR':
                case 'PHP_CONFIG_FILE_PATH':
                case 'PHP_CONFIG_FILE_SCAN_DIR':
                case 'PHP_DATADIR':
                case 'PHP_EOL':
                case 'PHP_EXTENSION_DIR':
                case 'PHP_EXTRA_VERSION':
                case 'PHP_LIBDIR':
                case 'PHP_LOCALSTATEDIR':
                case 'PHP_MANDIR':
                case 'PHP_OS':
                case 'PHP_OS_FAMILY':
                case 'PHP_PREFIX':
                case 'PHP_SAPI':
                case 'PHP_SYSCONFDIR':
                    return Type::getString();

                case 'PHP_MAJOR_VERSION':
                case 'PHP_MINOR_VERSION':
                case 'PHP_RELEASE_VERSION':
                case 'PHP_DEBUG':
                case 'PHP_FLOAT_DIG':
                case 'PHP_INT_MAX':
                case 'PHP_INT_MIN':
                case 'PHP_INT_SIZE':
                case 'PHP_MAXPATHLEN':
                case 'PHP_VERSION_ID':
                case 'PHP_ZTS':
                    return Type::getInt();

                case 'PHP_FLOAT_EPSILON':
                case 'PHP_FLOAT_MAX':
                case 'PHP_FLOAT_MIN':
                    return Type::getFloat();
            }

            $type = ClassLikeChecker::getTypeFromValue($predefinedConstants[$fqConstName ?: $constName]);
            return $type;
        }

        $stubbedConstType = $codebase->getStubbedConstantType(
            $fqConstName ?: $constName
        );

        if ($stubbedConstType) {
            return $stubbedConstType;
        }

        return null;
    }
}
