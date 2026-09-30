# ojs-sdk OJS 3.4 Compatibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `lyseontech/ojs-sdk` (this repo, `FiocruzLivre/ojs-sdk`) boot and operate correctly against OJS 3.4.0-10, so `scielo-scraping` (and any other consumer) can depend on a real, tagged, git-tracked release instead of the untracked hand-patch currently sitting in `scielo-scraping`'s gitignored `vendor/lyseontech/ojs-sdk/`.

**Architecture:** OJS 3.4 removed the legacy global-class/`import()` bootstrap entirely (no `\Application`, `\PKPRequest`, `\PKPRouter`, `\HookRegistry`, `\AppLocale`, `\UserDAO`; no `import()` function at all) in favor of namespaced classes (`APP\core\Application`, `PKP\core\Request`/`Router`/`Registry`, `PKP\plugins\Hook`) reached via `APP\facades\Repo` for entities that got a Repository, or the composer-autoloaded class directly otherwise. This SDK's `OjsProvider::getApplication()` is the single bootstrap entrypoint every consumer calls before touching any OJS class, so it — and everything downstream of it (the SDK's own PHPUnit bootstrap, its one real service class, and that service's test) — has to be rewritten against the namespaced APIs. This is the same class of migration already completed for `scielo-scraping/src/Command/Ojs/ImportCommand.php` (see that repo's `docs/superpowers/plans/2026-09-28-scielo-scraping-ojs-3-4-migration.md`), applied here to the shared SDK it depends on.

**Tech Stack:** PHP 8.2, Guzzle (dev-only, test HTTP mocking), PHPUnit 9, OJS 3.4.0-10 (installed fresh in CI from `pkp/ojs` at tag `3_4_0-10`).

