<?php
namespace Psalm\Codebase;

use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\CommentChecker;
use Psalm\Codebase;
use Psalm\Provider\ClassLikeStorageProvider;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Storage\PropertyStorage;
use Psalm\Type;

/**
 * @internal
 *
 * Handles information gleaned from class and function reflection
 */
class Reflection
{
    /**
     * @var ClassLikeStorageProvider
     */
    private $storageProvider;

    /**
     * @var Codebase
     */
    private $codebase;

    /**
     * @var array<string, FunctionLikeStorage>
     */
    private static $builtinFunctions = [];

    public function __construct(ClassLikeStorageProvider $storageProvider, Codebase $codebase)
    {
        $this->storageProvider = $storageProvider;
        $this->codebase = $codebase;
        self::$builtinFunctions = [];
    }

    /**
     * @return void
     */
    public function registerClass(\ReflectionClass $reflectedClass)
    {
        $className = $reflectedClass->name;

        if ($className === 'LibXMLError') {
            $className = 'libXMLError';
        }

        $classNameLower = strtolower($className);

        try {
            $this->storageProvider->get($classNameLower);

            return;
        } catch (\Exception $e) {
            // this is fine
        }

        $reflectedParentClass = $reflectedClass->getParentClass();

        $storage = $this->storageProvider->create($className);
        $storage->abstract = $reflectedClass->isAbstract();

        if ($reflectedParentClass) {
            $parentClassName = $reflectedParentClass->getName();
            $this->registerClass($reflectedParentClass);

            $parentStorage = $this->storageProvider->get($parentClassName);

            $this->registerInheritedMethods($className, $parentClassName);
            $this->registerInheritedProperties($className, $parentClassName);

            $storage->classImplements = $parentStorage->classImplements;

            $storage->publicClassConstants = $parentStorage->publicClassConstants;
            $storage->protectedClassConstants = $parentStorage->protectedClassConstants;
            $parentClassNameLc = strtolower($parentClassName);
            $storage->parentClasses = array_merge(
                [$parentClassNameLc => $parentClassNameLc],
                $parentStorage->parentClasses
            );

            $storage->usedTraits = $parentStorage->usedTraits;
        }

        $classProperties = $reflectedClass->getProperties();

        $publicMappedProperties = PropertyMap::inPropertyMap($className)
            ? PropertyMap::getPropertyMap()[strtolower($className)]
            : [];

        /** @var \ReflectionProperty $classProperty */
        foreach ($classProperties as $classProperty) {
            $propertyName = $classProperty->getName();
            $storage->properties[$propertyName] = new PropertyStorage();

            $storage->properties[$propertyName]->type = Type::getMixed();

            if ($classProperty->isStatic()) {
                $storage->properties[$propertyName]->isStatic = true;
            }

            if ($classProperty->isPublic()) {
                $storage->properties[$propertyName]->visibility = ClassLikeChecker::VISIBILITY_PUBLIC;
            } elseif ($classProperty->isProtected()) {
                $storage->properties[$propertyName]->visibility = ClassLikeChecker::VISIBILITY_PROTECTED;
            } elseif ($classProperty->isPrivate()) {
                $storage->properties[$propertyName]->visibility = ClassLikeChecker::VISIBILITY_PRIVATE;
            }

            $propertyId = (string)$classProperty->class . '::$' . $propertyName;

            $storage->declaringPropertyIds[$propertyName] = (string)$classProperty->class;
            $storage->appearingPropertyIds[$propertyName] = $propertyId;

            if (!$classProperty->isPrivate()) {
                $storage->inheritablePropertyIds[$propertyName] = $propertyId;
            }
        }

        // have to do this separately as there can be new properties here
        foreach ($publicMappedProperties as $propertyName => $type) {
            if (!isset($storage->properties[$propertyName])) {
                $storage->properties[$propertyName] = new PropertyStorage();
                $storage->properties[$propertyName]->visibility = ClassLikeChecker::VISIBILITY_PUBLIC;

                $propertyId = $className . '::$' . $propertyName;

                $storage->declaringPropertyIds[$propertyName] = $className;
                $storage->appearingPropertyIds[$propertyName] = $propertyId;
                $storage->inheritablePropertyIds[$propertyName] = $propertyId;
            }

            $storage->properties[$propertyName]->type = Type::parseString($type);
        }

        /** @var array<string, int|string|float|null|array> */
        $classConstants = $reflectedClass->getConstants();

        foreach ($classConstants as $name => $value) {
            $storage->publicClassConstants[$name] = ClassLikeChecker::getTypeFromValue($value);
        }

        if ($reflectedClass->isInterface()) {
            $this->codebase->classlikes->addFullyQualifiedInterfaceName($className);
        } elseif ($reflectedClass->isTrait()) {
            $this->codebase->classlikes->addFullyQualifiedTraitName($className);
        } else {
            $this->codebase->classlikes->addFullyQualifiedClassName($className);
        }

        $reflectionMethods = $reflectedClass->getMethods(
            (int) (\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED)
        );

        if ($classNameLower === 'generator') {
            $storage->templateTypes = ['TKey' => Type::getMixed(), 'TValue' => Type::getMixed()];
        }

        $interfaces = $reflectedClass->getInterfaces();

        /** @var \ReflectionClass $interface */
        foreach ($interfaces as $interface) {
            $interfaceName = $interface->getName();
            $this->registerClass($interface);

            if ($reflectedClass->isInterface()) {
                $storage->parentInterfaces[strtolower($interfaceName)] = $interfaceName;
            } else {
                $storage->classImplements[strtolower($interfaceName)] = $interfaceName;
            }
        }

        /** @var \ReflectionMethod $reflectionMethod */
        foreach ($reflectionMethods as $reflectionMethod) {
            $methodReflectionClass = $reflectionMethod->getDeclaringClass();

            $this->registerClass($methodReflectionClass);

            $this->extractReflectionMethodInfo($reflectionMethod);

            if ($reflectionMethod->class !== $className) {
                $this->codebase->methods->setDeclaringMethodId(
                    $className . '::' . strtolower($reflectionMethod->name),
                    $reflectionMethod->class . '::' . strtolower($reflectionMethod->name)
                );

                $this->codebase->methods->setAppearingMethodId(
                    $className . '::' . strtolower($reflectionMethod->name),
                    $reflectionMethod->class . '::' . strtolower($reflectionMethod->name)
                );

                continue;
            }
        }
    }

