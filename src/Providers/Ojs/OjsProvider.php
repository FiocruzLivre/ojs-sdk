<?php

namespace OjsSdk\Providers\Ojs;

use APP\core\Application;
use APP\core\PageRouter;
use PKP\config\Config;
use PKP\core\Registry;
use PKP\plugins\Hook;
use PKP\plugins\PluginRegistry;

class OjsProvider
{
    /**
     * @return Application
     */
    public static function getApplication(string $journal = '')
    {
        if (defined('SESSION_DISABLE_INIT')) {
            return;
        }
        define('SESSION_DISABLE_INIT', true);
        define('INDEX_FILE_LOCATION', getenv('OJS_WEB_BASEDIR') . '/index.php');
        define('ENV_SEPARATOR', strtolower(substr(PHP_OS, 0, 3)) == 'win' ? ';' : ':');
        if (!defined('DIRECTORY_SEPARATOR')) {
            // Older versions of PHP do not define this
            define('DIRECTORY_SEPARATOR', strtolower(substr(PHP_OS, 0, 3)) == 'win' ? '\\' : '/');
        }
        define('BASE_SYS_DIR', dirname(INDEX_FILE_LOCATION));
        $currentDirectory = getcwd();
        chdir(BASE_SYS_DIR);

        $baseDir = getenv('OJS_WEB_BASEDIR');
        if (!$baseDir) {
            throw new \Exception('OJS_WEB_BASEDIR environment variable is not set');
        }

        // Load the Composer autoloader (required for 3.4+; there is no more import()/include_path bootstrap)
        require_once $baseDir . '/lib/pkp/lib/vendor/autoload.php';
        define('PKP_STRICT_MODE', (bool) Config::getVar('general', 'strict'));

        // Initialize the Application
        $application = Application::get();

        // A CLI-bootstrapped Request has no Router yet (nothing set one via an HTTP front
        // controller) - PKPRouter is abstract in 3.4, so it must be a concrete PageRouter.
        if ($application) {
            $request = $application->getRequest();
            $router = new PageRouter();
            $router->setApplication($application);
            $request->setRouter($router);
            Registry::set('request', $request);
        }

        self::setJournal($journal);
        PluginRegistry::loadCategory('generic', true);
        PluginRegistry::loadCategory('pubIds', true);
        chdir($currentDirectory);
        return $application;
    }

    private static function setJournal(string $journal)
    {
        $configData = Registry::get('configData');
        $configData['general']['disable_path_info'] = true;
        Registry::set('configData', $configData);

        Hook::add('Request::getQueryString', function ($hookName, &$args) use ($journal) {
            $args[0] = ($args[0] ? $args[0] . '&' : '') . 'journal=' . $journal;
        });
    }
}
