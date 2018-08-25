<?php
namespace Psalm\Provider;

class FileProvider
{
    /**
     * @param  string  $filePath
     *
     * @return string
     */
    public function getContents($filePath)
    {
        return (string)file_get_contents($filePath);
    }

    /**
     * @param  string  $filePath
     * @param  string  $fileContents
     *
     * @return void
     */
    public function setContents($filePath, $fileContents)
    {
        file_put_contents($filePath, $fileContents);
    }

    /**
     * @param  string $filePath
     *
     * @return int
     */
    public function getModifiedTime($filePath)
    {
        return (int)filemtime($filePath);
    }

    /**
     * @param  string $filePath
     *
     * @return bool
     */
    public function fileExists($filePath)
    {
        return file_exists($filePath);
    }
}
