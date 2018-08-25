<?php
namespace Psalm\Tests\Provider;

class FakeFileProvider extends \Psalm\Provider\FileProvider
{
    /**
     * @var array<string, string>
     */
    public $fakeFiles = [];

    /**
     * @var array<string, int>
     */
    public $fakeFileTimes = [];

    /**
     * @param  string $filePath
     *
     * @return bool
     */
    public function fileExists($filePath)
    {
        return isset($this->fakeFiles[$filePath]) || parent::fileExists($filePath);
    }

    /**
     * @param  string $filePath
     *
     * @return string
     */
    public function getContents($filePath)
    {
        if (isset($this->fakeFiles[$filePath])) {
            return $this->fakeFiles[$filePath];
        }

        return parent::getContents($filePath);
    }

    /**
     * @param  string  $filePath
     * @param  string  $fileContents
     *
     * @return void
     */
    public function setContents($filePath, $fileContents)
    {
        $this->fakeFiles[$filePath] = $fileContents;
    }

    /**
     * @param  string $filePath
     *
     * @return int
     */
    public function getModifiedTime($filePath)
    {
        if (isset($this->fakeFileTimes[$filePath])) {
            return $this->fakeFileTimes[$filePath];
        }

        return parent::getModifiedTime($filePath);
    }

    /**
     * @param  string $filePath
     * @param  string $fileContents
     *
     * @return void
     * @psalm-suppress InvalidPropertyAssignmentValue because microtime is needed for cache busting
     */
    public function registerFile($filePath, $fileContents)
    {
        $this->fakeFiles[$filePath] = $fileContents;
        $this->fakeFileTimes[$filePath] = (float) microtime(true);
    }
}
