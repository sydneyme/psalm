<?php
namespace Psalm\Codebase;

use Psalm\Checker\ClassLikeChecker;
use Psalm\Config;
use Psalm\Issue\CircularReference;
use Psalm\IssueBuffer;
use Psalm\Provider\ClassLikeStorageProvider;
use Psalm\Provider\FileReferenceProvider;
use Psalm\Provider\FileStorageProvider;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FileStorage;
use Psalm\Type;

/**
 * @internal
 *
 * Populates file and class information so that analysis can work properly
 */
class Populator
{
    /**
     * @var ClassLikeStorageProvider
     */
    private $classlikeStorageProvider;

    /**
     * @var FileStorageProvider
     */
    private $fileStorageProvider;

    /**
     * @var bool
     */
    private $debugOutput;

    /**
     * @var ClassLikes
     */
    private $classlikes;

    /**
     * @var Config
     */
    private $config;

    /**
     * @param bool $debugOutput
     */
    public function __construct(
        Config $config,
        ClassLikeStorageProvider $classlikeStorageProvider,
        FileStorageProvider $fileStorageProvider,
        ClassLikes $classlikes,
        $debugOutput
    ) {
        $this->classlikeStorageProvider = $classlikeStorageProvider;
        $this->fileStorageProvider = $fileStorageProvider;
        $this->classlikes = $classlikes;
        $this->debugOutput = $debugOutput;
        $this->config = $config;
    }

    /**
     * @return void
     */
    public function populateCodebase(\Psalm\Codebase $codebase)
    {
        if ($this->debugOutput) {
            echo 'ClassLikeStorage is populating' . "\n";
        }

        foreach ($this->classlikeStorageProvider->getAll() as $classStorage) {
            if (!$classStorage->userDefined && !$classStorage->stubbed) {
                continue;
            }

            $this->populateClassLikeStorage($classStorage);
        }

        if ($this->debugOutput) {
            echo 'ClassLikeStorage is populated' . "\n";
        }

        if ($this->debugOutput) {
            echo 'FileStorage is populating' . "\n";
        }

        $allFileStorage = $this->fileStorageProvider->getAll();

        foreach ($allFileStorage as $fileStorage) {
            $this->populateFileStorage($fileStorage);
        }


        foreach ($this->classlikeStorageProvider->getAll() as $classStorage) {
            if ($this->config->allowPhpstormGenerics) {
                foreach ($classStorage->properties as $propertyStorage) {
                    if ($propertyStorage->type) {
                        $this->convertPhpStormGenericToPsalmGeneric($propertyStorage->type, true);
                    }
                }

                foreach ($classStorage->methods as $methodStorage) {
                    if ($methodStorage->returnType) {
                        $this->convertPhpStormGenericToPsalmGeneric($methodStorage->returnType);
                    }

                    foreach ($methodStorage->params as $paramStorage) {
                        if ($paramStorage->type) {
                            $this->convertPhpStormGenericToPsalmGeneric($paramStorage->type);
                        }
                    }
                }
            }

            if ($classStorage->aliases) {
                foreach ($classStorage->publicClassConstantNodes as $constName => $node) {
                    $constType = \Psalm\Checker\StatementsChecker::getSimpleType(
                        $codebase,
                        $node,
                        $classStorage->aliases,
                        null,
                        null,
                        $classStorage->name
                    );

                    $classStorage->publicClassConstants[$constName] = $constType ?: Type::getMixed();
                }

                foreach ($classStorage->protectedClassConstantNodes as $constName => $node) {
                    $constType = \Psalm\Checker\StatementsChecker::getSimpleType(
                        $codebase,
                        $node,
                        $classStorage->aliases,
                        null,
                        null,
                        $classStorage->name
                    );

                    $classStorage->protectedClassConstants[$constName] = $constType ?: Type::getMixed();
                }

                foreach ($classStorage->privateClassConstantNodes as $constName => $node) {
                    $constType = \Psalm\Checker\StatementsChecker::getSimpleType(
                        $codebase,
                        $node,
                        $classStorage->aliases,
                        null,
                        null,
                        $classStorage->name
                    );

                    $classStorage->privateClassConstants[$constName] = $constType ?: Type::getMixed();
                }
            }
        }

        if ($this->config->allowPhpstormGenerics) {
            foreach ($allFileStorage as $fileStorage) {
                foreach ($fileStorage->functions as $functionStorage) {
                    if ($functionStorage->returnType) {
                        $this->convertPhpStormGenericToPsalmGeneric($functionStorage->returnType);
                    }

                    foreach ($functionStorage->params as $paramStorage) {
                        if ($paramStorage->type) {
                            $this->convertPhpStormGenericToPsalmGeneric($paramStorage->type);
                        }
                    }
                }
            }
        }

        if ($this->debugOutput) {
            echo 'FileStorage is populated' . "\n";
        }
    }

