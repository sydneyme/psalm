<?php
namespace Psalm\Codebase;

use Psalm\CodeLocation;
use Psalm\Provider\ClassLikeStorageProvider;

/**
 * @internal
 *
 * Handles information about class properties
 */
class Properties
{
    /**
     * @var ClassLikeStorageProvider
     */
    private $classlikeStorageProvider;

    /**
     * @var bool
     */
    public $collectReferences = false;

    public function __construct(
        ClassLikeStorageProvider $storageProvider
    ) {
        $this->classlikeStorageProvider = $storageProvider;
    }

    /**
     * Whether or not a given property exists
     *
     * @param  string $propertyId
     *
     * @return bool
     */
    public function propertyExists(
        $propertyId,
        CodeLocation $codeLocation = null
    ) {
        // remove trailing backslash if it exists
        $propertyId = preg_replace('/^\\\\/', '', $propertyId);

        list($fqClassName, $propertyName) = explode('::$', $propertyId);

        $classStorage = $this->classlikeStorageProvider->get($fqClassName);

        if (isset($classStorage->declaringPropertyIds[$propertyName])) {
            if ($this->collectReferences && $codeLocation) {
                $declaringPropertyClass = $classStorage->declaringPropertyIds[$propertyName];

                $declaringClassStorage = $this->classlikeStorageProvider->get($declaringPropertyClass);
                $declaringPropertyStorage = $declaringClassStorage->properties[$propertyName];

                if ($declaringPropertyStorage->referencingLocations === null) {
                    $declaringPropertyStorage->referencingLocations = [];
                }

                $declaringPropertyStorage->referencingLocations[$codeLocation->filePath][] = $codeLocation;
            }

            return true;
        }

        return false;
    }

    /**
     * @param  string $propertyId
     *
     * @return string|null
     */
    public function getDeclaringClassForProperty($propertyId)
    {
        list($fqClassName, $propertyName) = explode('::$', $propertyId);

        $fqClassName = strtolower($fqClassName);

        $classStorage = $this->classlikeStorageProvider->get($fqClassName);

        if (isset($classStorage->declaringPropertyIds[$propertyName])) {
            return $classStorage->declaringPropertyIds[$propertyName];
        }
    }

    /**
     * Get the class this property appears in (vs is declared in, which could give a trait)
     *
     * @param  string $propertyId
     *
     * @return string|null
     */
    public function getAppearingClassForProperty($propertyId)
    {
        list($fqClassName, $propertyName) = explode('::$', $propertyId);

        $fqClassName = strtolower($fqClassName);

        $classStorage = $this->classlikeStorageProvider->get($fqClassName);

        if (isset($classStorage->appearingPropertyIds[$propertyName])) {
            $appearingPropertyId = $classStorage->appearingPropertyIds[$propertyName];

            return explode('::$', $appearingPropertyId)[0];
        }
    }
}
