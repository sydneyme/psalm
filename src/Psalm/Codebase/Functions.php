<?php
namespace Psalm\Codebase;

use Psalm\Checker\ProjectChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Provider\FileStorageProvider;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeStorage;

class Functions
{
    /**
     * @var FileStorageProvider
     */
    private $fileStorageProvider;

    /**
     * @var array<string, FunctionLikeStorage>
     */
    private static $stubbedFunctions;

    /**
     * @var Reflection
     */
    private $reflection;

    public function __construct(FileStorageProvider $storageProvider, Reflection $reflection)
    {
        $this->fileStorageProvider = $storageProvider;
        $this->reflection = $reflection;

        self::$stubbedFunctions = [];
    }

    /**
     * @param  StatementsChecker|null $statementsChecker
     * @param  string $functionId
     *
     * @return FunctionLikeStorage
     */
    public function getStorage($statementsChecker, $functionId)
    {
        if (isset(self::$stubbedFunctions[strtolower($functionId)])) {
            return self::$stubbedFunctions[strtolower($functionId)];
        }

        if ($this->reflection->hasFunction($functionId)) {
            return $this->reflection->getFunctionStorage($functionId);
        }

        if (!$statementsChecker) {
            throw new \UnexpectedValueException('$statementsChecker must not be null here');
        }

        $filePath = $statementsChecker->getRootFilePath();
        $checkedFilePath = $statementsChecker->getFilePath();
        $fileStorage = $this->fileStorageProvider->get($filePath);

        $functionCheckers = $statementsChecker->getFunctionCheckers();

        if (isset($functionCheckers[$functionId])) {
            $functionId = $functionCheckers[$functionId]->getMethodId();

            if (isset($fileStorage->functions[$functionId])) {
                return $fileStorage->functions[$functionId];
            }
        }

        // closures can be returned here
        if (isset($fileStorage->functions[$functionId])) {
            return $fileStorage->functions[$functionId];
        }

        if (!isset($fileStorage->declaringFunctionIds[$functionId])) {
            if ($checkedFilePath !== $filePath) {
                $fileStorage = $this->fileStorageProvider->get($checkedFilePath);

                if (isset($fileStorage->functions[$functionId])) {
                    return $fileStorage->functions[$functionId];
                }
            }

            throw new \UnexpectedValueException(
                'Expecting ' . $functionId . ' to have storage in ' . $filePath
            );
        }

        $declaringFilePath = $fileStorage->declaringFunctionIds[$functionId];

        $declaringFileStorage = $this->fileStorageProvider->get($declaringFilePath);

        if (!isset($declaringFileStorage->functions[$functionId])) {
            throw new \UnexpectedValueException(
                'Not expecting ' . $functionId . ' to not have storage in ' . $declaringFilePath
            );
        }

        return $declaringFileStorage->functions[$functionId];
    }

    /**
     * @param string $functionId
     * @param FunctionLikeStorage $storage
     *
     * @return void
     */
    public function addGlobalFunction($functionId, FunctionLikeStorage $storage)
    {
        self::$stubbedFunctions[strtolower($functionId)] = $storage;
    }

    /**
     * @param  string  $functionId
     *
     * @return bool
     */
    public function hasStubbedFunction($functionId)
    {
        return isset(self::$stubbedFunctions[strtolower($functionId)]);
    }

    /**
     * @param  string $functionId
     *
     * @return bool
     */
    public function functionExists(StatementsChecker $statementsChecker, $functionId)
    {
        $fileStorage = $this->fileStorageProvider->get($statementsChecker->getRootFilePath());

        if (isset($fileStorage->declaringFunctionIds[$functionId])) {
            return true;
        }

        if ($this->reflection->hasFunction($functionId)) {
            return true;
        }

        if (isset(self::$stubbedFunctions[strtolower($functionId)])) {
            return true;
        }

        if (isset($statementsChecker->getFunctionCheckers()[$functionId])) {
            return true;
        }

        if ($this->reflection->registerFunction($functionId) === false) {
            return false;
        }

        return true;
    }

    /**
     * @param  string                   $functionName
     * @param  StatementsSource         $source
     *
     * @return string
     */
    public function getFullyQualifiedFunctionNameFromString($functionName, StatementsSource $source)
    {
        if (empty($functionName)) {
            throw new \InvalidArgumentException('$functionName cannot be empty');
        }

        if ($functionName[0] === '\\') {
            return substr($functionName, 1);
        }

        $functionNameLcase = strtolower($functionName);

        $aliases = $source->getAliases();

        $importedFunctionNamespaces = $aliases->functions;
        $importedNamespaces = $aliases->uses;

        if (strpos($functionName, '\\') !== false) {
            $functionNameParts = explode('\\', $functionName);
            $firstNamespace = array_shift($functionNameParts);
            $firstNamespaceLcase = strtolower($firstNamespace);

            if (isset($importedNamespaces[$firstNamespaceLcase])) {
                return $importedNamespaces[$firstNamespaceLcase] . '\\' . implode('\\', $functionNameParts);
            }

            if (isset($importedFunctionNamespaces[$firstNamespaceLcase])) {
                return $importedFunctionNamespaces[$firstNamespaceLcase] . '\\' .
                    implode('\\', $functionNameParts);
            }
        } elseif (isset($importedNamespaces[$functionNameLcase])) {
            return $importedNamespaces[$functionNameLcase];
        } elseif (isset($importedFunctionNamespaces[$functionNameLcase])) {
            return $importedFunctionNamespaces[$functionNameLcase];
        }

        $namespace = $source->getNamespace();

        return ($namespace ? $namespace . '\\' : '') . $functionName;
    }

    /**
     * @param  string $functionId
     * @param  string $filePath
     *
     * @return bool
     */
    public static function isVariadic(ProjectChecker $projectChecker, $functionId, $filePath)
    {
        $fileStorage = $projectChecker->fileStorageProvider->get($filePath);

        if (!isset($fileStorage->declaringFunctionIds[$functionId])) {
            return false;
        }

        $declaringFilePath = $fileStorage->declaringFunctionIds[$functionId];

        $fileStorage = $declaringFilePath === $filePath
            ? $fileStorage
            : $projectChecker->fileStorageProvider->get($declaringFilePath);

        return isset($fileStorage->functions[$functionId]) && $fileStorage->functions[$functionId]->variadic;
    }
}