    /**
     * @param  ClassLikeStorage $storage
     * @param  array            $dependentClasslikes
     *
     * @return void
     */
    private function populateClassLikeStorage(ClassLikeStorage $storage, array $dependentClasslikes = [])
    {
        if ($storage->populated) {
            return;
        }

        $fqClasslikeNameLc = strtolower($storage->name);

        if (isset($dependentClasslikes[$fqClasslikeNameLc])) {
            if ($storage->location && IssueBuffer::accepts(
                new CircularReference(
                    'Circular reference discovered when loading ' . $storage->name,
                    $storage->location
                )
            )) {
                // fall through
            }

            return;
        }

        $storageProvider = $this->classlikeStorageProvider;

        $this->populateDataFromTraits($storage, $storageProvider, $dependentClasslikes);

        $dependentClasslikes[$fqClasslikeNameLc] = true;

        if ($storage->parentClasses) {
            $this->populateDataFromParentClass($storage, $storageProvider, $dependentClasslikes);
        }

        $this->populateInterfaceDataFromParentInterfaces($storage, $storageProvider, $dependentClasslikes);

        $this->populateDataFromImplementedInterfaces($storage, $storageProvider, $dependentClasslikes);

        if ($storage->location) {
            $filePath = $storage->location->filePath;

            foreach ($storage->parentInterfaces as $parentInterfaceLc) {
                FileReferenceProvider::addFileInheritanceToClass($filePath, $parentInterfaceLc);
            }

            foreach ($storage->parentClasses as $parentClassLc) {
                FileReferenceProvider::addFileInheritanceToClass($filePath, $parentClassLc);
            }

            foreach ($storage->classImplements as $implementedInterface) {
                FileReferenceProvider::addFileInheritanceToClass($filePath, strtolower($implementedInterface));
            }

            foreach ($storage->usedTraits as $usedTraitLc) {
                FileReferenceProvider::addFileInheritanceToClass($filePath, $usedTraitLc);
            }
        }

        if ($this->debugOutput) {
            echo 'Have populated ' . $storage->name . "\n";
        }

        $storage->populated = true;
    }

    /**
     * @return void
     */
    private function populateDataFromTraits(
        ClassLikeStorage $storage,
        ClassLikeStorageProvider $storageProvider,
        array $dependentClasslikes
    ) {
        foreach ($storage->usedTraits as $usedTraitLc => $_) {
            try {
                $traitStorage = $storageProvider->get($usedTraitLc);
            } catch (\InvalidArgumentException $e) {
                continue;
            }

            $this->populateClassLikeStorage($traitStorage, $dependentClasslikes);

            $this->inheritMethodsFromParent($storage, $traitStorage);
            $this->inheritPropertiesFromParent($storage, $traitStorage);
        }
    }

    /**
     * @return void
     */
    private function populateDataFromParentClass(
        ClassLikeStorage $storage,
        ClassLikeStorageProvider $storageProvider,
        array $dependentClasslikes
    ) {
        $parentStorageClass = reset($storage->parentClasses);

        try {
            $parentStorage = $storageProvider->get($parentStorageClass);
        } catch (\InvalidArgumentException $e) {
            $storage->invalidDependencies[] = $parentStorageClass;
            $parentStorage = null;
        }

        if ($parentStorage) {
            $this->populateClassLikeStorage($parentStorage, $dependentClasslikes);

            $storage->parentClasses = array_merge($storage->parentClasses, $parentStorage->parentClasses);

            $this->inheritMethodsFromParent($storage, $parentStorage);
            $this->inheritPropertiesFromParent($storage, $parentStorage);

            $storage->classImplements += $parentStorage->classImplements;
            $storage->invalidDependencies = array_merge(
                $storage->invalidDependencies,
                $parentStorage->invalidDependencies
            );

            $storage->publicClassConstants += $parentStorage->publicClassConstants;
            $storage->protectedClassConstants += $parentStorage->protectedClassConstants;

            $storage->pseudoPropertyGetTypes += $parentStorage->pseudoPropertyGetTypes;
            $storage->pseudoPropertySetTypes += $parentStorage->pseudoPropertySetTypes;
        }
    }

