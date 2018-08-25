<?php
namespace Psalm\Config;

use SimpleXMLElement;

class FileFilter
{
    /**
     * @var array<string>
     */
    protected $directories = [];

    /**
     * @var array<string>
     */
    protected $files = [];

    /**
     * @var array<string>
     */
    protected $fqClasslikeNames = [];

    /**
     * @var array<string>
     */
    protected $methodIds = [];

    /**
     * @var array<string>
     */
    protected $propertyIds = [];

    /**
     * @var array<string>
     */
    protected $filesLowercase = [];

    /**
     * @var bool
     */
    protected $inclusive;

    /**
     * @var array<string, bool>
     */
    protected $ignoreTypeStats = [];

    /**
     * @param  bool             $inclusive
     *
     * @psalm-suppress DocblockTypeContradiction
     */
    public function __construct($inclusive)
    {
        if (!is_bool($inclusive)) {
            throw new \InvalidArgumentException('Filter arg must be bool');
        }

        $this->inclusive = $inclusive;
    }

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
        $filter = new static($inclusive);

        if ($e->directory) {
            /** @var \SimpleXMLElement $directory */
            foreach ($e->directory as $directory) {
                $directoryPath = (string) $directory['name'];
                $ignoreTypeStats = strtolower(
                    isset($directory['ignoreTypeStats']) ? (string) $directory['ignoreTypeStats'] : ''
                ) === 'true';

                if ($directoryPath[0] === '/' && DIRECTORY_SEPARATOR === '/') {
                    $prospectiveDirectoryPath = $directoryPath;
                } else {
                    $prospectiveDirectoryPath = $baseDir . DIRECTORY_SEPARATOR . $directoryPath;
                }

                if (strpos($prospectiveDirectoryPath, '*') !== false) {
                    $globs = array_map(
                        'realpath',
                        array_filter(
                            glob($prospectiveDirectoryPath),
                            'is_dir'
                        )
                    );

                    if (empty($globs)) {
                        echo 'Could not resolve config path to ' . $baseDir . DIRECTORY_SEPARATOR .
                            (string)$directory['name'] . PHP_EOL;
                        exit(1);
                    }

                    foreach ($globs as $globIndex => $directoryPath) {
                        if (!$directoryPath) {
                            echo 'Could not resolve config path to ' . $baseDir . DIRECTORY_SEPARATOR .
                                (string)$directory['name'] . ':' . $globIndex . PHP_EOL;
                            exit(1);
                        }

                        if ($ignoreTypeStats && $filter instanceof ProjectFileFilter) {
                            $filter->ignoreTypeStats[$directoryPath] = true;
                        }

                        $filter->addDirectory($directoryPath);
                    }
                    continue;
                }

                $directoryPath = realpath($prospectiveDirectoryPath);

                if (!$directoryPath) {
                    echo 'Could not resolve config path to ' . $baseDir . DIRECTORY_SEPARATOR .
                        (string)$directory['name'] . PHP_EOL;
                    exit(1);
                }

                if ($ignoreTypeStats && $filter instanceof ProjectFileFilter) {
                    $filter->ignoreTypeStats[$directoryPath] = true;
                }

                $filter->addDirectory($directoryPath);
            }
        }

        if ($e->file) {
            /** @var \SimpleXMLElement $file */
            foreach ($e->file as $file) {
                $filePath = (string) $file['name'];

                if ($filePath[0] === '/' && DIRECTORY_SEPARATOR === '/') {
                    $prospectiveFilePath = $filePath;
                } else {
                    $prospectiveFilePath = $baseDir . DIRECTORY_SEPARATOR . $filePath;
                }

                if (strpos($prospectiveFilePath, '*') !== false) {
                    $globs = array_map(
                        'realpath',
                        array_filter(
                            glob($prospectiveFilePath),
                            'file_exists'
                        )
                    );

                    if (empty($globs)) {
                        echo 'Could not resolve config path to ' . $baseDir . DIRECTORY_SEPARATOR .
                            (string)$file['name'] . PHP_EOL;
                        exit(1);
                    }

                    foreach ($globs as $globIndex => $filePath) {
                        if (!$filePath) {
                            echo 'Could not resolve config path to ' . $baseDir . DIRECTORY_SEPARATOR .
                                (string)$file['name'] . ':' . $globIndex . PHP_EOL;
                            exit(1);
                        }
                        $filter->addFile($filePath);
                    }
                    continue;
                }

                $filePath = realpath($prospectiveFilePath);

                if (!$filePath) {
                    echo 'Could not resolve config path to ' . $baseDir . DIRECTORY_SEPARATOR .
                        (string)$file['name'] . PHP_EOL;
                    exit(1);
                }

                $filter->addFile($filePath);
            }
        }

