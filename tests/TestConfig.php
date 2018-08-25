<?php
namespace Psalm\Tests;

use Psalm\Config;

class TestConfig extends Config
{
    /**
     * @psalm-suppress PossiblyNullPropertyAssignmentValue because cache_directory isn't strictly nullable
     */
    public function __construct()
    {
        parent::__construct();

        $this->throwException = true;
        $this->useDocblockTypes = true;
        $this->totallyTyped = true;
        $this->cacheDirectory = null;

        $this->baseDir = getcwd() . DIRECTORY_SEPARATOR;

        $this->projectFiles = new Config\ProjectFileFilter(true);
        $this->projectFiles->addDirectory($this->baseDir . 'src');

        $this->collectPredefinedConstants();
        $this->collectPredefinedFunctions();
    }

    public function getComposerFilePathForClassLike($fqClasslikeName)
    {
        return false;
    }
}