**Spec:** `OJS_OMP_OPS 3.4 Release Notebook` (PKP's official 3.4 migration guide) and the actually-installed OJS 3.4.0-10 source at `/home/liviagouvea/Projetos/ENSP/ensp-csp-3-4/ojs`, which this plan's code was verified against directly (grepping real class/method signatures, not guessing from the release notes alone).

## Global Constraints

- Target OJS version is **3.4.0-10** (PHP 8.0+ required; this plan standardizes on 8.2, matching `scielo-scraping`'s own already-updated Dockerfile).
- This repo has a real PHPUnit suite that runs against a genuinely-installed OJS instance in CI (`.github/workflows/phpunit.yml` clones `pkp/ojs` and runs `tests/setupOjs.php` before `composer unit`) — unlike `scielo-scraping`, tests here are not optional decoration; every task that touches `src/` must keep `tests/` passing against a real, running OJS 3.4 install, not a mock of one.
- Do not reintroduce `import(...)`, or references to `\Application`, `\PKPRequest`, `\PKPRouter`, `\Registry`, `\HookRegistry`, `\AppLocale`, `\Validation`, `\UserDAO`, `\DAORegistry` as bare global classes — none of them exist in OJS 3.4. Use the namespaced equivalents.
- `UserDAO`, `UserGroupDAO` (as standalone DAO classes) do not exist in OJS 3.4 — confirmed by `find` returning nothing under `/ojs`. Use `Repo::user()` / `Repo::userGroup()`.
- The three DAO-internals hook names the current test suite mocks (`userdao::_getuserbyemail`, `userdao::_insertobject`, `usergroupdao::_getbyid`, `UserGroupDAO::_returnFromRow`, `usergroupdao::_useringroup`, `dao::_updatedataobjectsettings`) are gone — the release notebook's "Hooks Removed" section explicitly killed the `*::_fromRow` / `*::getMany::query*` hook family these depend on, and the classes that fired them (`UserGroupDAO`) no longer exist. Do not try to find their "3.4 equivalent" hook names — there isn't one; the whole DAO-internals mocking approach is obsolete (see Task 5).
- Treat `vendor/lyseontech/ojs-sdk/src/Providers/Ojs/OjsProvider.php` inside the `scielo-scraping` checkout as a **reference draft only**, not something to copy verbatim — it was a working-but-unfinished local patch (unused imports, a dead `$context` variable, plugin-category loading commented out with a fabricated third argument rather than fixed or deliberately removed). This plan produces the clean, final version.

## Review Focus

- **Plugin categories silently not loading**: the local hand-patch commented out `PluginRegistry::loadCategory('generic', true); PluginRegistry::loadCategory('pubIds', true);` instead of fixing or deliberately dropping it. `PluginRegistry::loadCategory(string $category, bool $enabledOnly = false, ?int $mainContextId = null): array` is unchanged in 3.4 (verified in `lib/pkp/classes/plugins/PluginRegistry.php:107`) — there is no technical reason the original two-argument calls can't be restored. Leaving them disabled would silently break any SDK consumer that relies on generic or pub-id plugins (DOI registration, citation style, etc.) being loaded after `getApplication()` returns. Task 1 restores them.
- **New required `time_zone` install param**: OJS 3.4 added a required `time_zone` setting (release notebook, "Timezones"), read via `$this->getParam('timeZone')` in `lib/pkp/classes/install/PKPInstall.php:189`. `tests/setupOjs.php`'s install `$params` array has no `timeZone` key today, so a CI-driven install would silently write an empty `time_zone` config value. Task 3 fixes this.
- **Dead install params that look load-bearing but aren't**: `clientCharset`, `connectionCharset`, `databaseCharset` in `tests/setupOjs.php` are not read anywhere in `PKPInstall.php` any more (grepped, zero matches) — the release notebook confirms `client_charset` was removed from `config.inc.php` entirely since only UTF-8 is supported now. Leaving them in isn't harmful, but a reviewer could reasonably assume they still do something; Task 3 removes them so the params array only contains keys OJS 3.4 actually reads.
- **`Repo::author()`/`Repo::user()`/`Repo::galley()`-style `add()` calls don't mutate their argument** — this exact class of bug was already caught once in the `scielo-scraping` migration (`Repo::author()->add()` returning the id rather than mutating the object). `Repo::user()->add($user)` (`lib/pkp/classes/user/Repository.php:105-111`) has the identical shape: it returns `int`, does not set the id back onto `$user`. Task 4 must capture the returned id, not call `$user->getId()` afterward.
- **The rewritten `OJSUserServiceTest` must still prove something real**: given the old DAO-internals hooks it mocked no longer exist, the temptation is to mock so little that the test stops exercising `Repo::user()`/`Repo::userGroup()` at all. Task 5 keeps this an integration-style test against the OJS instance CI actually provisions (as `tests/setupOjs.php` already does), asserting on real inserted/updated rows — not a hollowed-out test that always passes.

---

## File Structure

- **Modify:** `src/Providers/Ojs/OjsProvider.php` — the bootstrap entrypoint every consumer calls (Task 1).
- **Modify:** `tests/phpunit-bootstrap.php` — the test suite's own OJS bootstrap, same class of fix as Task 1 (Task 2).
- **Modify:** `tests/setupOjs.php` — CLI install script CI uses to provision a real OJS 3.4 before running tests (Task 3).
- **Modify:** `src/Services/OJSService/Users/OJSUserService.php` — the SDK's one real service class (Task 4).
- **Modify:** `tests/Services/OJSService/Users/OJSUserServiceTest.php` — rewrite `testCreateUpdateUserReturnTrue()`; `testUserLogin()` is untouched (it only mocks Guzzle, never touches an OJS class) (Task 5).
- **Modify:** `.github/workflows/phpunit.yml` — bump the CI matrix to PHP 8.2 / OJS `3_4_0-10` (Task 6).
- **Modify:** `composer.json` — add an explicit `"php": ">=8.0"` runtime requirement (Task 6).
- **Modify:** `.docker/php7/Dockerfile`, `docker-compose.yml`, `.env.example` — bump local dev environment to PHP 8.2 / OJS 3.4, mirroring the identical fix already applied in `scielo-scraping/.docker/php/Dockerfile` (Task 7).
- No changes needed to `src/Providers/Provider.php` (plain singleton, no OJS classes) or `src/Providers/LogProvider.php` (Monolog/SwiftMailer only, no OJS classes).

---

### Task 1: Rewrite `OjsProvider::getApplication()` for OJS 3.4

**Files:**
- Modify: `src/Providers/Ojs/OjsProvider.php`

**Interfaces:**
- Consumes: nothing (this is the bootstrap root).
- Produces: `OjsProvider::getApplication(string $journal = ''): ?Application` — same public signature as today, still callable exactly as `OjsProvider::getApplication($slug)`. Every later task (and every existing consumer, including `scielo-scraping`) relies on this signature staying stable.

- [ ] **Step 1: Replace the whole file**

Current (`src/Providers/Ojs/OjsProvider.php`, verified against the real `main` branch — this is the OJS-3.3-and-earlier bootstrap, still legacy in every consumer's currently-installed copy):

```php
<?php

namespace OjsSdk\Providers\Ojs;

use PluginRegistry;

class OjsProvider
{
    /**
     * @return \Application
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

        // Update include path - for backwards compatibility only
        // Try to use absolute (/...) or relative (./...) filenames
        // wherever possible to bypass the costly file name normalization
        // process.
        ini_set(
            'include_path',
            ENV_SEPARATOR . BASE_SYS_DIR
            . ENV_SEPARATOR . BASE_SYS_DIR . '/classes'
            . ENV_SEPARATOR . BASE_SYS_DIR . '/pages'
            . ENV_SEPARATOR . BASE_SYS_DIR . '/lib/pkp'
            . ENV_SEPARATOR . BASE_SYS_DIR . '/lib/pkp/classes'
            . ENV_SEPARATOR . BASE_SYS_DIR . '/lib/pkp/pages'
            . ENV_SEPARATOR . BASE_SYS_DIR . '/lib/pkp/lib/adodb'
            . ENV_SEPARATOR . ini_get('include_path')
        );
        // System-wide functions
        require('includes/functions.inc.php');

        // Initialize the application environment
        import('classes.core.Application');
        $application = new \Application();
        import('lib.pkp.classes.core.PKPRequest');
        import('lib.pkp.classes.core.PKPRouter');
        import('lib.pkp.classes.core.Registry');
        $request = new \PKPRequest();
        $router = new \PKPRouter();
        $router->setApplication($application);
        $request->setRouter($router);
        \Registry::set('request', $request);
        \AppLocale::$request = \Registry::get('request', true, null);
        self::setJournal($journal);
        PluginRegistry::loadCategory('generic', true);
        PluginRegistry::loadCategory('pubIds', true);
        chdir($currentDirectory);
        return $application;
    }

    private static function setJournal($journal)
    {
        $configData = \Registry::get('configData');
        $configData['general']['disable_path_info'] = true;
        \Registry::set('configData', $configData);
        import('lib.pkp.classes.plugins.HookRegistry');
        \HookRegistry::register('Request::getQueryString', function ($hook, &$args) use ($journal) {
            $args[0] = ($args[0] ? $args[0] . '&' : '') . 'journal=' . $journal;
        });
    }
}
```

Replace the entire file with:

```php
<?php

namespace OjsSdk\Providers\Ojs;

use APP\core\Application;
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

        // Reuse the Application's own Request/Router instead of constructing new ones
        if ($application) {
            $request = $application->getRequest();
            $router = $request->getRouter();
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
```

Notes on choices made here versus the local hand-patch found in `scielo-scraping`'s vendor copy:
- Dropped the unused `use PKP\core\PKPApplication;` import (never referenced) and the unused `$context = $request->getContext();` line (computed and discarded; also unreliable that early since the `journal` query-string hook isn't registered yet).
- Restored `PluginRegistry::loadCategory('generic', true); PluginRegistry::loadCategory('pubIds', true);` as plain two-argument calls (see Review Focus) instead of leaving them commented out.
- `PluginRegistry::loadCategory` is imported directly now (`use PKP\plugins\PluginRegistry;`) since it's actually called, unlike the hand-patch which imported it but left the calls commented out.

- [ ] **Step 2: Verify syntax**

Run: `php -l src/Providers/Ojs/OjsProvider.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/Providers/Ojs/OjsProvider.php
git commit -m "fix: rewrite OjsProvider bootstrap for OJS 3.4 namespaces"
```

---

### Task 2: Fix the PHPUnit bootstrap for OJS 3.4

**Files:**
- Modify: `tests/phpunit-bootstrap.php`

**Interfaces:**
- Consumes: nothing (loaded once, by `phpunit.xml.dist`'s `bootstrap="./tests/phpunit-bootstrap.php"`, before any test class).
- Produces: a booted OJS `Request`/`Router`/`Registry`, matching what `OjsProvider::getApplication()` now sets up in Task 1, so tests execute inside the same runtime state a real consumer would create.

This file duplicates `OjsProvider`'s old bootstrap logic (not by calling it — it runs standalone, before the SDK's own autoloaded classes are even relevant) and needs the identical class of fix.

- [ ] **Step 1: Replace the file**

Current (`tests/phpunit-bootstrap.php`):

```php
<?php

require_once 'vendor/autoload.php';

chdir(getenv('OJS_WEB_BASEDIR'));

require_once './lib/pkp/tests/phpunit-bootstrap.php';

import('lib.pkp.classes.core.PKPRequest');
import('lib.pkp.classes.core.PKPRouter');
import('lib.pkp.classes.core.Registry');
$request = new \PKPRequest();
$router = new \PKPRouter();
$router->setApplication(\PKPApplication::get());
$request->setRouter($router);
\Registry::set('request', $request);
\AppLocale::$request = \Registry::get('request', true, null);
```

Replace with:

```php
<?php

require_once 'vendor/autoload.php';

chdir(getenv('OJS_WEB_BASEDIR'));

require_once './lib/pkp/tests/phpunit-bootstrap.php';

use APP\core\Application;
use PKP\core\Registry;

$application = Application::get();
$request = $application->getRequest();
$router = $request->getRouter();
$router->setApplication($application);
$request->setRouter($router);
Registry::set('request', $request);
```

`./lib/pkp/tests/phpunit-bootstrap.php` itself is OJS's own file (not this SDK's) and already targets 3.4 — it's what loads OJS's Composer autoloader and base test scaffolding, so by the time this file's own code runs, `APP\core\Application` and `PKP\core\Registry` are already available.

- [ ] **Step 2: Verify syntax**

Run: `php -l tests/phpunit-bootstrap.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add tests/phpunit-bootstrap.php
git commit -m "fix: rewrite PHPUnit bootstrap for OJS 3.4 namespaces"
```

---

### Task 3: Fix the CI install script for OJS 3.4

**Files:**
- Modify: `tests/setupOjs.php`

**Interfaces:**
- Consumes: `PKP\cliTool\InstallTool` (namespace confirmed at `lib/pkp/classes/cliTool/InstallTool.php:17`, extending `PKP\cliTool\CommandLineTool`, unchanged shape: public `$params` property, `execute()` method).
- Produces: a real, installed OJS 3.4 database + `config.inc.php`, which every later test (Task 5) runs against.

- [ ] **Step 1: Replace the file**

Current (`tests/setupOjs.php`):

```php
<?php

if (!getenv('OJS_WEB_BASEDIR') && is_dir(dirname(__FILE__).'/../ojs')) {
    putenv('OJS_WEB_BASEDIR='.dirname(__FILE__) . '/../ojs/');
}

if (file_exists(getenv('OJS_WEB_BASEDIR') . '/config.inc.php')) {
    return;
}

set_include_path(getenv('OJS_WEB_BASEDIR') . PATH_SEPARATOR . get_include_path() );

copy(getenv('OJS_WEB_BASEDIR').'/config.TEMPLATE.inc.php', getenv('OJS_WEB_BASEDIR').'/config.inc.php');
require('tools/bootstrap.inc.php');

import('lib.pkp.classes.cliTool.InstallTool');

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
    'clientCharset' => 'utf-8',
    'connectionCharset' => 'utf8',
    'databaseCharset' => 'utf8',
    'filesDir' => '/app/ojs/',
    'adminUsername' => 'admin',
    'adminPassword' => 'admin',
    'adminPassword2' => 'admin',
    'adminEmail' => 'admin@test.coop',
    'databaseDriver' => 'mysqli',
    'databaseHost' => 'db',
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
```

Replace with:

```php
<?php

if (!getenv('OJS_WEB_BASEDIR') && is_dir(dirname(__FILE__).'/../ojs')) {
    putenv('OJS_WEB_BASEDIR='.dirname(__FILE__) . '/../ojs/');
}

if (file_exists(getenv('OJS_WEB_BASEDIR') . '/config.inc.php')) {
    return;
}

set_include_path(getenv('OJS_WEB_BASEDIR') . PATH_SEPARATOR . get_include_path() );

copy(getenv('OJS_WEB_BASEDIR').'/config.TEMPLATE.inc.php', getenv('OJS_WEB_BASEDIR').'/config.inc.php');
require('tools/bootstrap.inc.php');

use PKP\cliTool\InstallTool;

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
    'filesDir' => '/app/ojs/',
    'adminUsername' => 'admin',
    'adminPassword' => 'admin',
    'adminPassword2' => 'admin',
    'adminEmail' => 'admin@test.coop',
    'databaseDriver' => 'mysqli',
    'databaseHost' => 'db',
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
```

Changes: `import('lib.pkp.classes.cliTool.InstallTool');` → `use PKP\cliTool\InstallTool;` (the `import()` function does not exist in OJS 3.4 at all — this line would fatal on the very first CI run otherwise). Added `'timeZone' => 'UTC'` (see Review Focus — this is a genuinely new required param, not a rename of an existing one). Removed `'clientCharset'`, `'connectionCharset'`, `'databaseCharset'` — confirmed by grep that `PKPInstall.php` no longer reads any of these three keys.

- [ ] **Step 2: Verify syntax**

Run: `php -l tests/setupOjs.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add tests/setupOjs.php
git commit -m "fix: install script targets OJS 3.4 (namespaced InstallTool, timeZone param)"
```

---

### Task 4: Migrate `OJSUserService` off removed DAOs

**Files:**
- Modify: `src/Services/OJSService/Users/OJSUserService.php`

**Interfaces:**
- Consumes: `Repo::user()` (`PKP\facades\Repo::user(): PKP\user\Repository`, methods `getByEmail`, `getByUsername`, `add`, `edit` verified at `lib/pkp/classes/user/Repository.php`), `Repo::userGroup()` (methods `userInGroup`, `assignUserToGroup` verified at `lib/pkp/classes/userGroup/Repository.php`), `PKP\security\Validation::encryptCredentials()` (unchanged signature, verified at `lib/pkp/classes/security/Validation.php:285`).
- Produces: `createUpdateUser(array $data): bool`, `changePassword(string $email, string $password): void`, `getUniqueUsername(string $username): string`, `login($username, $password): array` — all four keep their existing public signatures; `login()`'s body is untouched (it never touches an OJS class, only Guzzle).

`UserDAO` and `UserGroupDAO` do not exist as classes in OJS 3.4 (confirmed: `find . -iname UserDAO.php` under `/ojs` returns nothing). `Repo::user()` and `Repo::userGroup()` replace them.

- [ ] **Step 1: Replace the use-statement block and `createUpdateUser()`**

Current (top of file):

```php
<?php

namespace OjsSdk\Services\OJSService\Users;

use GuzzleHttp\Cookie\CookieJar;
use DAORegistry;
use OjsSdk\Providers\Ojs\OjsProvider;
```

Replace with:

```php
<?php

namespace OjsSdk\Services\OJSService\Users;

use APP\facades\Repo;
use GuzzleHttp\Cookie\CookieJar;
use OjsSdk\Providers\Ojs\OjsProvider;
use PKP\security\Validation;
```

Current `createUpdateUser()`:

```php
    public function createUpdateUser(array $data)
    {
        try {
            OjsProvider::getApplication();
            /** @var UserDAO */
            $userDao = DAORegistry::getDAO('UserDAO');
            $user = $userDao->getUserByEmail($data['email']);
            if (is_null($user)) {
                $user = $userDao->newDataObject();
                $user->setAllData($data);
                $user->setPassword(\Validation::encryptCredentials($user->getUsername(), $data['password']));
                $userId = $userDao->insertObject($user);
                /** @var UserGroupDAO */
                $userGroupDao = DAORegistry::getDAO('UserGroupDAO');
                foreach ($data['groups'] as $groupId) {
                    if (!$userGroupDao->userInGroup($userId, $groupId)) {
                        $userGroupDao->assignUserToGroup($userId, $groupId);
                    }
                }
            } else {
                $id = $user->getId();
                $user->setAllData($data);
                $user->setId($id);
                $userDao->updateObject($user);
            }
        } catch (\Exception $e) {
            throw new \Exception("Error creating OJS user: $e");
        }
         return true;
    }
```

Replace with:

```php
    public function createUpdateUser(array $data)
    {
        try {
            OjsProvider::getApplication();
            $user = Repo::user()->getByEmail($data['email']);
            if (is_null($user)) {
                $user = Repo::user()->newDataObject();
                $user->setAllData($data);
                $user->setPassword(Validation::encryptCredentials($user->getUsername(), $data['password']));
                $userId = Repo::user()->add($user);
                foreach ($data['groups'] as $groupId) {
                    if (!Repo::userGroup()->userInGroup($userId, $groupId)) {
                        Repo::userGroup()->assignUserToGroup($userId, $groupId);
                    }
                }
            } else {
                $id = $user->getId();
                $user->setAllData($data);
                $user->setId($id);
                Repo::user()->edit($user, []);
            }
        } catch (\Exception $e) {
            throw new \Exception("Error creating OJS user: $e");
        }
         return true;
    }
```

Note `$userId = Repo::user()->add($user);` (see Review Focus) — `add()` returns the new id directly; `$user` itself is never mutated by it, which is exactly why the group-assignment loop uses `$userId`, not `$user->getId()`.

- [ ] **Step 2: Replace `changePassword()` and `getUniqueUsername()`**

Current:

```php
    public function changePassword(string $email, string $password)
    {
        OjsProvider::getApplication();
        $userDao = DAORegistry::getDAO('UserDAO');
        $user = $userDao->getUserByEmail($email);
        if ($user) {
            $user->setPassword(\Validation::encryptCredentials($user->getUsername(), $password));
            $userDao->updateObject($user);
        }
    }
    public function getUniqueUsername(string $username)
    {
        OjsProvider::getApplication();
        $userDao = DAORegistry::getDAO('UserDAO');
        $i = '';
        while ($userDao->userExistsByUsername($username . $i)) {
            if ($i === '') {
                $i = 0;
            } else {
                $i++;
            }
        }
        return $username . $i;
    }
```

Replace with:

```php
    public function changePassword(string $email, string $password)
    {
        OjsProvider::getApplication();
        $user = Repo::user()->getByEmail($email);
        if ($user) {
            $user->setPassword(Validation::encryptCredentials($user->getUsername(), $password));
            Repo::user()->edit($user, []);
        }
    }
    public function getUniqueUsername(string $username)
    {
        OjsProvider::getApplication();
        $i = '';
        while (Repo::user()->getByUsername($username . $i)) {
            if ($i === '') {
                $i = 0;
            } else {
                $i++;
            }
        }
        return $username . $i;
    }
```

`UserDAO::userExistsByUsername()` had no direct replacement method, but `Repo::user()->getByUsername($username)` (returns `?User`) gives the identical truthy/falsy check the `while` loop needs.

- [ ] **Step 3: Verify syntax**

Run: `php -l src/Services/OJSService/Users/OJSUserService.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add src/Services/OJSService/Users/OJSUserService.php
git commit -m "fix: migrate OJSUserService off removed UserDAO/UserGroupDAO to Repo facade"
```

---

### Task 5: Rewrite `OJSUserServiceTest::testCreateUpdateUserReturnTrue()` against a real OJS 3.4 instance

**Files:**
- Modify: `tests/Services/OJSService/Users/OJSUserServiceTest.php`

**Interfaces:**
- Consumes: the `OJSUserService` from Task 4; the real installed OJS 3.4 database that `tests/setupOjs.php` (Task 3) provisions in CI before PHPUnit runs; `Repo::user()->getByUsername()` and `Repo::userGroup()->getByRoleIds()` (verified: `PKP\userGroup\Repository::getByRoleIds(array $roleIds, int $contextId, ?bool $default = null): LazyCollection`, same method already used in `scielo-scraping`'s `ImportCommand::getAuthorGroup()`) for assertions and test setup.
- Produces: no change to the test class name or `testUserLogin()`.

The old test intercepted six different private DAO-internals hooks (`userdao::_getuserbyemail`, `userdao::_insertobject`, `dao::_updatedataobjectsettings`, `usergroupdao::_getbyid`, `UserGroupDAO::_returnFromRow`, `usergroupdao::_useringroup`) to avoid ever touching a real database. None of those hook names exist in OJS 3.4 — `UserGroupDAO` itself doesn't exist, and the release notebook's "Hooks Removed" section explicitly removed the `*::_fromRow` / `*::getMany::query*` family these depend on. There is no direct replacement hook to swap them for; the underlying extension points were deleted, not renamed. Given CI already installs a real OJS 3.4 + MySQL before running PHPUnit (Task 3), the correct fix is to let this test hit that real, freshly-installed database and assert on the actual row it creates — this is also a strictly better regression check for exactly the kind of "does this still work against a live OJS" question this whole migration is trying to answer.

- [ ] **Step 1: Replace `testCreateUpdateUserReturnTrue()`**

Current:

```php
    public function testCreateUpdateUserReturnTrue()
    {
        $OjsClient = new OJSUserService();

        HookRegistry::clear('userdao::_getuserbyemail');
        HookRegistry::register('userdao::_getuserbyemail', function($hookName, $args) {
            $args[2] = new ADORecordSet_empty();
            return true;
        });

        HookRegistry::clear('userdao::_insertobject');
        HookRegistry::register('userdao::_insertobject', function($hookName, $args) {
            $args[2] = true;
            return true;
        });

        HookRegistry::clear('dao::_updatedataobjectsettings');
        HookRegistry::register('dao::_updatedataobjectsettings', function($hookName, $args) {
            $args[2] = true;
            return true;
        });

        HookRegistry::clear('usergroupdao::_getbyid');
        HookRegistry::register('usergroupdao::_getbyid', function($hookName, $args) {
            $args[2] = new ADORecordSet_array();
            $args[2]->_numOfRows = 1;
            $args[2]->_currentRow= 0;
            $args[2]->fields = $args[2]->bind = [
                'user_group_id' => 1,
                'role_id' => 1,
                'context_id' => 1,
                'is_default' => 1,
                'show_title' => 1,
                'permit_self_registration' => 1,
                'permit_metadata_edit' => 1
            ];
            return true;
        });

        HookRegistry::clear('UserGroupDAO::_returnFromRow');
        HookRegistry::register('UserGroupDAO::_returnFromRow', function($hookName, $args) {
            return true;
        });

        HookRegistry::clear('usergroupdao::_useringroup');
        HookRegistry::register('usergroupdao::_useringroup', function($hookName, $args) {
            $args[2] = new ADORecordSet_array();
            $args[2]->fields[0] = 1;
            return true;
        });
        $return = $OjsClient->createUpdateUser([
            'username' => 'jhon',
            'password' => 'test',
            'email' => 'jhon.doe@test.coop',
            'mailingAddress' => 'Street 55',
            'locales' => ['en_US'],
            'groups' => [1],// administrator
            'givenName'     => ['en_US' => 'Jhon'],
            'familyName'    => ['en_US' => 'Doe'],
            'phone'         => '+123456789',
            'lattes'        => 'https://lattes.com'
        ]);
        $this->assertTrue($return);
    }
```

Replace with:

```php
    public function testCreateUpdateUserReturnTrue()
    {
        $OjsClient = new OJSUserService();
        $username = 'jhon' . uniqid();
        $email = $username . '@test.coop';

        $administratorGroup = Repo::userGroup()
            ->getByRoleIds([Role::ROLE_ID_MANAGER], 1, true)
            ->first();
        $this->assertNotNull($administratorGroup, 'Fixture OJS install must have a default manager user group for context 1');

        $return = $OjsClient->createUpdateUser([
            'username' => $username,
            'password' => 'test',
            'email' => $email,
            'mailingAddress' => 'Street 55',
            'locales' => ['en_US'],
            'groups' => [$administratorGroup->getId()],
            'givenName'     => ['en_US' => 'Jhon'],
            'familyName'    => ['en_US' => 'Doe'],
            'phone'         => '+123456789',
            'lattes'        => 'https://lattes.com'
        ]);
        $this->assertTrue($return);

        $createdUser = Repo::user()->getByUsername($username);
        $this->assertNotNull($createdUser);
        $this->assertSame($email, $createdUser->getEmail());
        $this->assertTrue(Repo::userGroup()->userInGroup($createdUser->getId(), $administratorGroup->getId()));

        // Calling again with the same email should update, not duplicate
        $return = $OjsClient->createUpdateUser([
            'username' => $username,
            'password' => 'test',
            'email' => $email,
            'mailingAddress' => 'Street 56',
            'locales' => ['en_US'],
            'groups' => [$administratorGroup->getId()],
            'givenName'     => ['en_US' => 'Jhon'],
            'familyName'    => ['en_US' => 'Doe'],
            'phone'         => '+123456789',
            'lattes'        => 'https://lattes.com'
        ]);
        $this->assertTrue($return);
        $this->assertSame($email, Repo::user()->getByUsername($username)->getEmail());
    }
```

Add the two new `use` statements this needs at the top of the file:

```php
use APP\facades\Repo;
use PKP\security\Role;
```

A fixture OJS install (the one `tests/setupOjs.php` creates) always has context id `1` with at least a manager user group marked default (`is_default = 1`), because `PKPInstall` creates the site's first journal/press and its default role-based user groups as part of installation — this mirrors the same `Repo::userGroup()->getByRoleIds([...], $contextId, true)->first()` pattern already used and verified in `scielo-scraping`'s `ImportCommand::getAuthorGroup()`. `Role::ROLE_ID_MANAGER` (rather than `ROLE_ID_AUTHOR`) is used here because `groups' => [1]` in the original test comment said `// administrator`, and the manager role is OJS's context-level "administrator" role; if your fixture install's default group for role `1` in context `1` doesn't match, adjust the role constant to whatever `Repo::userGroup()->getCollector()->filterByContextIds([1])->getMany()` actually returns in your CI install — the point of this test is to prove it against your real fixture data, not to hardcode a number that happens to work by coincidence.

`testUserLogin()` is unchanged — leave it exactly as-is.

- [ ] **Step 2: Verify syntax**

Run: `php -l tests/Services/OJSService/Users/OJSUserServiceTest.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Run the test against a real OJS 3.4 install**

This cannot be verified with `php -l` alone — it needs the actual CI-equivalent environment. Run (from the repo root, after Tasks 1–4 are committed):

```bash
docker-compose up -d db
docker-compose run --rm php7 composer install
php tests/setupOjs.php
docker-compose run --rm php7 composer unit
```

Expected: `OK` from PHPUnit, both `testUserLogin` and `testCreateUpdateUserReturnTrue` passing, no fatal errors about undefined classes/functions.

- [ ] **Step 4: Commit**

```bash
git add tests/Services/OJSService/Users/OJSUserServiceTest.php
git commit -m "test: rewrite OJSUserServiceTest against real OJS 3.4 instead of removed DAO hooks"
```

---

### Task 6: Bump CI matrix and composer.json for OJS 3.4 / PHP 8.2

**Files:**
- Modify: `.github/workflows/phpunit.yml`
- Modify: `composer.json`

**Interfaces:** none (CI/tooling config only).

- [ ] **Step 1: Bump the PHPUnit job matrix**

Current (`.github/workflows/phpunit.yml`):

```yaml
    strategy:
      # do not stop on another job's failure
      fail-fast: false
      matrix:
        php-versions: ['7.4']
        databases: ['mysql']
        server-versions: ['3_2_1-1']
```

Replace with:

```yaml
    strategy:
      # do not stop on another job's failure
      fail-fast: false
      matrix:
        php-versions: ['8.2']
        databases: ['mysql']
        server-versions: ['3_4_0-10']
```

- [ ] **Step 2: Bump the php-cs-fixer job matrix**

Current:

```yaml
    strategy:
      # do not stop on another job's failure
      fail-fast: false
      matrix:
        php-versions: ['7.4']
        server-versions: ['3_2_1-1']
```

Replace with:

```yaml
    strategy:
      # do not stop on another job's failure
      fail-fast: false
      matrix:
        php-versions: ['8.2']
        server-versions: ['3_4_0-10']
```

- [ ] **Step 3: Update the workflow's extensions list to match what OJS 3.4 needs**

Current (`Set up php` step, in the `phpunit` job):

```yaml
      - name: Set up php ${{ matrix.php-versions }}
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php-versions }}
          extensions: gettext, intl, mysql, pdo_mysql
```

No change needed here — `gettext, intl, mysql, pdo_mysql` are still exactly what OJS needs on the `mysqli`/`postgres9`-only driver set 3.4 requires. Leave as-is; this step is listed only so whoever executes this task confirms it rather than assuming it needs a change too.

- [ ] **Step 4: Add an explicit PHP floor to `composer.json`**

Current (`composer.json`, no `"require"` key exists today):

```json
    "minimum-stability": "stable",
    "prefer-stable": true,
    "require-dev": {
        "guzzlehttp/guzzle": "^6.4",
        "squizlabs/php_codesniffer": "^3.5"
    },
```

Replace with:

```json
    "minimum-stability": "stable",
    "prefer-stable": true,
    "require": {
        "php": ">=8.0"
    },
    "require-dev": {
        "guzzlehttp/guzzle": "^6.4",
        "squizlabs/php_codesniffer": "^3.5"
    },
```

- [ ] **Step 5: Verify composer.json is valid**

Run: `composer validate --no-check-all`
Expected: `./composer.json is valid`

- [ ] **Step 6: Commit**

```bash
git add .github/workflows/phpunit.yml composer.json
git commit -m "ci: target PHP 8.2 and OJS 3.4.0-10"
```

---

### Task 7: Bump the local dev Docker environment to PHP 8.2 / OJS 3.4

**Files:**
- Modify: `.docker/php7/Dockerfile`
- Modify: `docker-compose.yml`
- Modify: `.env.example`

**Interfaces:** none (local dev tooling only).

This mirrors the identical, already-verified-working fix applied in `scielo-scraping/.docker/php/Dockerfile` in the sibling monorepo (`FROM php:7.4-fpm` → `FROM php:8.2-fpm`, `xdebug-2.9.8` → `xdebug-3.2.2`).

- [ ] **Step 1: Bump the Dockerfile base image and xdebug version**

Current (`.docker/php7/Dockerfile`):

```dockerfile
FROM php:7.4-fpm
```

Replace with:

```dockerfile
FROM php:8.2-fpm
```

Current:

```dockerfile
RUN pecl install xdebug-2.9.8 \
    && docker-php-ext-enable xdebug
```

Replace with:

```dockerfile
RUN pecl install xdebug-3.2.2 \
    && docker-php-ext-enable xdebug
```

- [ ] **Step 2: Update the OJS version pin in `docker-compose.yml`**

Current (`docker-compose.yml`):

```yaml
    environment:
      XDEBUG_CONFIG: "remote_host=172.17.0.1 remote_enable=1"
      OJS_VERSION: stable-3_1_2
```

Replace with:

```yaml
    environment:
      XDEBUG_CONFIG: "remote_host=172.17.0.1 remote_enable=1"
      OJS_VERSION: 3_4_0-10
```

- [ ] **Step 3: Update `.env.example`**

Current:

```
OJS_VERSION=3_2_1-1
DB_PASSWD=root
OJS_WEB_BASEDIR=/app/ojs
OJS_LOGIN_URL=http://localhost/ojs/index.php/journal/login/signIn
```

Replace with:

```
OJS_VERSION=3_4_0-10
DB_PASSWD=root
OJS_WEB_BASEDIR=/app/ojs
OJS_LOGIN_URL=http://localhost/ojs/index.php/journal/login/signIn
```

- [ ] **Step 4: Commit**

```bash
git add .docker/php7/Dockerfile docker-compose.yml .env.example
git commit -m "chore: bump local dev environment to PHP 8.2 / OJS 3.4.0-10"
```

---

### Task 8: Full verification and tag a real release

**Files:** none (verification and release only).

- [ ] **Step 1: Style check**

Run: `composer cs:check`
Expected: no PSR-12 violations in `src/`.

- [ ] **Step 2: Full local test run**

Run (fresh, from a clean `vendor/` and no pre-existing `ojs/` checkout, to prove this isn't relying on any leftover local state):

```bash
rm -rf ojs vendor
composer install
git clone --branch 3_4_0-10 --depth 1 https://github.com/pkp/ojs.git ojs
composer --working-dir=ojs/lib/pkp install
composer --working-dir=ojs/plugins/paymethod/paypal install
composer --working-dir=ojs/plugins/generic/citationStyleLanguage install
OJS_WEB_BASEDIR=$(pwd)/ojs php tests/setupOjs.php
composer unit
```

Expected: `OK`, no errors, both `OJSUserServiceTest` tests pass.

- [ ] **Step 3: Push and confirm CI is green**

```bash
git push origin ojs-3.4
```

Expected: the `phpunit` and `php-cs-fixer` GitHub Actions jobs both pass on this branch.

- [ ] **Step 4: Cut a real release**

Once CI is green and this has been exercised by `scielo-scraping` pointing its own `composer.json` at this branch/commit (via a VCS repository entry, temporarily) to confirm `ImportCommand.php`'s actual import flow still works end-to-end: open a PR into `main`, merge it, and tag a new version (e.g. `v0.2.0`, since this is a breaking rewrite of the bootstrap, not a patch). Only then does `scielo-scraping/composer.json`'s `"lyseontech/ojs-sdk": "^0.1.4"` constraint need to change to point at the new version — do not do that until this release actually exists on Packagist, or `composer update` will simply reintroduce the exact breakage this plan fixes.

---

## Post-implementation findings (Task 8 actually run)

Tasks 1–7 were implemented as written above, then verified for real: rather than a fresh `pkp/ojs` clone, the suite was run inside the monorepo's already-running `scielo` container against the real, already-installed OJS 3.4.0-10 at `ensp-csp-3-4/ojs` (copy the repo in, symlink `ojs -> /ojs`, `composer install --prefer-source`, `OJS_WEB_BASEDIR=/ojs composer unit`). This caught four real bugs the plan's static analysis missed — all now fixed in the working tree, described here so the plan stays an accurate record of what actually shipped, not just what was originally designed:

1. **`$request->getRouter()` returns null, not a router to reuse.** Task 1/2's `OjsProvider.php` and `tests/phpunit-bootstrap.php` originally did `$router = $request->getRouter(); $router->setApplication($application);` — copied from the local hand-patch this plan set out to replace, and never actually tested against a real instance. `PKPRouter` is `abstract` in 3.4 (`lib/pkp/classes/core/PKPRouter.php:69`), and a CLI-bootstrapped `Request` has never had a router attached (nothing ran the HTTP front controller that normally does that), so `getRouter()` legitimately returns `null`, and the next line fatals with `Call to a member function setApplication() on null`. **Fix:** construct a concrete `APP\core\PageRouter` instead (`$router = new PageRouter();`) — same no-arg constructor and `setApplication()` the old 3.3 code's `new \PKPRouter()` used, just the 3.4 concrete subclass. Both files were corrected.
2. **Two incompatible Guzzle major versions loaded in one process.** `testUserLogin` failed with `Call to undefined method GuzzleHttp\Utils::defaultUserAgent()`. OJS 3.4 bundles Guzzle 7.x internally (via Laravel) for the first time — OJS 3.2/3.3 shipped no Guzzle at all, so this class of conflict didn't exist before. With ojs-sdk's own `require-dev` pinned to `^6.4`, two separately-composer-installed copies of `guzzlehttp/guzzle` end up loaded in the same PHPUnit process (one via `ojs/lib/pkp/lib/vendor/phpunit/phpunit/phpunit`'s own bootstrap, one via this repo's `vendor/autoload.php`), and whichever copy's classes/procedural-function-shims win the autoload race don't match the other copy's method surface. **Fix:** bumped `guzzlehttp/guzzle` to `^7.0` in `composer.json` (Guzzle 6→7 is a non-breaking upgrade for this repo's simple `Client`/`MockHandler`/`HandlerStack`/`CookieJar` usage) — this makes both loaded copies the same generation, so it no longer matters which one wins.
3. **`createUpdateUser()` silently never wrote `username` to the database — and was hashing the password with a null username.** `testCreateUpdateUserReturnTrue` then failed with a real `SQLSTATE` error: `Field 'username' doesn't have a default value`. Root cause, found by reading `PKP\user\DAO::$primaryTableColumns`: the schema property is `userName` (camelCase), not `username`. `$user->setAllData($data)` does a **wholesale replace** of the object's internal data array with whatever keys the caller passed — and this SDK's own public API (and its test, matching the README's documented usage) has always used the lowercase key `'username'`. That mismatch meant the username was silently dropped on every call, in both the create and update branches — and, worse, `$user->getUsername()` (called immediately after `setAllData()` to salt the password hash) was returning `null` the entire time. This is a **pre-existing bug**, not something the 3.4 migration introduced — it was invisible before only because the old test mocked away every DAO call and never executed real SQL. **Fix:** added an explicit `$user->setUsername($data['username']);` after `setAllData()` in both branches of `createUpdateUser()`.
4. **`date_registered` is `NOT NULL` with no default, and nothing ever set it.** Same test, next SQL error after fixing #3. OJS's own registration paths (`RegistrationForm`, `CreateReviewerForm`, `UserDetailsForm`) all call `$user->setDateRegistered(Core::getCurrentDate());` when creating a user — `OJSUserService::createUpdateUser()` never did. **Fix:** added the same call for new users.

Also found and fixed while auditing dependencies during this pass: `composer audit` flagged `squizlabs/php_codesniffer` (the existing `^3.5` dev-dependency) for `CVE-2026-67434` (high-severity OS command injection, affects `<3.13.6`). Bumped to `^3.13.6`.

Final state, verified inside the real OJS 3.4 instance: `composer unit` → `OK (2 tests, 8 assertions)`; `vendor/bin/phpcs --standard=PSR12 -n src` → clean; `composer audit` → no advisories.

`composer.lock` was regenerated by the `guzzlehttp/guzzle` and `squizlabs/php_codesniffer` updates above and is part of this branch's changes.

## Further findings (Task 9, real consumer end-to-end)

The four fixes above made `composer unit` pass, but PHPUnit's own bootstrap (`./lib/pkp/tests/phpunit-bootstrap.php`) constructs a real `Application` and wires up locale/DB state itself, before any test runs. That papered over two more bugs in `OjsProvider::getApplication()` that only surfaced once it was actually exercised as a real consumer would: `scielo-scraping`'s `ojs:import` command, wired to this branch via a temporary Composer `path` repository and run against the real, already-installed OJS 3.4.0-10 in the `ensp-csp-3-4` monorepo (not a fresh install).

5. **`Application::get()` returns `null` in a bare CLI process, and the code silently tolerated it.** It's `Registry::get('application')` - a plain read, no lazy construction. An HTTP request gets one because `index.php` constructs it; nothing does that here. The `if ($application) { ... }` guard around router setup made this worse, not better: it silently skipped router setup instead of failing loudly, so the null only surfaced several lines later at an unguarded call inside `PluginRegistry::_loadFromDatabase()`, as `Call to a member function getEnabledProducts() on null` - a confusing error pointing at the wrong place entirely. **Fix:** construct `new Application()` when `Application::get()` returns null, matching what the old 3.3 code's `new \Application()` actually did.
6. **The Composer autoloader is not the same thing as OJS's bootstrap.** `lib/pkp/includes/bootstrap.php` requires exactly two files: the autoloader, and `lib/pkp/includes/functions.php` - a global-scope helpers file (`strtolower_codesafe()` and others) that `PKP\db\DAO` and other core classes call unqualified, assuming it's already loaded. `OjsProvider` only ever did the first. Missing the second didn't produce a normal PHP error: the `Application` constructor resets `display_errors` from the site's `config.inc.php` before the crash point, so the process just died (exit 255, zero output) with nothing to go on. The real error was only recoverable via a `register_shutdown_function` reading `error_get_last()`. **Fix:** added the missing `require_once $baseDir . '/lib/pkp/includes/functions.php';` right after the autoloader require.

With both fixes in place, `bin/scielo ojs:import csp --ojs-basedir=/ojs --import-without-keywords --import-without-binaries` ran to completion against the real database: exit code 0, and a direct query afterward confirmed real `submissions`/`publications` rows with `status = 3` (published) and the correct `date_published`. Running it a second time correctly skipped every article as already-imported, confirming the idempotency guard still works through this whole rewrite. (`--import-without-binaries` means the Galley/SubmissionFile *creation* path was exercised, but not against real downloaded PDFs/HTML - that would need a `scielo:download-binary` run against the live SciELO site first, not done here.)
