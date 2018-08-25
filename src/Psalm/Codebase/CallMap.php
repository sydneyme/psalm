<?php
namespace Psalm\Codebase;

use Psalm\Type;
use Psalm\Storage\FunctionLikeParameter;

/**
 * @internal
 *
 * Gets values from the call map array, which stores data about native functions and methods
 */
class CallMap
{
    /**
     * @var array<array<string,string>>|null
     */
    private static $callMap = null;

    /**
     * @param  string $functionId
     *
     * @return array|null
     * @psalm-return array<int, array<int, FunctionLikeParameter>>|null
     */
    public static function getParamsFromCallMap($functionId)
    {
        $callMap = self::getCallMap();

        $callMapKey = strtolower($functionId);

        if (!isset($callMap[$callMapKey])) {
            return null;
        }

        $callMapFunctions = [];
        $callMapFunctions[] = $callMap[$callMapKey];

        for ($i = 1; $i < 10; ++$i) {
            if (!isset($callMap[$callMapKey . '\'' . $i])) {
                break;
            }

            $callMapFunctions[] = $callMap[$callMapKey . '\'' . $i];
        }

        $functionTypeOptions = [];

        foreach ($callMapFunctions as $callMapFunctionArgs) {
            array_shift($callMapFunctionArgs);

            $functionTypes = [];

            /** @var string $argName - key type changed with above array_shift */
            foreach ($callMapFunctionArgs as $argName => $argType) {
                $byReference = false;
                $optional = false;
                $variadic = false;

                if ($argName[0] === '&') {
                    $argName = substr($argName, 1);
                    $byReference = true;
                }

                if (substr($argName, -1) === '=') {
                    $argName = substr($argName, 0, -1);
                    $optional = true;
                }

                if (substr($argName, 0, 3) === '...') {
                    $argName = substr($argName, 3);
                    $variadic = true;
                }

                $paramType = $argType
                    ? Type::parseString($argType)
                    : Type::getMixed();

                if ($paramType->hasScalarType() || $paramType->hasObject()) {
                    $paramType->fromDocblock = true;
                }

                $functionTypes[] = new FunctionLikeParameter(
                    $argName,
                    $byReference,
                    $paramType,
                    null,
                    null,
                    $optional,
                    false,
                    $variadic
                );
            }

            $functionTypeOptions[] = $functionTypes;
        }

        return $functionTypeOptions;
    }

    /**
     * @param  string  $functionId
     *
     * @return Type\Union
     */
    public static function getReturnTypeFromCallMap($functionId)
    {
        $callMapKey = strtolower($functionId);

        $callMap = self::getCallMap();

        if (!isset($callMap[$callMapKey])) {
            throw new \InvalidArgumentException('Function ' . $functionId . ' was not found in callmap');
        }

        if (!$callMap[$callMapKey][0]) {
            return Type::getMixed();
        }

        $callMapType = Type::parseString($callMap[$callMapKey][0]);

        if ($callMapType->isNullable()) {
            $callMapType->fromDocblock = true;
        }

        return $callMapType;
    }

    /**
     * Gets the method/function call map
     *
     * @return array<string, array<int|string, string>>
     * @psalm-suppress MixedInferredReturnType as the use of require buggers things up
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedTypeCoercion
     */
    public static function getCallMap()
    {
        if (self::$callMap !== null) {
            return self::$callMap;
        }

        /** @var array<string, array<int|string, string>> */
        $callMap = require_once(__DIR__ . '/../CallMap.php');

        self::$callMap = [];

        foreach ($callMap as $key => $value) {
            $casedKey = strtolower($key);
            self::$callMap[$casedKey] = $value;
        }

        return self::$callMap;
    }

    /**
     * @param   string $key
     *
     * @return  bool
     */
    public static function inCallMap($key)
    {
        return isset(self::getCallMap()[strtolower($key)]);
    }
}
