<?php
require_once('command_functions.php');

use Psalm\Checker\ProjectChecker;
use Psalm\Config;
use Psalm\IssueBuffer;

// show all errors
error_reporting(-1);

$validShortOptions = [
    'f:',
    'm',
    'h',
    'v',
    'c:',
    'i',
    'r:',
];

$validLongOptions = [
    'clear-cache',
    'config:',
    'debug',
    'debug-by-line',
    'diff',
    'disable-extension:',
    'find-dead-code',
    'find-references-to:',
    'help',
    'init',
    'monochrome',
    'no-cache',
    'output-format:',
    'plugin:',
    'report:',
    'root:',
    'show-info:',
    'show-snippet:',
    'stats',
    'threads:',
    'use-ini-defaults',
    'version',
];

$args = array_slice($argv, 1);

array_map(
    /**
     * @param string $arg
     *
     * @return void
     */
    function ($arg) use ($validLongOptions, $validShortOptions) {
        if (substr($arg, 0, 2) === '--' && $arg !== '--') {
            $argName = preg_replace('/=.*$/', '', substr($arg, 2));

            if (!in_array($argName, $validLongOptions) && !in_array($argName . ':', $validLongOptions)) {
                echo 'Unrecognised argument "--' . $argName . '"' . PHP_EOL
                    . 'Type --help to see a list of supported arguments'. PHP_EOL;
                exit(1);
            }
        } elseif (substr($arg, 0, 2) === '-' && $arg !== '-' && $arg !== '--') {
            $argName = preg_replace('/=.*$/', '', substr($arg, 1));

            if (!in_array($argName, $validShortOptions) && !in_array($argName . ':', $validShortOptions)) {
                echo 'Unrecognised argument "-' . $argName . '"' . PHP_EOL
                    . 'Type --help to see a list of supported arguments'. PHP_EOL;
                exit(1);
            }
        }
    },
    $args
);

// get options from command line
$options = getopt(implode('', $validShortOptions), $validLongOptions);

if (!array_key_exists('use-ini-defaults', $options)) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    ini_set('memory_limit', 4 * 1024 * 1024 * 1024);
}

if (array_key_exists('help', $options)) {
    $options['h'] = false;
}

if (array_key_exists('version', $options)) {
    $options['v'] = false;
}

if (array_key_exists('init', $options)) {
    $options['i'] = false;
}

if (array_key_exists('monochrome', $options)) {
    $options['m'] = false;
}

if (isset($options['config'])) {
    $options['c'] = $options['config'];
}

if (isset($options['c']) && is_array($options['c'])) {
    echo 'Too many config files provided' . PHP_EOL;
    exit(1);
}

if (array_key_exists('h', $options)) {
    echo <<<HELP
Usage:
    psalm [options] [file...]

Options:
    -h, --help
        Display this help message

    -v, --version
        Display the Psalm version

    -i, --init [source_dir=src] [level=3]
        Create a psalm config file in the current directory that points to [source_dir]
        at the required level, from 1, most strict, to 8, most permissive.

    --debug
        Debug information

    --debug-by-line
        Debug information on a line-by-line level

    -c, --config=psalm.xml
        Path to a psalm.xml configuration file. Run psalm --init to create one.

    -m, --monochrome
        Enable monochrome output

    -r, --root
        If running Psalm globally you'll need to specify a project root. Defaults to cwd

    --show-info[=BOOLEAN]
        Show non-exception parser findings

    --show-snippet[=true]
        Show code snippets with errors. Options are 'true' or 'false'

    --diff
        Runs Psalm in diff mode, only checking files that have changed (and their dependents)

    --output-format=console
        Changes the output format. Possible values: console, emacs, json, pylint, xml

    --find-dead-code
        Look for dead code

    --find-references-to=[class|method]
        Searches the codebase for references to the given fully-qualified class or method,
        where method is in the format class::methodName

    --threads=INT
        If greater than one, Psalm will run analysis on multiple threads, speeding things up.

    --report=PATH
        The path where to output report file. The output format is base on the file extension.
        (Currently supported format: ".json", ".xml", ".txt")

    --clear-cache
        Clears all cache files that Psalm uses

    --no-cache
        Runs Psalm without using cache

    --plugin=PATH
        Executes a plugin, an alternative to using the Psalm config

    --stats
        Shows a breakdown of Psalm's ability to infer types in the codebase

    --use-ini-defaults
        Use PHP-provided ini defaults for memory and error display

    --disable-extension=[extension]
        Used to disable certain extensions while Psalm is running.

HELP;

    exit;
}

