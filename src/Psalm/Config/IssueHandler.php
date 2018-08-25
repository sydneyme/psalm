<?php
namespace Psalm\Config;

use SimpleXMLElement;

class IssueHandler
{
    /**
     * @var string
     */
    private $errorLevel = \Psalm\Config::REPORT_ERROR;

    /**
     * @var array<ErrorLevelFileFilter>
     */
    private $customLevels = [];

    /**
     * @param  SimpleXMLElement $e
     * @param  string           $baseDir
     *
     * @return self
     */
    public static function loadFromXMLElement(SimpleXMLElement $e, $baseDir)
    {
        $handler = new self();

        if (isset($e['errorLevel'])) {
            $handler->errorLevel = (string) $e['errorLevel'];

            if (!in_array($handler->errorLevel, \Psalm\Config::$ERRORLEVELS, true)) {
                throw new \Psalm\Exception\ConfigException('Unexepected error level ' . $handler->errorLevel);
            }
        }

        /** @var \SimpleXMLElement $errorLevel */
        foreach ($e->errorLevel as $errorLevel) {
            $handler->customLevels[] = ErrorLevelFileFilter::loadFromXMLElement($errorLevel, $baseDir, true);
        }

        return $handler;
    }

    /**
     * @param string $errorLevel
     *
     * @return void
     */
    public function setErrorLevel($errorLevel)
    {
        if (!in_array($errorLevel, \Psalm\Config::$ERRORLEVELS, true)) {
            throw new \Psalm\Exception\ConfigException('Unexepected error level ' . $errorLevel);
        }

        $this->errorLevel = $errorLevel;
    }

    /**
     * @param string $filePath
     *
     * @return string
     */
    public function getReportingLevelForFile($filePath)
    {
        foreach ($this->customLevels as $customLevel) {
            if ($customLevel->allows($filePath)) {
                return $customLevel->getErrorLevel();
            }
        }

        return $this->errorLevel;
    }

    /**
     * @param string $fqClasslikeName
     *
     * @return string
     */
    public function getReportingLevelForClass($fqClasslikeName)
    {
        foreach ($this->customLevels as $customLevel) {
            if ($customLevel->allowsClass($fqClasslikeName)) {
                return $customLevel->getErrorLevel();
            }
        }

        return $this->errorLevel;
    }

    /**
     * @param string $methodId
     *
     * @return string
     */
    public function getReportingLevelForMethod($methodId)
    {
        foreach ($this->customLevels as $customLevel) {
            if ($customLevel->allowsMethod(strtolower($methodId))) {
                return $customLevel->getErrorLevel();
            }
        }

        return $this->errorLevel;
    }

    /**
     * @param string $propertyId
     *
     * @return string
     */
    public function getReportingLevelForProperty($propertyId)
    {
        foreach ($this->customLevels as $customLevel) {
            if ($customLevel->allowsProperty($propertyId)) {
                return $customLevel->getErrorLevel();
            }
        }

        return $this->errorLevel;
    }
}
