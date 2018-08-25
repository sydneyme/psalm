<?php
namespace Psalm\Provider;

use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\ProjectChecker;
use Psalm\Config;

/**
 * Used to determine which files reference other files, necessary for using the --diff
 * option from the command line.
 */
class FileReferenceProvider
{
    const REFERENCE_CACHE_NAME = 'references';

    /**
     * A lookup table used for getting all the files that reference a class
     *
     * @var array<string, array<string,bool>>
     */
    protected static $fileReferencesToClass = [];

    /**
     * A lookup table used for getting all the files that reference any other file
     *
     * @var array<string,array<string,bool>>
     */
    protected static $referencingFiles = [];

    /**
     * @var array<string, array<int,string>>
     */
    protected static $filesInheritingClasses = [];

    /**
     * A list of all files deleted since the last successful run
     *
     * @var array<int, string>|null
     */
    protected static $deletedFiles = null;

    /**
     * A lookup table used for getting all the files referenced by a file
     *
     * @var array<string, array{a:array<int, string>, i:array<int, string>}>
     */
    protected static $fileReferences = [];

    /**
     * @return array<string>
     */
    public static function getDeletedReferencedFiles()
    {
        if (self::$deletedFiles === null) {
            self::$deletedFiles = array_filter(
                array_keys(self::$fileReferences),
                /**
                 * @param  string $fileName
                 *
                 * @return bool
                 */
                function ($fileName) {
                    return !file_exists($fileName);
                }
            );
        }

        return self::$deletedFiles;
    }

    /**
     * @param string $sourceFile
     * @param string $fqClassNameLc
     *
     * @return void
     */
    public static function addFileReferenceToClass($sourceFile, $fqClassNameLc)
    {
        self::$referencingFiles[$sourceFile] = true;
        self::$fileReferencesToClass[$fqClassNameLc][$sourceFile] = true;
    }

    /**
     * @return array<string, array<string,bool>>
     */
    public static function getAllFileReferences()
    {
        return self::$fileReferencesToClass;
    }

    /**
     * @param array<string, array<string,bool>> $references
     * @psalm-suppress MixedTypeCoercion
     *
     * @return void
     */
    public static function addFileReferences(array $references)
    {
        self::$fileReferencesToClass = array_merge_recursive($references, self::$fileReferencesToClass);
    }

    /**
     * @param string $sourceFile
     * @param string $fqClassNameLc
     *
     * @return void
     */
    public static function addFileInheritanceToClass($sourceFile, $fqClassNameLc)
    {
        self::$filesInheritingClasses[$fqClassNameLc][$sourceFile] = true;
    }

    /**
     * @param   string $file
     *
     * @return  array
     */
    public static function calculateFilesReferencingFile(ProjectChecker $projectChecker, $file)
    {
        $referencedFiles = [];

        $fileClasses = ClassLikeChecker::getClassesForFile($projectChecker, $file);

        foreach ($fileClasses as $fileClassLc => $_) {
            if (isset(self::$fileReferencesToClass[$fileClassLc])) {
                $referencedFiles = array_merge(
                    $referencedFiles,
                    array_keys(self::$fileReferencesToClass[$fileClassLc])
                );
            }
        }

        return array_unique($referencedFiles);
    }

    /**
     * @param   string $file
     *
     * @return  array
     */
    public static function calculateFilesInheritingFile(ProjectChecker $projectChecker, $file)
    {
        $referencedFiles = [];

        $fileClasses = ClassLikeChecker::getClassesForFile($projectChecker, $file);

        foreach ($fileClasses as $fileClassLc => $_) {
            if (isset(self::$filesInheritingClasses[$fileClassLc])) {
                $referencedFiles = array_merge(
                    $referencedFiles,
                    array_keys(self::$filesInheritingClasses[$fileClassLc])
                );
            }
        }

        return array_unique($referencedFiles);
    }

    /**
     * @return void
     */
    public static function removeDeletedFilesFromReferences()
    {
        $cacheDirectory = Config::getInstance()->getCacheDirectory();

        $deletedFiles = self::getDeletedReferencedFiles();

        if ($deletedFiles) {
            foreach ($deletedFiles as $file) {
                unset(self::$fileReferences[$file]);
            }

            file_put_contents(
                $cacheDirectory . DIRECTORY_SEPARATOR . self::REFERENCE_CACHE_NAME,
                serialize(self::$fileReferences)
            );
        }
    }

    /**
     * @param  string $file
     *
     * @return array<string>
     */
    public static function getFilesReferencingFile($file)
    {
        return isset(self::$fileReferences[$file]['a']) ? self::$fileReferences[$file]['a'] : [];
    }

    /**
     * @param  string $file
     *
     * @return array<string>
     */
    public static function getFilesInheritingFromFile($file)
    {
        return isset(self::$fileReferences[$file]['i']) ? self::$fileReferences[$file]['i'] : [];
    }

    /**
     * @return bool
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedTypeCoercion
     */
    public static function loadReferenceCache()
    {
        $cacheDirectory = Config::getInstance()->getCacheDirectory();

        if ($cacheDirectory) {
            $cacheLocation = $cacheDirectory . DIRECTORY_SEPARATOR . self::REFERENCE_CACHE_NAME;

            if (is_readable($cacheLocation)) {
                $referenceCache = unserialize((string) file_get_contents($cacheLocation));

                if (!is_array($referenceCache)) {
                    throw new \UnexpectedValueException('The reference cache must be an array');
                }

                self::$fileReferences = $referenceCache;

                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, bool>  $visitedFiles
     *
     * @return void
     */
    public static function updateReferenceCache(ProjectChecker $projectChecker, array $visitedFiles)
    {
        $cacheDirectory = Config::getInstance()->getCacheDirectory();

        if ($cacheDirectory) {
            $cacheLocation = $cacheDirectory . DIRECTORY_SEPARATOR . self::REFERENCE_CACHE_NAME;

            foreach ($visitedFiles as $file => $_) {
                $allFileReferences = array_unique(
                    array_merge(
                        isset(self::$fileReferences[$file]['a']) ? self::$fileReferences[$file]['a'] : [],
                        FileReferenceProvider::calculateFilesReferencingFile($projectChecker, $file)
                    )
                );

                $inheritanceReferences = array_unique(
                    array_merge(
                        isset(self::$fileReferences[$file]['i']) ? self::$fileReferences[$file]['i'] : [],
                        FileReferenceProvider::calculateFilesInheritingFile($projectChecker, $file)
                    )
                );

                self::$fileReferences[$file] = [
                    'a' => $allFileReferences,
                    'i' => $inheritanceReferences,
                ];
            }

            file_put_contents($cacheLocation, serialize(self::$fileReferences));
        }
    }
}
