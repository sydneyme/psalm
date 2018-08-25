<?php

/**
 * @param  string $currentDir
 * @param  bool   $hasExplicitRoot
 * @param  string $vendorDir
 *
 * @psalm-suppress MixedInferred
 *
 * @return \Composer\Autoload\ClassLoader
 */
function requireAutoloaders($currentDir, $hasExplicitRoot, $vendorDir)
{
    $autoloadRoots = [$currentDir];

    $psalmDir = dirname(__DIR__);

    if (realpath($psalmDir) !== realpath($currentDir)) {
        $autoloadRoots[] = $psalmDir;
    }

    $autoloadFiles = [];

    foreach ($autoloadRoots as $autoloadRoot) {
        $hasAutoloader = false;

        $nestedAutoloadFile = dirname(dirname($autoloadRoot)) . DIRECTORY_SEPARATOR . 'autoload.php';

        // note: don't realpath $nestedAutoloadFile, or phar version will fail
        if (file_exists($nestedAutoloadFile)) {
            if (!in_array($nestedAutoloadFile, $autoloadFiles, false)) {
                $autoloadFiles[] = $nestedAutoloadFile;
            }
            $hasAutoloader = true;
        }

        $vendorAutoloadFile =
            $autoloadRoot . DIRECTORY_SEPARATOR . $vendorDir . DIRECTORY_SEPARATOR . 'autoload.php';

        // note: don't realpath $vendorAutoloadFile, or phar version will fail
        if (file_exists($vendorAutoloadFile)) {
            if (!in_array($vendorAutoloadFile, $autoloadFiles, false)) {
                $autoloadFiles[] = $vendorAutoloadFile;
            }
            $hasAutoloader = true;
        }

        if (!$hasAutoloader && file_exists($autoloadRoot . '/composer.json')) {
            $errorMessage = 'Could not find any composer autoloaders in ' . $autoloadRoot;

            if (!$hasExplicitRoot) {
                $errorMessage .= PHP_EOL . 'Add a --root=[your/project/directory] flag '
                    . 'to specify a particular project to run Psalm on.';
            }

            echo $errorMessage . PHP_EOL;
            exit(1);
        }
    }

    $firstAutoloader = null;

    foreach ($autoloadFiles as $file) {
        /**
         * @psalm-suppress UnresolvableInclude
         * @var \Composer\Autoload\ClassLoader
         */
        $autoloader = require_once $file;

        if (!$firstAutoloader) {
            $firstAutoloader = $autoloader;
        }
    }

    if ($firstAutoloader === null) {
        throw new \LogicException('Cannot be null here');
    }

    define('PSALM_VERSION', (string) \Muglug\PackageVersions\Versions::getVersion('vimeo/psalm'));
    define('PHP_PARSER_VERSION', (string) \Muglug\PackageVersions\Versions::getVersion('nikic/php-parser'));

    return $firstAutoloader;
}

/**
 * @param  string $currentDir
 *
 * @return string
 *
 * @psalm-suppress PossiblyFalseArgument
 * @psalm-suppress MixedArrayAccess
 * @psalm-suppress MixedAssignment
 */
function getVendorDir($currentDir)
{
    $composerJsonPath = $currentDir . DIRECTORY_SEPARATOR . 'composer.json';

    if (!file_exists($composerJsonPath)) {
        return 'vendor';
    }

    if (!$composerJson = json_decode(file_get_contents($composerJsonPath), true)) {
        throw new \UnexpectedValueException('Invalid composer.json at ' . $composerJsonPath);
    }

    if (isset($composerJson['config']['vendor-dir'])) {
        return (string) $composerJson['config']['vendor-dir'];
    }

    return 'vendor';
}

/**
 * @param  string|array|null|false $fPaths
 *
 * @return string[]|null
 */
function getPathsToCheck($fPaths)
{
    global $argv;

    $pathsToCheck = [];

    if ($fPaths) {
        $inputPaths = is_array($fPaths) ? $fPaths : [$fPaths];
    } else {
        $inputPaths = $argv ? $argv : null;
    }

    if ($inputPaths) {
        $filteredInputPaths = [];

        for ($i = 0; $i < count($inputPaths); ++$i) {
            /** @var string */
            $inputPath = $inputPaths[$i];

            if (realpath($inputPath) === realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'psalm')
                || realpath($inputPath) === realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'psalter')
                || realpath($inputPath) === \Phar::running(false)
            ) {
                continue;
            }

            if ($inputPath[0] === '-' && strlen($inputPath) === 2) {
                if ($inputPath[1] === 'c' || $inputPath[1] === 'f') {
                    ++$i;
                }
                continue;
            }

            if ($inputPath[0] === '-' && $inputPath[2] === '=') {
                continue;
            }

            if (substr($inputPath, 0, 2) === '--' && strlen($inputPath) > 2) {
                continue;
            }

            $filteredInputPaths[] = $inputPath;
        }

        if ($filteredInputPaths === ['-']) {
            $meta = stream_get_meta_data(STDIN);
            stream_set_blocking(STDIN, false);
            if ($stdin = fgets(STDIN)) {
                $filteredInputPaths = preg_split('/\s+/', trim($stdin));
            }
            /** @var bool */
            $blocked = $meta['blocked'];
            stream_set_blocking(STDIN, $blocked);
        }

        foreach ($filteredInputPaths as $pathToCheck) {
            if ($pathToCheck[0] === '-') {
                echo 'Invalid usage, expecting psalm [options] [file...]' . PHP_EOL;
                exit(1);
            }

            if (!file_exists($pathToCheck)) {
                echo 'Cannot locate ' . $pathToCheck . PHP_EOL;
                exit(1);
            }

            $pathToCheck = realpath($pathToCheck);

            if (!$pathToCheck) {
                echo 'Error getting realpath for file' . PHP_EOL;
                exit(1);
            }

            $pathsToCheck[] = $pathToCheck;
        }

        if (!$pathsToCheck) {
            $pathsToCheck = null;
        }
    }

    return $pathsToCheck;
}
