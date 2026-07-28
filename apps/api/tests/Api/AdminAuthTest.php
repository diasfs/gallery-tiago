<?php

namespace App\Tests\Api;

use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminAuthTest extends WebTestCase
{
    private const EMAIL = 'admin@gallery.test';
    private const PASSWORD = 'correct-horse-battery-staple';

    private EntityManagerInterface $em;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearAdmins();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = new AdminUser(self::EMAIL, 'temp');
        $admin->setPassword($hasher->hashPassword($admin, self::PASSWORD));
        $this->em->persist($admin);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clearAdmins();
        parent::tearDown();
    }

    private function clearAdmins(): void
    {
        foreach ($this->em->getRepository(AdminUser::class)->findAll() as $existing) {
            $this->em->remove($existing);
        }
        $this->em->flush();
    }

    public function testLoginRequiresValidCredentials(): void
    {
        $this->client->jsonRequest('POST', '/api/admin/login', [
            'email' => self::EMAIL,
            'password' => 'wrong-password',
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testLoginWithUnknownEmailIsUnauthorized(): void
    {
        $this->client->jsonRequest('POST', '/api/admin/login', [
            'email' => 'nobody@gallery.test',
            'password' => 'irrelevant',
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testLoginSucceedsWithValidCredentials(): void
    {
        $this->client->jsonRequest('POST', '/api/admin/login', [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(self::EMAIL, $data['data']['email']);
    }

    public function testMeRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/admin/me');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testMeReturnsCurrentAdminWhenAuthenticated(): void
    {
        $this->client->jsonRequest('POST', '/api/admin/login', [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]);
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/api/admin/me');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(self::EMAIL, $data['data']['email']);
    }

    public function testLogoutEndsSession(): void
    {
        $this->client->jsonRequest('POST', '/api/admin/login', [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]);
        $this->assertResponseIsSuccessful();

        $this->client->request('POST', '/api/admin/logout');
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/api/admin/me');
        $this->assertResponseStatusCodeSame(401);
    }
}
