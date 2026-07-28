<?php

namespace App\Tests\Api;

use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminUserManagementTest extends WebTestCase
{
    private const EMAIL = 'owner@gallery.test';
    private const PASSWORD = 'correct-horse-battery-staple';

    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private AdminUser $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearAdmins();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->owner = new AdminUser(self::EMAIL, 'temp');
        $this->owner->setPassword($hasher->hashPassword($this->owner, self::PASSWORD));
        $this->em->persist($this->owner);
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

    private function loginAsOwner(): void
    {
        $this->client->jsonRequest('POST', '/api/admin/login', [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]);
        $this->assertResponseIsSuccessful();
    }

    public function testUsersEndpointsRequireAuthentication(): void
    {
        $this->client->request('GET', '/api/admin/users');
        $this->assertResponseStatusCodeSame(401);

        $this->client->jsonRequest('POST', '/api/admin/users', [
            'email' => 'new@gallery.test',
            'password' => 'password123',
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testListCreateUpdateAndDeleteUsers(): void
    {
        $this->loginAsOwner();

        $this->client->request('GET', '/api/admin/users');
        $this->assertResponseIsSuccessful();
        $list = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertCount(1, $list);
        $this->assertSame(self::EMAIL, $list[0]['email']);

        $this->client->jsonRequest('POST', '/api/admin/users', [
            'email' => 'Editor@Gallery.Test',
            'password' => 'password123',
        ]);
        $this->assertResponseStatusCodeSame(201);
        $created = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('editor@gallery.test', $created['email']);
        $this->assertContains('ROLE_ADMIN', $created['roles']);
        $createdId = $created['id'];

        $this->client->jsonRequest('PATCH', '/api/admin/users/'.$createdId, [
            'email' => 'renamed@gallery.test',
        ]);
        $this->assertResponseIsSuccessful();
        $updated = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('renamed@gallery.test', $updated['email']);

        $this->client->request('DELETE', '/api/admin/users/'.$createdId);
        $this->assertResponseStatusCodeSame(204);

        $this->em->clear();
        $this->assertNull($this->em->getRepository(AdminUser::class)->find($createdId));
    }

    public function testCannotDeleteSelfOrLastAdmin(): void
    {
        $this->loginAsOwner();

        $this->client->request('DELETE', '/api/admin/users/'.$this->owner->getId());
        $this->assertResponseStatusCodeSame(400);

        $this->assertCount(1, $this->em->getRepository(AdminUser::class)->findAll());
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $this->loginAsOwner();

        $this->client->jsonRequest('POST', '/api/admin/users', [
            'email' => self::EMAIL,
            'password' => 'password123',
        ]);
        $this->assertResponseStatusCodeSame(409);
    }

    public function testPasswordChangeAllowsLogin(): void
    {
        $this->loginAsOwner();

        $this->client->jsonRequest('POST', '/api/admin/users', [
            'email' => 'other@gallery.test',
            'password' => 'password123',
        ]);
        $this->assertResponseStatusCodeSame(201);
        $id = json_decode((string) $this->client->getResponse()->getContent(), true)['data']['id'];

        $this->client->jsonRequest('PATCH', '/api/admin/users/'.$id, [
            'password' => 'new-password-99',
        ]);
        $this->assertResponseIsSuccessful();

        $this->client->jsonRequest('POST', '/api/admin/logout');
        $this->client->jsonRequest('POST', '/api/admin/login', [
            'email' => 'other@gallery.test',
            'password' => 'new-password-99',
        ]);
        $this->assertResponseIsSuccessful();
    }

    public function testNonEmailUsernameCanBeCreatedAndUsedToLogin(): void
    {
        $this->loginAsOwner();

        $this->client->jsonRequest('POST', '/api/admin/users', [
            'email' => 'Fabio',
            'password' => 'password123',
        ]);
        $this->assertResponseStatusCodeSame(201);
        $created = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('fabio', $created['email']);

        $this->client->jsonRequest('POST', '/api/admin/logout');
        $this->client->jsonRequest('POST', '/api/admin/login', [
            'email' => 'fabio',
            'password' => 'password123',
        ]);
        $this->assertResponseIsSuccessful();
    }

    public function testPasswordMinimumIsSixCharacters(): void
    {
        $this->loginAsOwner();

        $this->client->jsonRequest('POST', '/api/admin/users', [
            'email' => 'shortpw',
            'password' => '12345',
        ]);
        $this->assertResponseStatusCodeSame(400);

        $this->client->jsonRequest('POST', '/api/admin/users', [
            'email' => 'shortpw',
            'password' => '123456',
        ]);
        $this->assertResponseStatusCodeSame(201);
    }
}