if (getcwd() === false) {
    echo 'Cannot get current working directory' . PHP_EOL;
    exit(1);
}

if (isset($options['root'])) {
    $options['r'] = $options['root'];
}

$currentDir = (string)getcwd() . DIRECTORY_SEPARATOR;

if (isset($options['r']) && is_string($options['r'])) {
    $rootPath = realpath($options['r']);

    if (!$rootPath) {
        echo 'Could not locate root directory ' . $currentDir . DIRECTORY_SEPARATOR . $options['r'] . PHP_EOL;
        exit(1);
    }

    $currentDir = $rootPath . DIRECTORY_SEPARATOR;
}

$vendorDir = getVendorDir($currentDir);

$firstAutoloader = requireAutoloaders($currentDir, isset($options['r']), $vendorDir);

if (array_key_exists('v', $options)) {
    echo 'Psalm ' . PSALM_VERSION . PHP_EOL;
    exit;
}

$threads = isset($options['threads']) ? (int)$options['threads'] : 1;

$iniHandler = new \Psalm\Fork\PsalmRestarter('PSALM');

if (isset($options['disable-extension'])) {
    if (is_array($options['disable-extension'])) {
        /** @psalm-suppress MixedAssignment */
        foreach ($options['disable-extension'] as $extension) {
            if (is_string($extension)) {
                $iniHandler->disableExtension($extension);
            }
        }
    } elseif (is_string($options['disable-extension'])) {
        $iniHandler->disableExtension($options['disable-extension']);
    }
}

if ($threads > 1) {
    $iniHandler->disableExtension('grpc');
}

// If XDebug is enabled, restart without it
$iniHandler->check();

setlocale(LC_CTYPE, 'C');

if (isset($options['i'])) {
    if (file_exists($currentDir . 'psalm.xml')) {
        die('A config file already exists in the current directory' . PHP_EOL);
    }

    $args = array_values(array_filter(
        $args,
        /**
         * @param string $arg
         *
         * @return bool
         */
        function ($arg) {
            return $arg !== '--ansi'
                && $arg !== '--no-ansi'
                && $arg !== '--i'
                && $arg !== '--init'
                && strpos($arg, '--root=') !== 0
                && strpos($arg, '--r=') !== 0;
        }
    ));

    $level = 3;
    $sourceDir = 'src';

    if (count($args)) {
        if (count($args) > 2) {
            die('Too many arguments provided for psalm --init' . PHP_EOL);
        }

        if (isset($args[1])) {
            if (!preg_match('/^[1-8]$/', $args[1])) {
                die('Config strictness must be a number between 1 and 8 inclusive' . PHP_EOL);
            }

            $level = (int)$args[1];
        }

        $sourceDir = $args[0];
    }

    if (!is_dir($sourceDir)) {
        $badDirPath = getcwd() . DIRECTORY_SEPARATOR . $sourceDir;

        if (!isset($args[0])) {
            die('Please specify a directory - the default, "src", was not found in this project.' . PHP_EOL);
        }

        die('The given path "' . $badDirPath . '" does not appear to be a directory' . PHP_EOL);
    }

    $templateFileName = dirname(__DIR__) . '/assets/config_levels/' . $level . '.xml';

    if (!file_exists($templateFileName)) {
        die('Could not open config template ' . $templateFileName . PHP_EOL);
    }

    $template = (string)file_get_contents($templateFileName);

    $template = str_replace(
        '<directory name="src" />',
        '<directory name="' . $sourceDir . '" />',
        $template
    );

    if (!\Phar::running(false)) {
        $template = str_replace(
            'vendor/vimeo/psalm/config.xsd',
            'file://' . realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config.xsd'),
            $template
        );
    }

    if (!file_put_contents($currentDir . 'psalm.xml', $template)) {
        die('Could not write to psalm.xml' . PHP_EOL);
    }

    exit('Config file created successfully. Please re-run psalm.' . PHP_EOL);
}

$outputFormat = isset($options['output-format']) && is_string($options['output-format'])
    ? $options['output-format']
    : ProjectChecker::TYPE_CONSOLE;

