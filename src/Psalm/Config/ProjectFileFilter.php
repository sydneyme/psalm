<?php
namespace Psalm\Config;

use SimpleXMLElement;

class ProjectFileFilter extends FileFilter
{
    /**
     * @var ProjectFileFilter|null
     */
    private $fileFilter = null;

    /**
     * @param  SimpleXMLElement $e
     * @param  string           $baseDir
     * @param  bool             $inclusive
     *
     * @return static
     */
    public static function loadFromXMLElement(
        SimpleXMLElement $e,
        $baseDir,
        $inclusive
    ) {
        $filter = parent::loadFromXMLElement($e, $baseDir, $inclusive);

        if (isset($e->ignoreFiles)) {
            if (!$inclusive) {
                throw new \Psalm\Exception\ConfigException('Cannot nest ignoreFiles inside itself');
            }

            /** @var \SimpleXMLElement $e->ignoreFiles */
            $filter->fileFilter = static::loadFromXMLElement($e->ignoreFiles, $baseDir, false);
        }

        return $filter;
    }

    /**
     * @param  string  $fileName
     * @param  bool $caseSensitive
     *
     * @return bool
     */
    public function allows($fileName, $caseSensitive = false)
    {
        if ($this->inclusive && $this->fileFilter) {
            if (!$this->fileFilter->allows($fileName, $caseSensitive)) {
                return false;
            }
        }

        return parent::allows($fileName, $caseSensitive);
    }

    /**
     * @param  string  $fileName
     * @param  bool $caseSensitive
     *
     * @return bool
     */
    public function forbids($fileName, $caseSensitive = false)
    {
        if ($this->inclusive && $this->fileFilter) {
            if (!$this->fileFilter->allows($fileName, $caseSensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  string $fileName
     * @param  bool   $caseSensitive
     *
     * @return bool
     */
    public function reportTypeStats($fileName, $caseSensitive = false)
    {
        foreach ($this->ignoreTypeStats as $excludeDir => $_) {
            if ($caseSensitive) {
                if (strpos($fileName, $excludeDir) === 0) {
                    return false;
                }
            } else {
                if (stripos($fileName, $excludeDir) === 0) {
                    return false;
                }
            }
        }

        return true;
    }
}
