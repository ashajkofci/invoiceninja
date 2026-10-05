<?php

namespace Tests\Unit;

use Google\Client;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use Google\Service\Oauth2\Userinfo;
use PHPUnit\Framework\TestCase;

class GoogleApiServicesTest extends TestCase
{
    public function testGmailMailTransportServicesRemainAvailable(): void
    {
        $gmail = new Gmail(new Client());
        $message = new Message();
        $message->setRaw('encoded-email');

        $this->assertInstanceOf(Gmail\Resource\UsersMessages::class, $gmail->users_messages);
        $this->assertSame('encoded-email', $message->getRaw());
    }

    public function testLegacyOAuthProviderAndUserInfoRemainAvailable(): void
    {
        $oauth = new \Google_Service_Oauth2(new \Google_Client());
        $user = new Userinfo();
        $user->setEmail('test@example.com');

        $this->assertInstanceOf(\Google\Service\Oauth2::class, $oauth);
        $this->assertInstanceOf(\Google\Service\Oauth2\Resource\Userinfo::class, $oauth->userinfo);
        $this->assertSame('test@example.com', $user->getEmail());
    }
}