$pathsToCheck = getPathsToCheck(isset($options['f']) ? $options['f'] : null);

$plugins = [];

if (isset($options['plugin'])) {
    $plugins = $options['plugin'];

    if (!is_array($plugins)) {
        $plugins = [$plugins];
    }
}

$pathToConfig = isset($options['c']) && is_string($options['c']) ? realpath($options['c']) : null;

if ($pathToConfig === false) {
    /** @psalm-suppress InvalidCast */
    echo 'Could not resolve path to config ' . (string)$options['c'] . PHP_EOL;
    exit(1);
}

$showInfo = isset($options['show-info'])
    ? $options['show-info'] !== 'false' && $options['show-info'] !== '0'
    : true;

$isDiff = isset($options['diff']);

$findDeadCode = isset($options['find-dead-code']);

$findReferencesTo = isset($options['find-references-to']) && is_string($options['find-references-to'])
    ? $options['find-references-to']
    : null;

$cacheProvider = isset($options['no-cache'])
    ? new Psalm\Provider\NoCache\NoParserCacheProvider()
    : new Psalm\Provider\ParserCacheProvider();

// initialise custom config, if passed
try {
    if ($pathToConfig) {
        $config = Config::loadFromXMLFile($pathToConfig, $currentDir);
    } else {
        $config = Config::getConfigForPath($currentDir, $currentDir, $outputFormat);
    }
} catch (Psalm\Exception\ConfigException $e) {
    echo $e->getMessage();
    exit(1);
}

$config->setComposerClassLoader($firstAutoloader);

$fileStorageCacheProvider = isset($options['no-cache'])
    ? new Psalm\Provider\NoCache\NoFileStorageCacheProvider()
    : new Psalm\Provider\FileStorageCacheProvider($config);

$classlikeStorageCacheProvider = isset($options['no-cache'])
    ? new Psalm\Provider\NoCache\NoClassLikeStorageCacheProvider()
    : new Psalm\Provider\ClassLikeStorageCacheProvider($config);

if (isset($options['clear-cache'])) {
    $cacheDirectory = $config->getCacheDirectory();

    Config::removeCacheDirectory($cacheDirectory);
    echo 'Cache directory deleted' . PHP_EOL;
    exit;
}

$debug = array_key_exists('debug', $options) || array_key_exists('debug-by-line', $options);

$projectChecker = new ProjectChecker(
    $config,
    new Psalm\Provider\FileProvider(),
    $cacheProvider,
    $fileStorageCacheProvider,
    $classlikeStorageCacheProvider,
    !array_key_exists('m', $options),
    $showInfo,
    $outputFormat,
    $threads,
    $debug,
    isset($options['report']) && is_string($options['report']) ? $options['report'] : null,
    !isset($options['show-snippet']) || $options['show-snippet'] !== "false"
);

$config->visitComposerAutoloadFiles($projectChecker, $debug);

if (array_key_exists('debug-by-line', $options)) {
    $projectChecker->debugLines = true;
}

if ($findDeadCode || $findReferencesTo !== null) {
    $projectChecker->getCodebase()->collectReferences();

    if ($findReferencesTo) {
        $projectChecker->showIssues = false;
    }
}

if ($findDeadCode) {
    $projectChecker->getCodebase()->reportUnusedCode();
}

/** @var string $pluginPath */
foreach ($plugins as $pluginPath) {
    Config::getInstance()->addPluginPath($currentDir . DIRECTORY_SEPARATOR . $pluginPath);
}

$startTime = (float) microtime(true);

if ($pathsToCheck === null) {
    $projectChecker->check($currentDir, $isDiff);
} elseif ($pathsToCheck) {
    $projectChecker->checkPaths($pathsToCheck);
}

if ($findReferencesTo) {
    $projectChecker->findReferencesTo($findReferencesTo);
} elseif ($findDeadCode && !$pathsToCheck && !$isDiff) {
    if ($threads > 1) {
        if ($outputFormat === ProjectChecker::TYPE_CONSOLE) {
            echo 'Unused classes and methods cannot currently be found in multithreaded mode' . PHP_EOL;
        }
    } else {
        $projectChecker->checkClassReferences();
    }
}

IssueBuffer::finish($projectChecker, !$pathsToCheck, $startTime, isset($options['stats']));
