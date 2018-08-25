<?php
namespace Psalm;

use Psalm\Checker\FileChecker;

interface StatementsSource extends FileSource
{
    /**
     * @return null|string
     */
    public function getNamespace();

    /**
     * @return array<string, string>
     */
    public function getAliasedClassesFlipped();

    /**
     * @return string|null
     */
    public function getFQCLN();

    /**
     * @return string|null
     */
    public function getClassName();

    /**
     * @return FileChecker
     */
    public function getFileChecker();

    /**
     * @return string|null
     */
    public function getParentFQCLN();

    /**
     * @param string $filePath
     * @param string $fileName
     *
     * @return void
     */
    public function setRootFilePath($filePath, $fileName);

    /**
     * @param string $filePath
     *
     * @return bool
     */
    public function hasParentFilePath($filePath);

    /**
     * @param string $filePath
     *
     * @return bool
     */
    public function hasAlreadyRequiredFilePath($filePath);

    /**
     * @return int
     */
    public function getRequireNesting();

    /**
     * @return bool
     */
    public function isStatic();

    /**
     * @return StatementsSource|null
     */
    public function getSource();

    /**
     * Get a list of suppressed issues
     *
     * @return array<string>
     */
    public function getSuppressedIssues();

    /**
     * @param array<int, string> $newIssues
     *
     * @return void
     */
    public function addSuppressedIssues(array $newIssues);

    /**
     * @param array<int, string> $newIssues
     *
     * @return void
     */
    public function removeSuppressedIssues(array $newIssues);
}
