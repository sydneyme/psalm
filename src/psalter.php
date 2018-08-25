<?php
require_once('command_functions.php');

use Psalm\Checker\ProjectChecker;
use Psalm\Config;
use Psalm\IssueBuffer;

// show all errors
error_reporting(-1);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('memory_limit', '2048M');

// get options from command line
$options = getopt(
    'f:mhr:',
    [
        'help', 'debug', 'config:', 'file:', 'root:',
        'plugin:', 'issues:', 'php-version:', 'dry-run', 'safe-types',
    ]
);

if (array_key_exists('help', $options)) {
    $options['h'] = false;
}

if (array_key_exists('monochrome', $options)) {
    $options['m'] = false;
}

if (isset($options['config'])) {
    $options['c'] = $options['config'];
}

if (isset($options['c']) && is_array($options['c'])) {
    die('Too many config files provided' . PHP_EOL);
}

if (array_key_exists('h', $options)) {
    echo <<< HELP
Usage:
    psalm [options] [file...]

Options:
    -h, --help
        Display this help message

    --debug
        Debug information

    -c, --config=psalm.xml
        Path to a psalm.xml configuration file. Run psalm --init to create one.

    -m, --monochrome
        Enable monochrome output

    -r, --root
        If running Psalm globally you'll need to specify a project root. Defaults to cwd

    --plugin=PATH
        Executes a plugin, an alternative to using the Psalm config

    --dry-run
        Shows a diff of all the changes, without making them

    --safe-types
        Only update PHP types when the new type information comes from other PHP types,
        as opposed to type information that just comes from docblocks

    --php-version=PHP_MAJOR_VERSION.PHP_MINOR_VERSION

    --issues=IssueType1,IssueType2
        If any issues can be fixed automatically, Psalm will update the codebase

HELP;

    exit;
}

if (!isset($options['issues']) && (!isset($options['plugin']) || $options['plugin'] === false)) {
    die('Please specify the issues you want to fix with --issues=IssueOne,IssueTwo '
        . 'or provide a plugin that has its own manipulations with --plugin=path/to/plugin.php' . PHP_EOL);
}

if (isset($options['root'])) {
    $options['r'] = $options['root'];
}

$currentDir = (string)getcwd() . DIRECTORY_SEPARATOR;

if (isset($options['r']) && is_string($options['r'])) {
    $rootPath = realpath($options['r']);

    if (!$rootPath) {
        die('Could not locate root directory ' . $currentDir . DIRECTORY_SEPARATOR . $options['r'] . PHP_EOL);
    }

    $currentDir = $rootPath . DIRECTORY_SEPARATOR;
}

$vendorDir = getVendorDir($currentDir);

$firstAutoloader = requireAutoloaders($currentDir, isset($options['r']), $vendorDir);

// If XDebug is enabled, restart without it
(new \Composer\XdebugHandler\XdebugHandler('PSALTER'))->check();

$pathsToCheck = getPathsToCheck(isset($options['f']) ? $options['f'] : null);

if ($pathsToCheck && count($pathsToCheck) > 1) {
    die('Psalter can currently only be run on one path at a time' . PHP_EOL);
}

$pathToConfig = isset($options['c']) && is_string($options['c']) ? realpath($options['c']) : null;

if ($pathToConfig === false) {
    /** @psalm-suppress InvalidCast */
    die('Could not resolve path to config ' . (string)$options['c'] . PHP_EOL);
}

// initialise custom config, if passed
if ($pathToConfig) {
    $config = Config::loadFromXMLFile($pathToConfig, $currentDir);
} else {
    $config = Config::getConfigForPath($currentDir, $currentDir, ProjectChecker::TYPE_CONSOLE);
}

$config->setComposerClassLoader($firstAutoloader);

$projectChecker = new ProjectChecker(
    $config,
    new Psalm\Provider\FileProvider(),
    new Psalm\Provider\ParserCacheProvider(),
    new Psalm\Provider\FileStorageCacheProvider($config),
    new Psalm\Provider\ClassLikeStorageCacheProvider($config),
    !array_key_exists('m', $options),
    false,
    ProjectChecker::TYPE_CONSOLE,
    1,
    array_key_exists('debug', $options)
);

$config->visitComposerAutoloadFiles($projectChecker);

if (array_key_exists('issues', $options)) {
    if (!is_string($options['issues']) || !$options['issues']) {
        die('Expecting a comma-separated list of issues' . PHP_EOL);
    }

    $issues = explode(',', $options['issues']);

    $keyedIssues = [];

    foreach ($issues as $issue) {
        $keyedIssues[$issue] = true;
    }
} else {
    $keyedIssues = [];
}

$phpMajorVersion = PHP_MAJOR_VERSION;
$phpMinorVersion = PHP_MINOR_VERSION;

if (isset($options['php-version'])) {
    if (!is_string($options['php-version']) || !preg_match('/^(5\.[456]|7\.[012])$/', $options['php-version'])) {
        die('Expecting a version number in the format x.y' . PHP_EOL);
    }

    list($phpMajorVersion, $phpMinorVersion) = explode('.', $options['php-version']);
}

$plugins = [];

if (isset($options['plugin'])) {
    $plugins = $options['plugin'];

    if (!is_array($plugins)) {
        $plugins = [$plugins];
    }
}

/** @var string $pluginPath */
foreach ($plugins as $pluginPath) {
    Config::getInstance()->addPluginPath($currentDir . DIRECTORY_SEPARATOR . $pluginPath);
}

$projectChecker->alterCodeAfterCompletion(
    (int) $phpMajorVersion,
    (int) $phpMinorVersion,
    array_key_exists('dry-run', $options),
    array_key_exists('safe-types', $options)
);
$projectChecker->setIssuesToFix($keyedIssues);

$startTime = (float) microtime(true);

if ($pathsToCheck === null) {
    $projectChecker->check($currentDir);
} elseif ($pathsToCheck) {
    foreach ($pathsToCheck as $pathToCheck) {
        if (is_dir($pathToCheck)) {
            $projectChecker->checkDir($pathToCheck);
        } else {
            $projectChecker->checkFile($pathToCheck);
        }
    }
}

IssueBuffer::finish($projectChecker, false, $startTime);
