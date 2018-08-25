<?php
namespace Psalm\FileManipulation;

class FileManipulationBuffer
{
    /** @var array<string, FileManipulation[]> */
    private static $fileManipulations = [];

    /**
     * @param string $filePath
     * @param FileManipulation[] $fileManipulations
     *
     * @return void
     */
    public static function add($filePath, array $fileManipulations)
    {
        self::$fileManipulations[$filePath] = isset(self::$fileManipulations[$filePath])
            ? array_merge(self::$fileManipulations[$filePath], $fileManipulations)
            : $fileManipulations;
    }

    /**
     * @param string $filePath
     *
     * @return FileManipulation[]
     */
    public static function getForFile($filePath)
    {
        if (!isset(self::$fileManipulations[$filePath])) {
            return [];
        }

        return self::$fileManipulations[$filePath];
    }

    /**
     * @return void
     */
    public static function clearCache()
    {
        self::$fileManipulations = [];
    }
}
