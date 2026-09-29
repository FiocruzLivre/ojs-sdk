<?php

use PHPUnit\Framework\TestCase;
use OjsSdk\Services\OJSService\Users\OJSUserService;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use APP\facades\Repo;
use PKP\security\Role;

class OJSUserServiceTest extends TestCase
{
    public function testUserLogin()
    {
        $mock = new MockHandler([
            new Response(200, ['Set-cookie' => ['OJSSID=91a6b30db3337105fefa2bc2c2520421; path=/; domain=nginx-ojs']])
        ]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $OjsClient = new OJSUserService($client);
        $loginResponse = $OjsClient->login('lyseontech', '123');

        $this->assertNotEmpty($loginResponse);
    }

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
}