    /**
     * @return void
     */
    private function populateInterfaceDataFromParentInterfaces(
        ClassLikeStorage $storage,
        ClassLikeStorageProvider $storageProvider,
        array $dependentClasslikes
    ) {
        $parentInterfaces = [];

        foreach ($storage->parentInterfaces as $parentInterfaceLc => $_) {
            try {
                $parentInterfaceStorage = $storageProvider->get($parentInterfaceLc);
            } catch (\InvalidArgumentException $e) {
                $storage->invalidDependencies[] = $parentInterfaceLc;
                continue;
            }

            $this->populateClassLikeStorage($parentInterfaceStorage, $dependentClasslikes);

            // copy over any constants
            $storage->publicClassConstants = array_merge(
                $storage->publicClassConstants,
                $parentInterfaceStorage->publicClassConstants
            );

            $storage->invalidDependencies = array_merge(
                $storage->invalidDependencies,
                $parentInterfaceStorage->invalidDependencies
            );

            $parentInterfaces = array_merge($parentInterfaces, $parentInterfaceStorage->parentInterfaces);

            $this->inheritMethodsFromParent($storage, $parentInterfaceStorage);
        }

        $storage->parentInterfaces = array_merge($parentInterfaces, $storage->parentInterfaces);
    }

    /**
     * @return void
     */
    private function populateDataFromImplementedInterfaces(
        ClassLikeStorage $storage,
        ClassLikeStorageProvider $storageProvider,
        array $dependentClasslikes
    ) {
        $extraInterfaces = [];

        foreach ($storage->classImplements as $implementedInterfaceLc => $_) {
            try {
                $implementedInterfaceStorage = $storageProvider->get($implementedInterfaceLc);
            } catch (\InvalidArgumentException $e) {
                $storage->invalidDependencies[] = $implementedInterfaceLc;
                continue;
            }

            $this->populateClassLikeStorage($implementedInterfaceStorage, $dependentClasslikes);

            // copy over any constants
            $storage->publicClassConstants = array_merge(
                $storage->publicClassConstants,
                $implementedInterfaceStorage->publicClassConstants
            );

            $storage->invalidDependencies = array_merge(
                $storage->invalidDependencies,
                $implementedInterfaceStorage->invalidDependencies
            );

            $extraInterfaces = array_merge($extraInterfaces, $implementedInterfaceStorage->parentInterfaces);

            $storage->publicClassConstants += $implementedInterfaceStorage->publicClassConstants;
        }

        $storage->classImplements = array_merge($extraInterfaces, $storage->classImplements);

        $interfaceMethodImplementers = [];

        foreach ($storage->classImplements as $implementedInterface) {
            try {
                $implementedInterfaceStorage = $storageProvider->get($implementedInterface);
            } catch (\InvalidArgumentException $e) {
                continue;
            }

            foreach ($implementedInterfaceStorage->methods as $methodName => $method) {
                if ($method->visibility === ClassLikeChecker::VISIBILITY_PUBLIC) {
                    $mentionedMethodId = $implementedInterface . '::' . $methodName;
                    $interfaceMethodImplementers[$methodName][] = $mentionedMethodId;
                }
            }
        }

        foreach ($interfaceMethodImplementers as $methodName => $interfaceMethodIds) {
            if (count($interfaceMethodIds) === 1) {
                $storage->overriddenMethodIds[$methodName][] = $interfaceMethodIds[0];
            } else {
                $storage->interfaceMethodIds[$methodName] = $interfaceMethodIds;
            }
        }
    }

