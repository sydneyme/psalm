<?php
namespace Psalm\Codebase;

class PropertyMap
{
    /**
     * @var array<string, array<string, string>>|null
     */
    private static $propertyMap;

    /**
     * Gets the method/function call map
     *
     * @return array<string, array<string, string>>
     * @psalm-suppress MixedInferredReturnType as the use of require buggers things up
     * @psalm-suppress MixedAssignment
     */
    public static function getPropertyMap()
    {
        if (self::$propertyMap !== null) {
            return self::$propertyMap;
        }

        /** @var array<string, array<string, string>> */
        $propertyMap = require_once(__DIR__ . '/../PropertyMap.php');

        self::$propertyMap = [];

        foreach ($propertyMap as $key => $value) {
            $casedKey = strtolower($key);
            self::$propertyMap[$casedKey] = $value;
        }

        return self::$propertyMap;
    }

    /**
     * @param   string $className
     *
     * @return  bool
     */
    public static function inPropertyMap($className)
    {
        return isset(self::getPropertyMap()[strtolower($className)]);
    }
}
