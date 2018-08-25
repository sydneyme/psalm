<?php
namespace Psalm\Config;

use SimpleXMLElement;

class ErrorLevelFileFilter extends FileFilter
{
    /**
     * @var string
     */
    private $errorLevel = '';

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

        if (isset($e['type'])) {
            $filter->errorLevel = (string) $e['type'];

            if (!in_array($filter->errorLevel, \Psalm\Config::$ERRORLEVELS, true)) {
                throw new \Psalm\Exception\ConfigException('Unexepected error level ' . $filter->errorLevel);
            }
        } else {
            throw new \Psalm\Exception\ConfigException('<type> element expects a level');
        }

        return $filter;
    }

    /**
     * @return string
     */
    public function getErrorLevel()
    {
        return $this->errorLevel;
    }
}