    /**
     * @param  FileStorage $storage
     * @param  array<string, bool> $dependentFilePaths
     *
     * @return void
     */
    private function populateFileStorage(FileStorage $storage, array $dependentFilePaths = [])
    {
        if ($storage->populated) {
            return;
        }

        $filePathLc = strtolower($storage->filePath);

        if (isset($dependentFilePaths[$filePathLc])) {
            return;
        }

        $dependentFilePaths[$filePathLc] = true;

        $allRequiredFilePaths = $storage->requiredFilePaths;

        foreach ($storage->requiredFilePaths as $includedFilePath => $_) {
            try {
                $includedFileStorage = $this->fileStorageProvider->get($includedFilePath);
            } catch (\InvalidArgumentException $e) {
                continue;
            }

            $this->populateFileStorage($includedFileStorage, $dependentFilePaths);

            $allRequiredFilePaths = $allRequiredFilePaths + $includedFileStorage->requiredFilePaths;
        }

        foreach ($allRequiredFilePaths as $includedFilePath => $_) {
            try {
                $includedFileStorage = $this->fileStorageProvider->get($includedFilePath);
            } catch (\InvalidArgumentException $e) {
                continue;
            }

            $storage->declaringFunctionIds = array_merge(
                $includedFileStorage->declaringFunctionIds,
                $storage->declaringFunctionIds
            );

            $storage->declaringConstants = array_merge(
                $includedFileStorage->declaringConstants,
                $storage->declaringConstants
            );
        }

        $storage->requiredFilePaths = $allRequiredFilePaths;

        foreach ($allRequiredFilePaths as $requiredFilePath) {
            try {
                $requiredFileStorage = $this->fileStorageProvider->get($requiredFilePath);
            } catch (\InvalidArgumentException $e) {
                continue;
            }

            $requiredFileStorage->requiredByFilePaths += [$filePathLc => $storage->filePath];
        }

        foreach ($storage->requiredClasses as $requiredClasslike) {
            try {
                $classlikeStorage = $this->classlikeStorageProvider->get($requiredClasslike);
            } catch (\InvalidArgumentException $e) {
                continue;
            }

            if (!$classlikeStorage->location) {
                continue;
            }

            try {
                $requiredFileStorage = $this->fileStorageProvider->get($classlikeStorage->location->filePath);
            } catch (\InvalidArgumentException $e) {
                continue;
            }

            $requiredFileStorage->requiredByFilePaths += [$filePathLc => $storage->filePath];
        }

        $storage->populated = true;
    }

    /**
     * @param  Type\Union $candidate
     * @param  bool       $isProperty
     *
     * @return void
     */
    private function convertPhpStormGenericToPsalmGeneric(Type\Union $candidate, $isProperty = false)
    {
        $atomicTypes = $candidate->getTypes();

        if (isset($atomicTypes['array']) && count($atomicTypes) > 1) {
            $iteratorName = null;
            $genericParams = null;

            foreach ($atomicTypes as $type) {
                if ($type instanceof Type\Atomic\TNamedObject
                    && (!$type->fromDocblock || $isProperty)
                    && (
                        strtolower($type->value) === 'traversable'
                        || $this->classlikes->interfaceExtends(
                            $type->value,
                            'Traversable'
                        )
                        || $this->classlikes->classImplements(
                            $type->value,
                            'Traversable'
                        )
                    )
                ) {
                    $iteratorName = $type->value;
                } elseif ($type instanceof Type\Atomic\TArray) {
                    $genericParams = $type->typeParams;
                }
            }

            if ($iteratorName && $genericParams) {
                $genericIterator = new Type\Atomic\TGenericObject($iteratorName, $genericParams);
                $candidate->removeType('array');
                $candidate->addType($genericIterator);
            }
        }
    }