        if ($e->referencedClass) {
            /** @var \SimpleXMLElement $referencedClass */
            foreach ($e->referencedClass as $referencedClass) {
                $filter->fqClasslikeNames[] = strtolower((string)$referencedClass['name']);
            }
        }

        if ($e->referencedMethod) {
            /** @var \SimpleXMLElement $referencedMethod */
            foreach ($e->referencedMethod as $referencedMethod) {
                $filter->methodIds[] = strtolower((string)$referencedMethod['name']);
            }
        }

        if ($e->referencedFunction) {
            /** @var \SimpleXMLElement $referencedFunction */
            foreach ($e->referencedFunction as $referencedFunction) {
                $filter->methodIds[] = strtolower((string)$referencedFunction['name']);
            }
        }

        if ($e->referencedProperty) {
            /** @var \SimpleXMLElement $referencedProperty */
            foreach ($e->referencedProperty as $referencedProperty) {
                $filter->propertyIds[] = strtolower((string)$referencedProperty['name']);
            }
        }

        return $filter;
    }

    /**
     * @param  string $str
     *
     * @return string
     */
    protected static function slashify($str)
    {
        return preg_replace('/\/?$/', DIRECTORY_SEPARATOR, $str);
    }

    /**
     * @param  string  $fileName
     * @param  bool $caseSensitive
     *
     * @return bool
     */
    public function allows($fileName, $caseSensitive = false)
    {
        if ($this->inclusive) {
            foreach ($this->directories as $includeDir) {
                if ($caseSensitive) {
                    if (strpos($fileName, $includeDir) === 0) {
                        return true;
                    }
                } else {
                    if (stripos($fileName, $includeDir) === 0) {
                        return true;
                    }
                }
            }

            if ($caseSensitive) {
                if (in_array($fileName, $this->files, true)) {
                    return true;
                }
            } else {
                if (in_array(strtolower($fileName), $this->filesLowercase, true)) {
                    return true;
                }
            }

            return false;
        }

        // exclusive
        foreach ($this->directories as $excludeDir) {
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

        if ($caseSensitive) {
            if (in_array($fileName, $this->files, true)) {
                return false;
            }
        } else {
            if (in_array(strtolower($fileName), $this->filesLowercase, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  string  $fqClasslikeName
     *
     * @return bool
     */
    public function allowsClass($fqClasslikeName)
    {
        return in_array(strtolower($fqClasslikeName), $this->fqClasslikeNames);
    }

    /**
     * @param  string  $methodId
     *
     * @return bool
     */
    public function allowsMethod($methodId)
    {
        return in_array($methodId, $this->methodIds);
    }

    /**
     * @param  string  $propertyId
     *
     * @return bool
     */
    public function allowsProperty($propertyId)
    {
        return in_array(strtolower($propertyId), $this->propertyIds);
    }

    /**
     * @return array<string>
     */
    public function getDirectories()
    {
        return $this->directories;
    }

    /**
     * @return array<string>
     */
    public function getFiles()
    {
        return $this->files;
    }

    /**
     * @param   string $fileName
     *
     * @return  void
     */
    public function addFile($fileName)
    {
        $this->files[] = $fileName;
        $this->filesLowercase[] = strtolower($fileName);
    }

    /**
     * @param string $dirName
     *
     * @return void
     */
    public function addDirectory($dirName)
    {
        $this->directories[] = self::slashify($dirName);
    }
}
