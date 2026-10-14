<?php

if (!getenv('OJS_WEB_BASEDIR') && is_dir(dirname(__FILE__).'/../ojs')) {
    putenv('OJS_WEB_BASEDIR='.dirname(__FILE__) . '/../ojs/');
}

if (file_exists(getenv('OJS_WEB_BASEDIR') . '/config.inc.php')) {
    return;
}

set_include_path(getenv('OJS_WEB_BASEDIR') . PATH_SEPARATOR . get_include_path() );

copy(getenv('OJS_WEB_BASEDIR').'/config.TEMPLATE.inc.php', getenv('OJS_WEB_BASEDIR').'/config.inc.php');
require('tools/bootstrap.php');

use APP\core\Application;
use APP\core\Services;
use APP\facades\Repo;
use APP\journal\Journal;
use PKP\cliTool\InstallTool;
use PKP\core\Registry;

class OJSInstallTest extends InstallTool {
    /**
     * Constructor.
     * @param $argv array command-line arguments
     */
    function __construct(array $params) {
        parent::__construct();
        $this->params = $params;
	}
}

$params = [
    'locale' => 'en_US',
    'additionalLocales' => [
        'en_US'
    ],
    'timeZone' => 'UTC',
    'filesDir' => getenv('OJS_WEB_BASEDIR'),
    'adminUsername' => 'admin',
    'adminPassword' => 'admin',
    'adminPassword2' => 'admin',
    'adminEmail' => 'admin@test.coop',
    'databaseDriver' => 'mysqli',
    'databaseHost' => getenv('TEST_DB_HOST') ?: 'db',
    'databasePort' => getenv('TEST_DB_PORT') ?: null,
    'databaseUsername' => 'root',
    'databasePassword' => 'root',
    'databaseName' => 'ojs',
    'createDatabase' => 1,
    'oaiRepositoryId' => 'localhost',
    'enableBeacon' => 1,
    'install' => 1
];

$tool = new OJSInstallTest($params);
$tool->install();

// The installer only sets up the site itself - it does not create a journal.
// Tests need a default context (and its default manager user group), so
// create one here, the same way the admin UI's "create journal" wizard does.
$request = Application::get()->getRequest();
Registry::set('user', Repo::user()->getByUsername($params['adminUsername']));

$journal = new Journal();
$journal->setData('urlPath', 'test');
$journal->setData('primaryLocale', 'en_US');
$journal->setData('supportedLocales', ['en_US']);
$journal->setData('supportedFormLocales', ['en_US']);
$journal->setData('supportedSubmissionLocales', ['en_US']);
$journal->setData('name', 'Test Journal', 'en_US');
$journal->setData('contactName', 'Admin');
$journal->setData('contactEmail', $params['adminEmail']);

Services::get('context')->add($journal, $request);