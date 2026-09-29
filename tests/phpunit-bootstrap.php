<?php

require_once 'vendor/autoload.php';

chdir(getenv('OJS_WEB_BASEDIR'));

require_once './lib/pkp/tests/phpunit-bootstrap.php';

use APP\core\Application;
use APP\core\PageRouter;
use PKP\core\Registry;

$application = Application::get();
$request = $application->getRequest();
$router = new PageRouter();
$router->setApplication($application);
$request->setRouter($router);
Registry::set('request', $request);
