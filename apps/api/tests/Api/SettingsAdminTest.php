<?php

namespace App\Tests\Api;

use App\Entity\AdminUser;
use App\Entity\ProcessingSettings;
use App\Enum\TagDetector;
use App\Repository\ProcessingSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SettingsAdminTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'settings-admin@gallery.test';
    private const ADMIN_PASSWORD = 'correct-horse-battery-staple';

    private EntityManagerInterface $em;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->resetSettings();
        $this->ensureAdmin();
    }

    protected function tearDown(): void
    {
        $this->resetSettings();
        parent::tearDown();
    }

    public function testGetRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/admin/settings');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testGetReturnsDefaults(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/settings');
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true)['data'];
        $this->assertTrue($data['facesEnabled']);
        $this->assertTrue($data['tagsEnabled']);
        $this->assertSame('ram_plus', $data['tagDetector']);
    }

    public function testUpdatePersistsSettings(): void
    {
        $this->loginAsAdmin();
        $this->client->jsonRequest('PUT', '/api/admin/settings', [
            'facesEnabled' => false,
            'tagsEnabled' => true,
            'tagDetector' => 'mobileclip_s0',
        ]);
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true)['data'];
        $this->assertFalse($data['facesEnabled']);
        $this->assertTrue($data['tagsEnabled']);
        $this->assertSame('mobileclip_s0', $data['tagDetector']);

        $row = static::getContainer()->get(ProcessingSettingsRepository::class)->getSingleton();
        $this->assertFalse($row->isFacesEnabled());
        $this->assertSame(TagDetector::MobileClipS0, $row->getTagDetector());
    }

    public function testUpdateRejectsInvalidDetector(): void
    {
        $this->loginAsAdmin();
        $this->client->jsonRequest('PUT', '/api/admin/settings', [
            'tagDetector' => 'clip-base',
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testUpdateRejectsNonBooleanFlags(): void
    {
        $this->loginAsAdmin();
        $this->client->jsonRequest('PUT', '/api/admin/settings', [
            'facesEnabled' => 'yes',
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    private function loginAsAdmin(): void
    {
        $this->client->jsonRequest('POST', '/api/admin/login', [
            'email' => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
        ]);
        $this->assertResponseIsSuccessful();
    }

    private function ensureAdmin(): void
    {
        foreach ($this->em->getRepository(AdminUser::class)->findAll() as $existing) {
            $this->em->remove($existing);
        }
        $this->em->flush();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = new AdminUser(self::ADMIN_EMAIL, 'temp');
        $admin->setPassword($hasher->hashPassword($admin, self::ADMIN_PASSWORD));
        $this->em->persist($admin);
        $this->em->flush();
    }

    private function resetSettings(): void
    {
        $row = $this->em->find(ProcessingSettings::class, ProcessingSettings::SINGLETON_ID);
        if (null === $row) {
            $row = ProcessingSettings::defaults();
            $this->em->persist($row);
        } else {
            $row->setFacesEnabled(true);
            $row->setTagsEnabled(true);
            $row->setTagDetector(TagDetector::RamPlus);
        }
        $this->em->flush();
    }
}
