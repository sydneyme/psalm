<?php
namespace Psalm\Checker\Statements\Expression;

use PhpParser;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\Context;
use Psalm\Exception\FileIncludeException;
use Psalm\Issue\MissingFile;
use Psalm\Issue\UnresolvableInclude;
use Psalm\IssueBuffer;

class IncludeChecker
{
    /**
     * @return  false|null
     */
    public static function analyze(
        StatementsChecker $statementsChecker,
        PhpParser\Node\Expr\Include_ $stmt,
        Context $context,
        Context $globalContext = null
    ) {
        $config = Config::getInstance();

        if (!$config->allowIncludes) {
            throw new FileIncludeException(
                'File includes are not allowed per your Psalm config - check the allowFileIncludes flag.'
            );
        }

        if (ExpressionChecker::analyze($statementsChecker, $stmt->expr, $context) === false) {
            return false;
        }

        if ($stmt->expr instanceof PhpParser\Node\Scalar\String_
            || (isset($stmt->expr->inferredType) && $stmt->expr->inferredType->isSingleStringLiteral())
        ) {
            if ($stmt->expr instanceof PhpParser\Node\Scalar\String_) {
                $pathToFile = $stmt->expr->value;
            } else {
                $pathToFile = $stmt->expr->inferredType->getSingleStringLiteral()->value;
            }

            $pathToFile = str_replace('/', DIRECTORY_SEPARATOR, $pathToFile);

            // attempts to resolve using get_include_path dirs
            $includePath = self::resolveIncludePath($pathToFile, dirname($statementsChecker->getFileName()));
            $pathToFile = $includePath ? $includePath : $pathToFile;

            if (DIRECTORY_SEPARATOR === '/') {
                $isPathRelative = $pathToFile[0] !== DIRECTORY_SEPARATOR;
            } else {
                $isPathRelative = !preg_match('~^[A-Z]:\\\\~i', $pathToFile);
            }

            if ($isPathRelative) {
                $pathToFile = getcwd() . DIRECTORY_SEPARATOR . $pathToFile;
            }
        } else {
            $pathToFile = self::getPathTo($stmt->expr, $statementsChecker->getFileName());
        }

        if ($pathToFile) {
            $slash = preg_quote(DIRECTORY_SEPARATOR, '/');
            $reducePattern = '/' . $slash . '[^' . $slash . ']+' . $slash . '\.\.' . $slash . '/';

            while (preg_match($reducePattern, $pathToFile)) {
                $pathToFile = preg_replace($reducePattern, DIRECTORY_SEPARATOR, $pathToFile);
            }

            // if the file is already included, we can't check much more
            if (in_array(realpath($pathToFile), get_included_files(), true)) {
                return null;
            }

            $currentFileChecker = $statementsChecker->getFileChecker();

            if ($currentFileChecker->projectChecker->fileExists($pathToFile)) {
                $codebase = $currentFileChecker->projectChecker->codebase;

                if ($statementsChecker->hasParentFilePath($pathToFile)
                    || ($statementsChecker->hasAlreadyRequiredFilePath($pathToFile)
                        && !$codebase->fileStorageProvider->get($pathToFile)->hasExtraStatements)
                ) {
                    return null;
                }

                $currentFileChecker->addRequiredFilePath($pathToFile);

                $fileName = $config->shortenFileName($pathToFile);

                if ($currentFileChecker->projectChecker->debugOutput) {
                    $nesting = $statementsChecker->getRequireNesting() + 1;
                    echo (str_repeat('  ', $nesting) . 'checking ' . $fileName . PHP_EOL);
                }

                $includeFileChecker = new \Psalm\Checker\FileChecker(
                    $currentFileChecker->projectChecker,
                    $pathToFile,
                    $fileName
                );

                $includeFileChecker->setRootFilePath(
                    $currentFileChecker->getRootFilePath(),
                    $currentFileChecker->getRootFileName()
                );

                $includeFileChecker->addParentFilePath($currentFileChecker->getFilePath());
                $includeFileChecker->addRequiredFilePath($currentFileChecker->getFilePath());

                foreach ($currentFileChecker->getRequiredFilePaths() as $requiredFilePath) {
                    $includeFileChecker->addRequiredFilePath($requiredFilePath);
                }

                foreach ($currentFileChecker->getParentFilePaths() as $parentFilePath) {
                    $includeFileChecker->addParentFilePath($parentFilePath);
                }

                try {
                    $includeFileChecker->analyze(
                        $context,
                        false,
                        $globalContext
                    );
                } catch (\Psalm\Exception\UnpreparedAnalysisException $e) {
                    $context->checkClasses = false;
                    $context->checkVariables = false;
                    $context->checkFunctions = false;
                }

                foreach ($includeFileChecker->getRequiredFilePaths() as $requiredFilePath) {
                    $currentFileChecker->addRequiredFilePath($requiredFilePath);
                }

                return null;
            }

            $source = $statementsChecker->getSource();

            if (IssueBuffer::accepts(
                new MissingFile(
                    'Cannot find file ' . $pathToFile . ' to include',
                    new CodeLocation($source, $stmt)
                ),
                $source->getSuppressedIssues()
            )) {
                // fall through
            }
        } else {
            $varId = ExpressionChecker::getArrayVarId($stmt->expr, null);

            if (!$varId || !isset($context->phantomFiles[$varId])) {
                $source = $statementsChecker->getSource();

                if (IssueBuffer::accepts(
                    new UnresolvableInclude(
                        'Cannot resolve the given expression to a file path',
                        new CodeLocation($source, $stmt)
                    ),
                    $source->getSuppressedIssues()
                )) {
                    // fall through
                }
            }
        }

        $context->checkClasses = false;
        $context->checkVariables = false;
        $context->checkFunctions = false;

        return null;
    }

