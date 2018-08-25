<?php
namespace Psalm\Checker;

use Psalm\Aliases;
use Psalm\StatementsSource;

abstract class SourceChecker implements StatementsSource
{
    /**
     * @var StatementsSource|null
     */
    protected $source = null;

    /**
     * @return Aliases
     */
    public function getAliases()
    {
        if ($this->source === null) {
            throw new \UnexpectedValueException('$source cannot be null');
        }

        return $this->source->getAliases();
    }

    /**
     * @return array<string, string>
     */
    public function getAliasedClassesFlipped()
    {
        if ($this->source === null) {
            throw new \UnexpectedValueException('$source cannot be null');
        }

        return $this->source->getAliasedClassesFlipped();
    }

    /**
     * @return string|null
     */
    public function getFQCLN()
    {
        if ($this->source === null) {
            throw new \UnexpectedValueException('$source cannot be null');
        }

        return $this->source->getFQCLN();
    }

    /**
     * @return string|null
     */
    public function getClassName()
    {
        if ($this->source === null) {
            throw new \UnexpectedValueException('$source cannot be null');
        }

        return $this->source->getClassName();
    }

    /**
     * @return FileChecker
     */
    abstract public function getFileChecker();

    /**
     * @return string|null
     */
    public function getParentFQCLN()
    {
        if ($this->source === null) {
            throw new \UnexpectedValueException('$source cannot be null');
        }

        return $this->source->getParentFQCLN();
    }

    /**
     * @return string
     */
    public function getFileName()
    {
        if ($this->source === null) {
            throw new \UnexpectedValueException('$source cannot be null');
        }

        return $this->source->getFileName();
    }

    /**
     * @return string
     */
    public function getFilePath()
    {
        if ($this->source === null) {
            throw new \UnexpectedValueException('$source cannot be null');
        }

        return $this->source->getFilePath();
    }

    /**
     * @return string
     */
    public function getRootFileName()
    {
        if ($this->source === null) {
            throw new \UnexpectedValueException('$source cannot be null');
        }

        return $this->source->getRootFileName();
    }

    /**
     * @return string
     */
    public function getRootFilePath()
    {
        if ($this->source === null) {
            throw new \UnexpectedValueException('$source cannot be null');
        }

        return $this->source->getRootFilePath();
    }

    /**
     * @param string $filePath
     * @param string $fileName
     *
     * @return void
     */
    public function setRootFilePath($filePath, $fileName)
    {
        if ($this->source === null) {
            throw new \UnexpectedValueException('$source cannot be null');
        }

        $this->source->setRootFilePath($filePath, $fileName);
    }

    /**
     * @param string $filePath
     *
     * @return bool
     */
    public function hasParentFilePath($filePath)
    {
        if ($this->source === null) {
            throw new \UnexpectedValueException('$source cannot be null');
        }

        return $this->source->hasParentFilePath($filePath);
    }

    /**
     * @param string $filePath
     *
     * @return bool
     */
    public function hasAlreadyRequiredFilePath($filePath)
    {
        if ($this->source === null) {
            throw new \UnexpectedValueException('$source cannot be null');
        }

        return $this->source->hasAlreadyRequiredFilePath($filePath);
    }

    /**
     * @return int
     */
    public function getRequireNesting()
    {
        if ($this->source === null) {
            throw new \UnexpectedValueException('$source cannot be null');
        }

        return $this->source->getRequireNesting();
    }

    /**
     * @return StatementsSource
     */
    public function getSource()
    {
        return $this->source ?: $this;
    }

    /**
     * Get a list of suppressed issues
     *
     * @return array<string>
     */
    public function getSuppressedIssues()
    {
        if ($this->source === null) {
            throw new \UnexpectedValueException('$source cannot be null');
        }

        return $this->source->getSuppressedIssues();
    }

    /**
     * @param array<int, string> $newIssues
     *
     * @return void
     */
    public function addSuppressedIssues(array $newIssues)
    {
        if ($this->source === null) {
            throw new \UnexpectedValueException('$source cannot be null');
        }

        $this->source->addSuppressedIssues($newIssues);
    }

    /**
     * @param array<int, string> $newIssues
     *
     * @return void
     */
    public function removeSuppressedIssues(array $newIssues)
    {
        if ($this->source === null) {
            throw new \UnexpectedValueException('$source cannot be null');
        }

        $this->source->removeSuppressedIssues($newIssues);
    }

    /**
     * @return null|string
     */
    public function getNamespace()
    {
        if ($this->source === null) {
            throw new \UnexpectedValueException('$source cannot be null');
        }

        return $this->source->getNamespace();
    }

    /**
     * @return bool
     */
    public function isStatic()
    {
        if ($this->source === null) {
            throw new \UnexpectedValueException('$source cannot be null');
        }

        return $this->source->isStatic();
    }
}