    /**
     * @param \ReflectionMethod $method
     *
     * @return void
     */
    public function extractReflectionMethodInfo(\ReflectionMethod $method)
    {
        $methodName = strtolower($method->getName());

        $classStorage = $this->storageProvider->get($method->class);

        if (isset($classStorage->methods[strtolower($methodName)])) {
            return;
        }

        $methodId = $method->class . '::' . $methodName;

        $storage = $classStorage->methods[strtolower($methodName)] = new MethodStorage();

        $storage->casedName = $method->name;

        if (strtolower((string)$method->name) === strtolower((string)$method->class)) {
            $this->codebase->methods->setDeclaringMethodId(
                $method->class . '::__construct',
                $method->class . '::' . $methodName
            );
            $this->codebase->methods->setAppearingMethodId(
                $method->class . '::__construct',
                $method->class . '::' . $methodName
            );
        }

        $declaringClass = $method->getDeclaringClass();

        $storage->isStatic = $method->isStatic();
        $storage->abstract = $method->isAbstract();

        $classStorage->declaringMethodIds[$methodName] =
            $declaringClass->name . '::' . strtolower((string)$method->getName());

        $classStorage->inheritableMethodIds[$methodName] = $classStorage->declaringMethodIds[$methodName];
        $classStorage->appearingMethodIds[$methodName] = $classStorage->declaringMethodIds[$methodName];
        $classStorage->overriddenMethodIds[$methodName] = [];

        try {
            $storage->returnType = CallMap::getReturnTypeFromCallMap($methodId);
            $storage->returnType->queueClassLikesForScanning($this->codebase);
        } catch (\InvalidArgumentException $e) {
            // do nothing
        }

        $storage->visibility = $method->isPrivate()
            ? ClassLikeChecker::VISIBILITY_PRIVATE
            : ($method->isProtected() ? ClassLikeChecker::VISIBILITY_PROTECTED : ClassLikeChecker::VISIBILITY_PUBLIC);

        $possibleParams = CallMap::getParamsFromCallMap($methodId);

        if ($possibleParams === null) {
            $params = $method->getParameters();

            $storage->params = [];

            /** @var \ReflectionParameter $param */
            foreach ($params as $param) {
                $paramArray = $this->getReflectionParamData($param);
                $storage->params[] = $paramArray;
                $storage->paramTypes[$param->name] = $paramArray->type;
            }
        } else {
            foreach ($possibleParams[0] as $param) {
                if ($param->type) {
                    $param->type->queueClassLikesForScanning($this->codebase);
                }
            }

            $storage->params = $possibleParams[0];
        }

        $storage->requiredParamCount = 0;

        foreach ($storage->params as $i => $param) {
            if (!$param->isOptional) {
                $storage->requiredParamCount = $i + 1;
            }
        }
    }