    /**
     * @param ClassLikeStorage $storage
     * @param ClassLikeStorage $parentStorage
     *
     * @return void
     */
    protected function inheritMethodsFromParent(ClassLikeStorage $storage, ClassLikeStorage $parentStorage)
    {
        $fqClassName = $storage->name;

        // register where they appear (can never be in a trait)
        foreach ($parentStorage->appearingMethodIds as $methodName => $appearingMethodId) {
            $aliasedMethodNames = [$methodName];

            if ($parentStorage->isTrait
                && $storage->traitAliasMap
                && isset($storage->traitAliasMap[$methodName])
            ) {
                $aliasedMethodNames[] = $storage->traitAliasMap[$methodName];
            }

            foreach ($aliasedMethodNames as $aliasedMethodName) {
                if (isset($storage->appearingMethodIds[$aliasedMethodName])) {
                    continue;
                }

                $implementedMethodId = $fqClassName . '::' . $aliasedMethodName;

                $storage->appearingMethodIds[$aliasedMethodName] =
                    $parentStorage->isTrait ? $implementedMethodId : $appearingMethodId;
            }
        }

        // register where they're declared
        foreach ($parentStorage->inheritableMethodIds as $methodName => $declaringMethodId) {
            if (!$parentStorage->isTrait) {
                $storage->overriddenMethodIds[$methodName][] = $declaringMethodId;

                if (isset($storage->methods[$methodName])) {
                    $storage->methods[$methodName]->overriddenSomewhere = true;
                }
            }

            $aliasedMethodNames = [$methodName];

            if ($parentStorage->isTrait
                && $storage->traitAliasMap
                && isset($storage->traitAliasMap[$methodName])
            ) {
                $aliasedMethodNames[] = $storage->traitAliasMap[$methodName];
            }

            foreach ($aliasedMethodNames as $aliasedMethodName) {
                if (isset($storage->declaringMethodIds[$aliasedMethodName])) {
                    list($implementingFqClassName, $implementingMethodName) = explode(
                        '::',
                        $storage->declaringMethodIds[$aliasedMethodName]
                    );

                    $implementingClassStorage = $this->classlikeStorageProvider->get($implementingFqClassName);

                    if (!$implementingClassStorage->methods[$implementingMethodName]->abstract) {
                        continue;
                    }
                }

                $storage->declaringMethodIds[$aliasedMethodName] = $declaringMethodId;
                $storage->inheritableMethodIds[$aliasedMethodName] = $declaringMethodId;
            }
        }

        foreach ($storage->methods as $methodName => $_) {
            if (isset($storage->overriddenMethodIds[$methodName])) {
                foreach ($storage->overriddenMethodIds[$methodName] as $declaringMethodId) {
                    list($declaringClass, $declaringMethodName) = explode('::', $declaringMethodId);
                    $declaringClassStorage = $this->classlikeStorageProvider->get($declaringClass);

                    // tell the declaring class it's overridden downstream
                    $declaringClassStorage->methods[strtolower($declaringMethodName)]->overriddenDownstream = true;
                    $declaringClassStorage->methods[strtolower($declaringMethodName)]->overriddenSomewhere = true;
                }
            }
        }
    }

    /**
     * @param ClassLikeStorage $storage
     * @param ClassLikeStorage $parentStorage
     *
     * @return void
     */
    private function inheritPropertiesFromParent(ClassLikeStorage $storage, ClassLikeStorage $parentStorage)
    {
        // register where they appear (can never be in a trait)
        foreach ($parentStorage->appearingPropertyIds as $propertyName => $appearingPropertyId) {
            if (isset($storage->appearingPropertyIds[$propertyName])) {
                continue;
            }

            if (!$parentStorage->isTrait
                && isset($parentStorage->properties[$propertyName])
                && $parentStorage->properties[$propertyName]->visibility === ClassLikeChecker::VISIBILITY_PRIVATE
            ) {
                continue;
            }

            $implementedPropertyId = $storage->name . '::$' . $propertyName;

            $storage->appearingPropertyIds[$propertyName] =
                $parentStorage->isTrait ? $implementedPropertyId : $appearingPropertyId;
        }

        // register where they're declared
        foreach ($parentStorage->declaringPropertyIds as $propertyName => $declaringPropertyClass) {
            if (isset($storage->declaringPropertyIds[$propertyName])) {
                continue;
            }

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

            if (!$parentStorage->isTrait) {
                $storage->overriddenPropertyIds[$propertyName][] = $inheritablePropertyId;
            }

            $storage->inheritablePropertyIds[$propertyName] = $inheritablePropertyId;
        }
    }
}
