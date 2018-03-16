<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */

// enable profiling if xhprof is present
extension_loaded('xhprof') && xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);

use Zend\Loader\AutoloaderFactory;
use Zend\Mvc\Application;

define('BASE_PATH', dirname(__DIR__));

// allow BASE_DATA_PATH to be overridden via an environment variable
define(
    'BASE_DATA_PATH',
    getenv('SWARM_DATA_PATH') ? rtrim(getenv('SWARM_DATA_PATH'), '/\\') : BASE_PATH . '/data'
);

// Try to run the application and catch the errors and run the sanityCheck on the errors.
try {
    // detect a multi-p4-server setup and select which one to use
    require_once __DIR__ . '/../module/Application/SwarmFunctions.php';
    \Application\SwarmFunctions::configureEnvironment(BASE_DATA_PATH);

    // The build will generate swarm_class_map.php. It can be generated directly
    // with 'ant generate-classmap'.
    if (file_exists(BASE_PATH . '/library/Zend/swarm_class_map.php')) {
        // setup mapped autoloading
        require_once __DIR__ . '/../library/Zend/Loader/ClassMapAutoloader.php';
        $loader = new Zend\Loader\ClassMapAutoloader();

        // Register the class map:
        $loader->registerAutoloadMap(BASE_PATH . '/library/Zend/swarm_class_map.php');

        // Register with spl_autoload:
        $loader->register();
    } else {
        // setup expensive autoloading
        set_include_path(BASE_PATH);
        include 'library/Zend/Loader/AutoloaderFactory.php';
        AutoloaderFactory::factory(
            array(
                'Zend\Loader\StandardAutoloader' => array(
                    'namespaces' => array(
                        'P4' => BASE_PATH . '/library/P4',
                        'Record' => BASE_PATH . '/library/Record',
                        'Zend' => BASE_PATH . '/library/Zend'
                    )
                )
            )
        );
    }

    // ensure strict and notice is disabled; otherwise keep the existing levels
    error_reporting(error_reporting() & ~(E_STRICT | E_NOTICE));

    // configure and run the application
    Application::init(
        array(
            'modules' => array_map(
                'basename',
                array_map('dirname', glob(BASE_PATH . '/module/*/Module.php'))
            ),
            'module_listener_options' => array(
                'module_paths' => array(BASE_PATH . '/module'),
                'config_glob_paths' => array(BASE_DATA_PATH . '/config.php'),
            ),
        )
    )->run();

    // If we catch any parse or exception errors check to see if we can help advise user what might be the cause.
} catch (ParseError $e) {
    sanityCheck($e);
} catch (Exception $e) {
    sanityCheck($e);
}

