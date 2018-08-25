<?php
namespace Psalm\Checker\Statements\Expression\Call;

use PhpParser;
use Psalm\Checker\ClassChecker;
use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\MethodChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Issue\AbstractInstantiation;
use Psalm\Issue\DeprecatedClass;
use Psalm\Issue\InterfaceInstantiation;
use Psalm\Issue\InvalidStringClass;
use Psalm\Issue\TooManyArguments;
use Psalm\Issue\UndefinedClass;
use Psalm\IssueBuffer;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;

class NewChecker extends \Psalm\Checker\Statements\Expression\CallChecker
{
    /**
     * @param   StatementsChecker           $statementsChecker
     * @param   PhpParser\Node\Expr\New_    $stmt
     * @param   Context                     $context
     *
     * @return  false|null
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\New_ $stmt,
        Context $context
    ) {
        $fqClassName = null;

        $projectChecker = $statementsChecker->getFileChecker()->projectChecker;
        $codebase = $projectChecker->codebase;
        $config = $projectChecker->config;

        $lateStatic = false;

        if ($stmt->class instanceof PhpParser\Node\Name) {
            if (!in_array(strtolower($stmt->class->parts[0]), ['self', 'static', 'parent'], true)) {
                $fqClassName = ClassLikeChecker::getFQCLNFromNameObject(
                    $stmt->class,
                    $statementsChecker->getAliases()
                );

                if ($context->checkClasses) {
                    if ($context->isPhantomClass($fqClassName)) {
                        return null;
                    }

                    if (ClassLikeChecker::checkFullyQualifiedClassLikeName(
                        $statementsChecker,
                        $fqClassName,
                        new CodeLocation($statementsChecker->getSource(), $stmt->class),
                        $statementsChecker->getSuppressedIssues(),
                        false
                    ) === false) {
                        return false;
                    }

                    if ($codebase->interfaceExists($fqClassName)) {
                        if (IssueBuffer::accepts(
                            new InterfaceInstantiation(
                                'Interface ' . $fqClassName . ' cannot be instantiated',
                                new CodeLocation($statementsChecker->getSource(), $stmt->class)
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            return false;
                        }

                        return null;
                    }
                }
            } else {
                switch ($stmt->class->parts[0]) {
                    case 'self':
                        $fqClassName = $context->self;
                        break;

                    case 'parent':
                        $fqClassName = $context->parent;
                        break;

                    case 'static':
                        // @todo maybe we can do better here
                        $fqClassName = $context->self;
                        $lateStatic = true;
                        break;
                }
            }
        } elseif ($stmt->class instanceof PhpParser\Node\Stmt\Class_) {
            $statementsChecker->analyze([$stmt->class], $context);
            $fqClassName = ClassChecker::getAnonymousClassName($stmt->class, $statementsChecker->getFilePath());
        } else {
            ExpressionChecker::analyze($statementsChecker, $stmt->class, $context);

            $genericParams = null;

            if (self::checkMethodArgs(
                null,
                $stmt->args,
                $genericParams,
                $context,
                new CodeLocation($statementsChecker->getSource(), $stmt),
                $statementsChecker
            ) === false) {
                return false;
            }

            if (isset($stmt->class->inferredType)) {
                $newType = null;

                foreach ($stmt->class->inferredType->getTypes() as $lhsTypePart) {
                    // this is always OK
                    if ($lhsTypePart instanceof Type\Atomic\TLiteralClassString
                        || $lhsTypePart instanceof Type\Atomic\TClassString
                    ) {
                        if (!isset($stmt->inferredType)) {
                            $className = $lhsTypePart instanceof Type\Atomic\TClassString
                                ? 'object'
                                : $lhsTypePart->value;

                            if ($newType) {
                                $newType = Type::combineUnionTypes(
                                    $newType,
                                    Type::parseString($className)
                                );
                            } else {
                                $newType = Type::parseString($className);
                            }
                        }

                        continue;
                    }

                    if ($lhsTypePart instanceof Type\Atomic\TString) {
                        if ($config->allowStringStandinForClass
                            && !$lhsTypePart instanceof Type\Atomic\TNumericString
                        ) {
                            // do nothing
                        } elseif (IssueBuffer::accepts(
                            new InvalidStringClass(
                                'String cannot be used as a class',
                                new CodeLocation($statementsChecker->getSource(), $stmt)
                            ),
                            $statementsChecker->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    } elseif ($lhsTypePart instanceof Type\Atomic\TMixed
                        || $lhsTypePart instanceof Type\Atomic\TGenericParam
                    ) {
                        // do nothing
                    } elseif ($lhsTypePart instanceof Type\Atomic\TFalse
                        && $stmt->class->inferredType->ignoreFalsableIssues
                    ) {
                        // do nothing
                    } elseif ($lhsTypePart instanceof Type\Atomic\TNull
                        && $stmt->class->inferredType->ignoreNullableIssues
                    ) {
                        // do nothing
                    } elseif (IssueBuffer::accepts(
                        new UndefinedClass(
                            'Type ' . $lhsTypePart . ' cannot be called as a class',
                            new CodeLocation($statementsChecker->getSource(), $stmt),
                            (string)$lhsTypePart
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }

                    if ($newType) {
                        $newType = Type::combineUnionTypes(
                            $newType,
                            Type::getObject()
                        );
                    } else {
                        $newType = Type::getObject();
                    }
                }

                if ($newType) {
                    $stmt->inferredType = $newType;
                }
            }

            return null;
        }

        if ($fqClassName) {
            $stmt->inferredType = new Type\Union([new TNamedObject($fqClassName)]);

            if (strtolower($fqClassName) !== 'stdclass' &&
                $context->checkClasses &&
                $codebase->classlikes->classExists($fqClassName)
            ) {
                $storage = $projectChecker->classlikeStorageProvider->get($fqClassName);

                // if we're not calling this constructor via new static()
                if ($storage->abstract && !$lateStatic) {
                    if (IssueBuffer::accepts(
                        new AbstractInstantiation(
                            'Unable to instantiate a abstract class ' . $fqClassName,
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        return false;
                    }
                }

                if ($storage->deprecated) {
                    if (IssueBuffer::accepts(
                        new DeprecatedClass(
                            $fqClassName . ' is marked deprecated',
                            new CodeLocation($statementsChecker->getSource(), $stmt)
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }

                if ($codebase->methodExists(
                    $fqClassName . '::__construct',
                    $context->collectReferences ? new CodeLocation($statementsChecker->getSource(), $stmt) : null
                )) {
                    $methodId = $fqClassName . '::__construct';

                    if (self::checkMethodArgs(
                        $methodId,
                        $stmt->args,
                        $foundGenericParams,
                        $context,
                        new CodeLocation($statementsChecker->getSource(), $stmt),
                        $statementsChecker
                    ) === false) {
                        return false;
                    }

                    if (MethodChecker::checkMethodVisibility(
                        $methodId,
                        $context->self,
                        $statementsChecker->getSource(),
                        new CodeLocation($statementsChecker->getSource(), $stmt),
                        $statementsChecker->getSuppressedIssues()
                    ) === false) {
                        return false;
                    }

                    $genericParams = null;

                    if ($storage->templateTypes) {
                        foreach ($storage->templateTypes as $templateName => $_) {
                            if (isset($foundGenericParams[$templateName])) {
                                $genericParams[] = $foundGenericParams[$templateName];
                            } else {
                                $genericParams[] = Type::getMixed();
                            }
                        }
                    }

                    if ($fqClassName === 'ArrayIterator' && isset($stmt->args[0]->value->inferredType)) {
                        $firstArgType = $stmt->args[0]->value->inferredType;

                        if ($firstArgType->hasGeneric()) {
                            $keyType = null;
                            $valueType = null;

                            foreach ($firstArgType->getTypes() as $type) {
                                if ($type instanceof Type\Atomic\TArray) {
                                    $firstTypeParam = count($type->typeParams) ? $type->typeParams[0] : null;
                                    $lastTypeParam = $type->typeParams[count($type->typeParams) - 1];

                                    if ($valueType === null) {
                                        $valueType = clone $lastTypeParam;
                                    } else {
                                        $valueType = Type::combineUnionTypes($valueType, $lastTypeParam);
                                    }

                                    if (!$keyType || !$firstTypeParam) {
                                        $keyType = $firstTypeParam ? clone $firstTypeParam : Type::getMixed();
                                    } else {
                                        $keyType = Type::combineUnionTypes($keyType, $firstTypeParam);
                                    }
                                }
                            }

                            if ($keyType === null) {
                                throw new \UnexpectedValueException('$keyType cannot be null');
                            }

                            if ($valueType === null) {
                                throw new \UnexpectedValueException('$valueType cannot be null');
                            }

                            $stmt->inferredType = new Type\Union([
                                new Type\Atomic\TGenericObject(
                                    $fqClassName,
                                    [
                                        $keyType,
                                        $valueType,
                                    ]
                                ),
                            ]);
                        }
                    } elseif ($genericParams) {
                        $stmt->inferredType = new Type\Union([
                            new Type\Atomic\TGenericObject(
                                $fqClassName,
                                $genericParams
                            ),
                        ]);
                    }
                } elseif ($stmt->args) {
                    if (IssueBuffer::accepts(
                        new TooManyArguments(
                            'Class ' . $fqClassName . ' has no __construct, but arguments were passed',
                            new CodeLocation($statementsChecker->getSource(), $stmt),
                            $fqClassName . '::__construct'
                        ),
                        $statementsChecker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }
            }
        }

        if (!$config->rememberPropertyAssignmentsAfterCall && !$context->collectInitializations) {
            $context->removeAllObjectVars();
        }

        return null;
    }
}
