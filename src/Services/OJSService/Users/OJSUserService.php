<?php

namespace OjsSdk\Services\OJSService\Users;

use APP\facades\Repo;
use GuzzleHttp\Cookie\CookieJar;
use OjsSdk\Providers\Ojs\OjsProvider;
use PKP\core\Core;
use PKP\security\Validation;

class OJSUserService
{
    private $ojsBasePath;
    private $_client;

    public function __construct(\GuzzleHttp\Client $client = null)
    {
        $this->_client = $client;
    }

    /**
     * Login method logs in into OJS
     *
     * Login returns * a session id
     *
     * @param string $username User's username
     * @param string $password User's password
     * @return array [sessionId, userId]
     **/
    public function login($username, $password)
    {
        // This cookiejar is used to capture the OJSSID
        $cookieJar = new CookieJar();
        if (empty($username) || empty($password)) {
            throw new \Exception(__CLASS__ . ':' . __METHOD__ . ':: ' . 'Username and password are required.');
        }
        $requestUri = getenv('OJS_LOGIN_URL');

        $response = $this->_client->request('POST', $requestUri, [
            'cookies' => $cookieJar,
            'form_params' => [
                'username' => $username,
                'password' => $password
            ]
        ]);

        if ($response->getStatusCode() == 200) {
            // Cookies from OJS
            $cookie = array_reduce($cookieJar->toArray(), function ($_, $item) {
                return $item['Name'] == 'OJSSID' ? $item['Value'] : false;
            });
            preg_match('/\$\.pkp\.currentUser = (?<json>[^;]+)/m', $response->getBody()->getContents(), $json);
            if ($json) {
                $json = $json['json'];
                $json = json_decode($json);
                if ($json) {
                    return ['sessionId' => $cookie, 'userId' => $json->id];
                }
            }
            return ['sessionId' => $cookie];
        }

        return [];
    }

    /**
     * Register a new user in OJS
     *
     * This method creates a valid XML
     * to import a new user to OJS database
     *
     * @param String $nome
     * @param String $sobrenome
     * @param String $email
     * @param String $login
     * @param String $senha
     * @return Boolean
     **/
    public function createUpdateUser(array $data)
    {
        try {
            OjsProvider::getApplication();
            $user = Repo::user()->getByEmail($data['email']);
            if (is_null($user)) {
                $user = Repo::user()->newDataObject();
                $user->setAllData($data);
                // setAllData() replaces the entire data array wholesale, but the schema's
                // property is "userName" (camelCase), not the "username" key this API takes -
                // without this, the username is silently dropped and never reaches the DB.
                $user->setUsername($data['username']);
                $user->setPassword(Validation::encryptCredentials($user->getUsername(), $data['password']));
                $user->setDateRegistered(Core::getCurrentDate());
                $userId = Repo::user()->add($user);
                foreach ($data['groups'] as $groupId) {
                    if (!Repo::userGroup()->userInGroup($userId, $groupId)) {
                        Repo::userGroup()->assignUserToGroup($userId, $groupId);
                    }
                }
            } else {
                $id = $user->getId();
                $user->setAllData($data);
                $user->setUsername($data['username']);
                $user->setId($id);
                Repo::user()->edit($user, []);
            }
        } catch (\Exception $e) {
            throw new \Exception("Error creating OJS user: $e");
        }
         return true;
    }

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
}