    /**
     * @param  PhpParser\Node\Expr $stmt
     * @param  string              $fileName
     *
     * @return string|null
     * @psalm-suppress MixedAssignment
     */
    public static function getPathTo(PhpParser\Node\Expr $stmt, $fileName)
    {
        if (DIRECTORY_SEPARATOR === '/') {
            $isPathRelative = $fileName[0] !== DIRECTORY_SEPARATOR;
        } else {
            $isPathRelative = !preg_match('~^[A-Z]:\\\\~i', $fileName);
        }

        if ($isPathRelative) {
            $fileName = getcwd() . DIRECTORY_SEPARATOR . $fileName;
        }

        if ($stmt instanceof PhpParser\Node\Scalar\String_) {
            return $stmt->value;
        }

        if (isset($stmt->inferredType) && $stmt->inferredType->isSingleStringLiteral()) {
            return $stmt->inferredType->getSingleStringLiteral()->value;
        }

        if ($stmt instanceof PhpParser\Node\Expr\BinaryOp\Concat) {
            $leftString = self::getPathTo($stmt->left, $fileName);
            $rightString = self::getPathTo($stmt->right, $fileName);

            if ($leftString && $rightString) {
                return $leftString . $rightString;
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\FuncCall &&
            $stmt->name instanceof PhpParser\Node\Name &&
            $stmt->name->parts === ['dirname']
        ) {
            if ($stmt->args) {
                $dirLevel = 1;

                if (isset($stmt->args[1])) {
                    if ($stmt->args[1]->value instanceof PhpParser\Node\Scalar\LNumber) {
                        $dirLevel = $stmt->args[1]->value->value;
                    } else {
                        return null;
                    }
                }

                $evaledPath = self::getPathTo($stmt->args[0]->value, $fileName);

                if (!$evaledPath) {
                    return null;
                }

                return dirname($evaledPath, $dirLevel);
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\ConstFetch && $stmt->name instanceof PhpParser\Node\Name) {
            $constName = implode('', $stmt->name->parts);

            if (defined($constName)) {
                $constantValue = constant($constName);

                if (is_string($constantValue)) {
                    return $constantValue;
                }
            }
        } elseif ($stmt instanceof PhpParser\Node\Scalar\MagicConst\Dir) {
            return dirname($fileName);
        } elseif ($stmt instanceof PhpParser\Node\Scalar\MagicConst\File) {
            return $fileName;
        }

        return null;
    }

    /**
     * @param   string  $fileName
     * @param   string  $currentDirectory
     *
     * @return  string|null
     */
    public static function resolveIncludePath($fileName, $currentDirectory)
    {
        if (!$currentDirectory) {
            return $fileName;
        }

        $paths = PATH_SEPARATOR == ':'
            ? preg_split('#(?<!phar):#', get_include_path())
            : explode(PATH_SEPARATOR, get_include_path());

        foreach ($paths as $prefix) {
            $ds = substr($prefix, -1) == DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR;

            if ($prefix === '.') {
                $prefix = $currentDirectory;
            }

            $file = $prefix . $ds . $fileName;

            if (file_exists($file)) {
                return $file;
            }
        }

        return null;
    }
}