// do what we can to report what we can detect might be misconfigured
function sanityCheck($error)
{
    $e = 'htmlspecialchars';

    // if we are in a multi-p4-server setup, the data path might need to be created
    $badP4dId = preg_match('/[^a-z0-9_-]/i', P4_SERVER_ID);
    if (!$badP4dId && !is_dir(DATA_PATH)) {
        @mkdir(DATA_PATH, 0700, true);
    }

    // check what could be misconfigured
    $config   = BASE_DATA_PATH . '/config.php';
    $badPhp   = (!defined('PHP_VERSION_ID') || (PHP_VERSION_ID < 50303));
    $noP4php  = !extension_loaded('perforce');
    $oldP4php = null;
    if (!$noP4php) {
        preg_match_all('/(P4PHP\/.[^\/]*.([^\/]*).[^\/\s]*)/i', substr(P4::identify(), 0), $p4phpYear);
        $oldP4php = intval(substr($p4phpYear[2][0], 0, 4)) < 2016 ? 1 : null;
    }
    $noIconv      = !extension_loaded('iconv');
    $noJson       = !extension_loaded('json');
    $noSession    = !extension_loaded('session');
    $numPhpIssues = $badPhp + $noP4php + $noIconv + $noJson + $noSession + $oldP4php;
    $badDataDir   = !$badP4dId && !is_writeable(DATA_PATH);
    $noConfig     = !file_exists($config);
    $configErrors = $noConfig ? array() : (array) checkConfig($config);
    $threadSafe   = defined('ZEND_THREAD_SAFE') ? ZEND_THREAD_SAFE : false;
    $numIssues    = $numPhpIssues + $badDataDir + $noConfig + $threadSafe + $badP4dId + count($configErrors);

    // if anything is misconfigured, compose error page and then die
    if ($numIssues) {
        $html = '<html><body>'
            . '<h1>Swarm has detected a configuration error</h1>'
            . '<p>Problem' . ($numIssues > 1 ? 's' : '') . ' detected:</p>';

        // compose message per condition
        $html                  .= '<ul>';
        $badPhp       && $html .= '<li>Swarm requires PHP 5.3.3 or higher; you have ' . $e(PHP_VERSION) . '.</li>';
        $noP4php      && $html .= '<li>The Perforce PHP extension (P4PHP) is not installed or enabled.</li>';
        $oldP4php     && $html .= '<li>The Perforce PHP extension (P4PHP) requires upgrading.</li>';
        $noIconv      && $html .= '<li>The iconv PHP extension is not installed or enabled.</li>';
        $noJson       && $html .= '<li>The json PHP extension is not installed or enabled.</li>';
        $noSession    && $html .= '<li>The session PHP extension is not installed or enabled.</li>';
        $badDataDir   && $html .= '<li>The data directory (' . $e(DATA_PATH) . ') is not writeable.</li>';
        $noConfig     && $html .= '<li>Swarm configuration file does not exist (' . $e($config) . ').</li>';
        $threadSafe   && $html .= '<li>Thread-safe PHP detected -- Swarm does not support running with thread-safe PHP.'
            . ' To remedy, install or rebuild a non-thread-safe variant of PHP and Apache (prefork).</li>';
        $badP4dId     && $html .= '<li>The Perforce server name (' . $e(P4_SERVER_ID) . ') contains invalid characters.'
            . ' Perforce server names may only contain alphanumeric characters, hyphens and underscores.</li>';
        $configErrors && $html .= '<li>' . implode('</li></li>', $configErrors) . '</li>';
        $html                  .= '</ul>';

        // display further information if there were any PHP issues
        if ($numPhpIssues) {
            // tell the user where the php.ini file is
            $php_ini_file = php_ini_loaded_file();
            if ($php_ini_file) {
                $html .= '<p>The php.ini file loaded is ' . $e($php_ini_file) . '.</p>';
            } else {
                $html .= '<p>There is no php.ini loaded (expected to find one in ' . $e(PHP_SYSCONFDIR) . ').</p>';
            }

            // if there are additional php.ini files, list them here
            if (php_ini_scanned_files()) {
                $html .= '<p>Other scanned php.ini files (in ' . $e(PHP_CONFIG_FILE_SCAN_DIR) . ') include:</p>'
                    . '<ul><li>' . implode('</li><li>', explode(",\n", $e(php_ini_scanned_files()))) . '</li></ul>';
            }
        }

        // Check if the user has attempted to access docs but fallen into the error checking.
        $url            = parse_url($_SERVER['REQUEST_URI']);
        $urlFirst       = explode('/', $url['path']);
        $desired_output = $urlFirst[1];

        // Default docs url endpoint.
        $url = '/docs/';

        // If user is on docs or has badly configured the p4 block we should point them to public docs.
        if ($desired_output === 'docs' || in_array("Swarm configuration file contain a p4 block", $configErrors)) {
            $url = 'https://www.perforce.com/perforce/doc.current/manuals/swarm/';
        }

        // Check if there are any other errors that we could help output to the end user.
        if ($error != null) {
            $html .= '<p>Please investigate the below PHP error:</p>'
            . '<pre>' . $error->getMessage() . '</pre>'
            . '<p>'.$error->getFile() . ' on line ' . $error->getLine() . '</p>';
        }

        // wrap it up with links to the docs
        $html .= '<p>For more information, please see the'
            . ' <a href="' . $url . 'chapter.setup.html">Setting Up</a> documentation;'
            . ' in particular:</p>'
            . '<ul>'
            . '<li><a href="' . $url . 'setup.installation.html">Initial Installation</a></li>'
            . '<li><a href="' . $url . 'setup.dependencies.html">Runtime dependencies</a></li>'
            . '<li><a href="' . $url . 'setup.php.html">PHP configuration</a></li>'
            . '<li><a href="' . $url . 'setup.swarm.html">Swarm configuration</a></li>'
            . '</ul>'
            . '<p>Please ensure you restart your web server after making any PHP changes.</p>'
            . '</body></html>';

        // goodbye cruel world

        die($html);
    } else {
        // If no config errors then just output the error that has been given by apache or php.
        $html = '<html><body>'
            . '<h1>Swarm has detected an error</h1>'
            . '<p>Please investigate the below PHP error:</p>'
            . '<pre>' . $error->getMessage() . '</pre>'
            . '<p>'.$error->getFile() . ' on line ' . $error->getLine() . '</p>'
            . '</body></html>';
        die($html);
    }
}

function checkConfig($configPath)
{
    // if config file doesn't exist, just return (we handle that error elsewhere)
    if (!file_exists($configPath)) {
        return null;
    }

    // Check if the config.php has any syntax errors.
    try {
        include $configPath;
    } catch (ParseError $parseE) {
        return array('Swarm configuration file is incorrectly configured');
    }

    // bail early if config is not an array
    $config = include $configPath;
    if (!is_array($config)) {
        return array('Swarm configuration file must return an array.');
    }

    // check if the p4 block has been set.
    if (!isset($config['p4'])) {
        return array('Swarm configuration file contain a p4 block');
    }

    $errors         = array();
    $urlShortLinks  = getConfigValue($config, array('short_links', 'external_url'));
    $urlEnvironment = getConfigValue($config, array('environment', 'external_url'));

    // ensure environment/short-links urls look ok and include valid scheme
    if ($urlEnvironment && !in_array(parse_url($urlEnvironment, PHP_URL_SCHEME), array('http', 'https'))) {
        $errors[] = 'Invalid value in [environment][external_url] config option.';
    }
    if ($urlShortLinks && !in_array(parse_url($urlShortLinks, PHP_URL_SCHEME), array('http', 'https'))) {
        $errors[] = 'Invalid value in [short_links][external_url] config option.';
    }

    // ensure valid short_links configuration
    if (strlen($urlShortLinks) && !$urlEnvironment) {
        $errors[] = 'Config option [environment][external_url] must be set if [short_links][external_url] is set.';
    }

    return $errors;
}

// helper function to return config value for a specified options path
// if config has no value for the given path, return null
function getConfigValue(array $config, array $optionsPath)
{
    $value = $config;
    foreach ($optionsPath as $option) {
        $value = isset($value[$option]) ? $value[$option] : null;
    }

    return $value;
}