    /**
     * @param  \ReflectionParameter $param
     *
     * @return FunctionLikeParameter
     */
    private function getReflectionParamData(\ReflectionParameter $param)
    {
        $paramTypeString = null;

        if ($param->isArray()) {
            $paramTypeString = 'array';
        } else {
            try {
                $paramClass = $param->getClass();
            } catch (\ReflectionException $e) {
                $paramClass = null;
            }

            if ($paramClass) {
                $paramTypeString = (string)$paramClass->getName();
            }
        }

        $isNullable = false;

        $isOptional = (bool)$param->isOptional();

        try {
            $isNullable = $param->getDefaultValue() === null;

            if ($paramTypeString && $isNullable) {
                $paramTypeString .= '|null';
            }
        } catch (\ReflectionException $e) {
            // do nothing
        }

        $paramName = (string)$param->getName();
        $paramType = $paramTypeString ? Type::parseString($paramTypeString) : Type::getMixed();

        return new FunctionLikeParameter(
            $paramName,
            (bool)$param->isPassedByReference(),
            $paramType,
            null,
            null,
            $isOptional,
            $isNullable,
            $param->isVariadic()
        );
    }

    /**
     * @param  string $functionId
     *
     * @return false|null
     */
    public function registerFunction($functionId)
    {
        try {
            $reflectionFunction = new \ReflectionFunction($functionId);

            $storage = self::$builtinFunctions[$functionId] = new FunctionLikeStorage();

            $reflectionParams = $reflectionFunction->getParameters();

            /** @var \ReflectionParameter $param */
            foreach ($reflectionParams as $param) {
                $paramObj = $this->getReflectionParamData($param);
                $storage->params[] = $paramObj;
            }

            $storage->requiredParamCount = 0;

            foreach ($storage->params as $i => $param) {
                if (!$param->isOptional) {
                    $storage->requiredParamCount = $i + 1;
                }
            }

            $storage->casedName = $reflectionFunction->getName();

            if (version_compare(PHP_VERSION, '7.0.0dev', '>=')
                && $reflectionReturnType = $reflectionFunction->getReturnType()
            ) {
                $storage->returnType = Type::parseString((string)$reflectionReturnType);
            }
        } catch (\ReflectionException $e) {
            return false;
        }
    }

    /**
     * @param string $fqClassName
     * @param string $parentClass
     *
     * @return void
     */
    private function registerInheritedMethods(
        $fqClassName,
        $parentClass
    ) {
        $parentStorage = $this->storageProvider->get($parentClass);
        $storage = $this->storageProvider->get($fqClassName);

        // register where they appear (can never be in a trait)
        foreach ($parentStorage->appearingMethodIds as $methodName => $appearingMethodId) {
            $storage->appearingMethodIds[$methodName] = $appearingMethodId;
        }

        // register where they're declared
        foreach ($parentStorage->inheritableMethodIds as $methodName => $declaringMethodId) {
            $storage->declaringMethodIds[$methodName] = $declaringMethodId;
            $storage->inheritableMethodIds[$methodName] = $declaringMethodId;

            $storage->overriddenMethodIds[$methodName][] = $declaringMethodId;
        }
    }

    /**
     * @param string $fqClassName
     * @param string $parentClass
     *
     * @return void
     */
    private function registerInheritedProperties(
        $fqClassName,
        $parentClass
    ) {
        $parentStorage = $this->storageProvider->get($parentClass);
        $storage = $this->storageProvider->get($fqClassName);

        // register where they appear (can never be in a trait)
        foreach ($parentStorage->appearingPropertyIds as $propertyName => $appearingPropertyId) {
            if (!$parentStorage->isTrait
                && isset($parentStorage->properties[$propertyName])
                && $parentStorage->properties[$propertyName]->visibility === ClassLikeChecker::VISIBILITY_PRIVATE
            ) {
                continue;
            }

            $storage->appearingPropertyIds[$propertyName] = $appearingPropertyId;
        }

        // register where they're declared
        foreach ($parentStorage->declaringPropertyIds as $propertyName => $declaringPropertyClass) {
            if (!$parentStorage->isTrait
                && isset($parentStorage->properties[$propertyName])
                && $parentStorage->properties[$propertyName]->visibility === ClassLikeChecker::VISIBILITY_PRIVATE
            ) {
                continue;
            }

            $storage->declaringPropertyIds[$propertyName] = $declaringPropertyClass;
        }

        // register where they're declared
        foreach ($parentStorage->inheritablePropertyIds as $propertyName => $inheritablePropertyId) {
            if (!$parentStorage->isTrait
                && isset($parentStorage->properties[$propertyName])
                && $parentStorage->properties[$propertyName]->visibility === ClassLikeChecker::VISIBILITY_PRIVATE
            ) {
                continue;
            }

            $storage->inheritablePropertyIds[$propertyName] = $inheritablePropertyId;
        }
    }

    /**
     * @param  string  $functionId
     *
     * @return bool
     */
    public function hasFunction($functionId)
    {
        return isset(self::$builtinFunctions[$functionId]);
    }

    /**
     * @param  string  $functionId
     *
     * @return FunctionLikeStorage
     */
    public function getFunctionStorage($functionId)
    {
        if (isset(self::$builtinFunctions[$functionId])) {
            return self::$builtinFunctions[$functionId];
        }

        throw new \UnexpectedValueException('Expecting to have a function for ' . $functionId);
    }
}
