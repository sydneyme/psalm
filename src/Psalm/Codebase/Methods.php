<?php
namespace Psalm\Codebase;

use PhpParser;
use Psalm\Checker\MethodChecker;
use Psalm\CodeLocation;
use Psalm\Provider\ClassLikeStorageProvider;
use Psalm\Storage\MethodStorage;
use Psalm\Type;

/**
 * @internal
 *
 * Handles information about class methods
 */
class Methods
{
    /**
     * @var ClassLikeStorageProvider
     */
    private $classlikeStorageProvider;

    /**
     * @var \Psalm\Config
     */
    private $config;

    /**
     * @var bool
     */
    public $collectReferences = false;

    /**
     * @param ClassLikeStorageProvider $storageProvider
     */
    public function __construct(
        \Psalm\Config $config,
        ClassLikeStorageProvider $storageProvider
    ) {
        $this->classlikeStorageProvider = $storageProvider;
        $this->config = $config;
    }

    /**
     * Whether or not a given method exists
     *
     * @param  string       $methodId
     * @param  CodeLocation|null $codeLocation
     *
     * @return bool
     */
    public function methodExists(
        $methodId,
        CodeLocation $codeLocation = null
    ) {
        // remove trailing backslash if it exists
        $methodId = preg_replace('/^\\\\/', '', $methodId);
        list($fqClassName, $methodName) = explode('::', $methodId);
        $methodName = strtolower($methodName);
        $methodId = $fqClassName . '::' . $methodName;

        $oldMethodId = null;

        $classStorage = $this->classlikeStorageProvider->get($fqClassName);

        if (isset($classStorage->declaringMethodIds[$methodName])) {
            if ($this->collectReferences && $codeLocation) {
                $declaringMethodId = $classStorage->declaringMethodIds[$methodName];
                list($declaringMethodClass, $declaringMethodName) = explode('::', $declaringMethodId);

                $declaringClassStorage = $this->classlikeStorageProvider->get($declaringMethodClass);
                $declaringMethodStorage = $declaringClassStorage->methods[strtolower($declaringMethodName)];
                if ($declaringMethodStorage->referencingLocations === null) {
                    $declaringMethodStorage->referencingLocations = [];
                }
                $declaringMethodStorage->referencingLocations[$codeLocation->filePath][] = $codeLocation;

                foreach ($classStorage->classImplements as $fqInterfaceName) {
                    $interfaceStorage = $this->classlikeStorageProvider->get($fqInterfaceName);
                    if (isset($interfaceStorage->methods[$methodName])) {
                        $interfaceMethodStorage = $interfaceStorage->methods[$methodName];
                        if (!isset($interfaceMethodStorage->referencingLocations)) {
                            $interfaceMethodStorage->referencingLocations = [];
                        }
                        $interfaceMethodStorage->referencingLocations[$codeLocation->filePath][] = $codeLocation;
                    }
                }

                if (isset($declaringClassStorage->overriddenMethodIds[$declaringMethodName])) {
                    $overriddenMethodIds = $declaringClassStorage->overriddenMethodIds[$declaringMethodName];

                    foreach ($overriddenMethodIds as $overriddenMethodId) {
                        list($overriddenMethodClass, $overriddenMethodName) = explode('::', $overriddenMethodId);

                        $classStorage = $this->classlikeStorageProvider->get($overriddenMethodClass);
                        $methodStorage = $classStorage->methods[strtolower($overriddenMethodName)];
                        if ($methodStorage->referencingLocations === null) {
                            $methodStorage->referencingLocations = [];
                        }
                        $methodStorage->referencingLocations[$codeLocation->filePath][] = $codeLocation;
                    }
                }
            }

            return true;
        }

        if ($classStorage->abstract && isset($classStorage->overriddenMethodIds[$methodName])) {
            return true;
        }

        // support checking oldstyle constructors
        if ($methodName === '__construct') {
            $methodNameParts = explode('\\', $fqClassName);
            $oldConstructorName = array_pop($methodNameParts);
            $oldMethodId = $fqClassName . '::' . $oldConstructorName;
        }

        if (!$classStorage->userDefined
            && (CallMap::inCallMap($methodId) || ($oldMethodId && CallMap::inCallMap($methodId)))
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param  string $methodId
     *
     * @return array<int, \Psalm\Storage\FunctionLikeParameter>
     */
    public function getMethodParams($methodId)
    {
        if ($methodId = $this->getDeclaringMethodId($methodId)) {
            $storage = $this->getStorage($methodId);

            return $storage->params;
        }

        throw new \UnexpectedValueException('Cannot get method params for ' . $methodId);
    }

    /**
     * @param  string $methodId
     *
     * @return bool
     */
    public function isVariadic($methodId)
    {
        $methodId = (string) $this->getDeclaringMethodId($methodId);

        list($fqClassName, $methodName) = explode('::', $methodId);

        return $this->classlikeStorageProvider->get($fqClassName)->methods[$methodName]->variadic;
    }

    /**
     * @param  string $methodId
     * @param  string $selfClass
     * @param  array<int, PhpParser\Node\Arg>|null $args
     *
     * @return Type\Union|null
     */
    public function getMethodReturnType($methodId, &$selfClass, array $args = null)
    {
        if ($this->config->usePhpdocMethodsWithoutCall) {
            list($originalFqClassName, $originalMethodName) = explode('::', $methodId);

            $originalClassStorage = $this->classlikeStorageProvider->get($originalFqClassName);

            if (isset($originalClassStorage->pseudoMethods[strtolower($originalMethodName)])) {
                return $originalClassStorage->pseudoMethods[strtolower($originalMethodName)]->returnType;
            }
        }

        $declaringMethodId = $this->getDeclaringMethodId($methodId);

        if (!$declaringMethodId) {
            return null;
        }

        $appearingMethodId = $this->getAppearingMethodId($methodId);

        if (!$appearingMethodId) {
            return null;
        }

        list($appearingFqClassName, $appearingMethodName) = explode('::', $appearingMethodId);

        $appearingFqClassStorage = $this->classlikeStorageProvider->get($appearingFqClassName);

        if (!$appearingFqClassStorage->userDefined && CallMap::inCallMap($appearingMethodId)) {
            if ($appearingMethodId === 'Closure::fromcallable'
                && isset($args[0]->value->inferredType)
                && $args[0]->value->inferredType->isSingle()
            ) {
                foreach ($args[0]->value->inferredType->getTypes() as $atomicType) {
                    if ($atomicType instanceof Type\Atomic\TCallable || $atomicType instanceof Type\Atomic\Fn) {
                        $callableType = clone $atomicType;

                        return new Type\Union([new Type\Atomic\Fn(
                            'Closure',
                            $callableType->params,
                            $callableType->returnType
                        )]);
                    }
                }
            }
            return CallMap::getReturnTypeFromCallMap($appearingMethodId);
        }

        $storage = $this->getStorage($declaringMethodId);

        if ($storage->returnType) {
            $selfClass = $appearingFqClassName;

            return clone $storage->returnType;
        }

        $classStorage = $this->classlikeStorageProvider->get($appearingFqClassName);

        if (!isset($classStorage->overriddenMethodIds[$appearingMethodName])) {
            return null;
        }

        foreach ($classStorage->overriddenMethodIds[$appearingMethodName] as $overriddenMethodId) {
            $overriddenStorage = $this->getStorage($overriddenMethodId);

            if ($overriddenStorage->returnType) {
                if ($overriddenStorage->returnType->isNull()) {
                    return Type::getVoid();
                }

                list($fqOverriddenClass) = explode('::', $overriddenMethodId);

                $overriddenClassStorage =
                    $this->classlikeStorageProvider->get($fqOverriddenClass);

                $overriddenReturnType = clone $overriddenStorage->returnType;

                if ($overriddenClassStorage->templateTypes) {
                    $genericTypes = [];
                    $overriddenReturnType->replaceTemplateTypesWithStandins(
                        $overriddenClassStorage->templateTypes,
                        $genericTypes
                    );
                }

                $selfClass = $overriddenClassStorage->name;

                return $overriddenReturnType;
            }
        }

        return null;
    }

    /**
     * @param  string $methodId
     *
     * @return bool
     */
    public function getMethodReturnsByRef($methodId)
    {
        $methodId = $this->getDeclaringMethodId($methodId);

        if (!$methodId) {
            return false;
        }

        list($fqClassName) = explode('::', $methodId);

        $fqClassStorage = $this->classlikeStorageProvider->get($fqClassName);

        if (!$fqClassStorage->userDefined && CallMap::inCallMap($methodId)) {
            return false;
        }

        $storage = $this->getStorage($methodId);

        return $storage->returnsByRef;
    }

    /**
     * @param  string               $methodId
     * @param  CodeLocation|null    $definedLocation
     *
     * @return CodeLocation|null
     */
    public function getMethodReturnTypeLocation(
        $methodId,
        CodeLocation &$definedLocation = null
    ) {
        $methodId = $this->getDeclaringMethodId($methodId);

        if ($methodId === null) {
            return null;
        }

        $storage = $this->getStorage($methodId);

        if (!$storage->returnTypeLocation) {
            $overriddenMethodIds = $this->getOverriddenMethodIds($methodId);

            foreach ($overriddenMethodIds as $overriddenMethodId) {
                $overriddenStorage = $this->getStorage($overriddenMethodId);

                if ($overriddenStorage->returnTypeLocation) {
                    $definedLocation = $overriddenStorage->returnTypeLocation;
                    break;
                }
            }
        }

        return $storage->returnTypeLocation;
    }

    /**
     * @param string $methodId
     * @param string $declaringMethodId
     *
     * @return void
     */
    public function setDeclaringMethodId(
        $methodId,
        $declaringMethodId
    ) {
        list($fqClassName, $methodName) = explode('::', $methodId);

        $classStorage = $this->classlikeStorageProvider->get($fqClassName);

        $classStorage->declaringMethodIds[$methodName] = $declaringMethodId;
    }

    /**
     * @param string $methodId
     * @param string $appearingMethodId
     *
     * @return void
     */
    public function setAppearingMethodId(
        $methodId,
        $appearingMethodId
    ) {
        list($fqClassName, $methodName) = explode('::', $methodId);

        $classStorage = $this->classlikeStorageProvider->get($fqClassName);

        $classStorage->appearingMethodIds[$methodName] = $appearingMethodId;
    }

    /**
     * @param  string $methodId
     *
     * @return string|null
     */
    public function getDeclaringMethodId($methodId)
    {
        $methodId = strtolower($methodId);

        list($fqClassName, $methodName) = explode('::', $methodId);

        $classStorage = $this->classlikeStorageProvider->get($fqClassName);

        if (isset($classStorage->declaringMethodIds[$methodName])) {
            return $classStorage->declaringMethodIds[$methodName];
        }

        if ($classStorage->abstract && isset($classStorage->overriddenMethodIds[$methodName])) {
            return $classStorage->overriddenMethodIds[$methodName][0];
        }
    }

    /**
     * Get the class this method appears in (vs is declared in, which could give a trait)
     *
     * @param  string $methodId
     *
     * @return string|null
     */
    public function getAppearingMethodId($methodId)
    {
        $methodId = strtolower($methodId);

        list($fqClassName, $methodName) = explode('::', $methodId);

        $classStorage = $this->classlikeStorageProvider->get($fqClassName);

        if (isset($classStorage->appearingMethodIds[$methodName])) {
            return $classStorage->appearingMethodIds[$methodName];
        }
    }

    /**
     * @param  string $methodId
     *
     * @return array<string>
     */
    public function getOverriddenMethodIds($methodId)
    {
        list($fqClassName, $methodName) = explode('::', $methodId);

        $classStorage = $this->classlikeStorageProvider->get($fqClassName);

        if (isset($classStorage->overriddenMethodIds[$methodName])) {
            return $classStorage->overriddenMethodIds[$methodName];
        }

        return [];
    }

    /**
     * @param  string $originalMethodId
     *
     * @return string
     */
    public function getCasedMethodId($originalMethodId)
    {
        $methodId = $this->getDeclaringMethodId($originalMethodId);

        if ($methodId === null) {
            throw new \UnexpectedValueException('Cannot get declaring method id for ' . $originalMethodId);
        }

        $storage = $this->getStorage($methodId);

        list($fqClassName) = explode('::', $methodId);

        return $fqClassName . '::' . $storage->casedName;
    }

    /**
     * @param  string $methodId
     *
     * @return MethodStorage
     */
    public function getUserMethodStorage($methodId)
    {
        $declaringMethodId = $this->getDeclaringMethodId($methodId);

        if (!$declaringMethodId) {
            throw new \UnexpectedValueException('$storage should not be null for ' . $methodId);
        }

        $storage = $this->getStorage($declaringMethodId);

        if (!$storage->location) {
            throw new \UnexpectedValueException('Storage for ' . $methodId . ' is not user-defined');
        }

        return $storage;
    }

    /**
     * @param  string $methodId
     *
     * @return MethodStorage
     */
    public function getStorage($methodId)
    {
        list($fqClassName, $methodName) = explode('::', $methodId);

        $classStorage = $this->classlikeStorageProvider->get($fqClassName);

        $methodNameLc = strtolower($methodName);

        if (!isset($classStorage->methods[$methodNameLc])) {
            throw new \UnexpectedValueException('$storage should not be null for ' . $methodId);
        }

        return $classStorage->methods[$methodNameLc];
    }
}
