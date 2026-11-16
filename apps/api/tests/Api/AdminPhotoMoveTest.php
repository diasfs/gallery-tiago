<?php

namespace App\Tests\Api;

use App\Entity\Album;
use App\Entity\AdminUser;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminPhotoMoveTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'move-admin@gallery.test';
    private const ADMIN_PASSWORD = 'correct-horse-battery-staple';

    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private Album $source;
    private Album $target;
    private Photo $first;
    private Photo $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearFixtures();
        $this->loadFixtures();
    }

    protected function tearDown(): void
    {
        $this->clearFixtures();
        parent::tearDown();
    }

    private function clearFixtures(): void
    {
        foreach ($this->em->getRepository(Photo::class)->findAll() as $photo) {
            $this->em->remove($photo);
        }
        foreach ($this->em->getRepository(Album::class)->findAll() as $album) {
            $this->em->remove($album);
        }
        foreach ($this->em->getRepository(AdminUser::class)->findAll() as $admin) {
            $this->em->remove($admin);
        }
        $this->em->flush();
    }

    private function loadFixtures(): void
    {
        $this->source = new Album('Source', 'source-'.uniqid());
        $this->source->setVisibility(AlbumVisibility::Public);
        $this->em->persist($this->source);

        $this->target = new Album('Target', 'target-'.uniqid());
        $this->target->setVisibility(AlbumVisibility::Public);
        $this->em->persist($this->target);

        $this->first = new Photo($this->source, 'originals/aa/first.jpg');
        $this->first->setFilename('first.jpg');
        $this->first->setSortOrder(0);
        $this->em->persist($this->first);

        $this->second = new Photo($this->source, 'originals/aa/second.jpg');
        $this->second->setFilename('second.jpg');
        $this->second->setSortOrder(1);
        $this->em->persist($this->second);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = new AdminUser(self::ADMIN_EMAIL, 'temp');
        $admin->setPassword($hasher->hashPassword($admin, self::ADMIN_PASSWORD));
        $this->em->persist($admin);

        $this->em->flush();
    }

    private function loginAsAdmin(): void
    {
        $this->client->jsonRequest('POST', '/api/admin/login', [
            'email' => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
        ]);
        $this->assertResponseIsSuccessful();
    }

    public function testMoveRequiresAuthentication(): void
    {
        $this->client->jsonRequest('POST', '/api/admin/photos/bulk-move', [
            'ids' => [(string) $this->first->getId()],
            'albumId' => (string) $this->target->getId(),
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testAdminCanMovePhotosToAnotherAlbum(): void
    {
        $existing = new Photo($this->target, 'originals/bb/existing.jpg');
        $existing->setFilename('existing.jpg');
        $existing->setSortOrder(4);
        $this->em->persist($existing);
        $this->em->flush();

        $this->loginAsAdmin();
        $firstId = (string) $this->first->getId();
        $secondId = (string) $this->second->getId();
        $targetId = (string) $this->target->getId();

        $this->client->jsonRequest('POST', '/api/admin/photos/bulk-move', [
            'ids' => [$firstId, $secondId],
            'albumId' => $targetId,
        ]);

        $this->assertResponseStatusCodeSame(204);

        $this->em->clear();
        $movedFirst = $this->em->getRepository(Photo::class)->find($firstId);
        $movedSecond = $this->em->getRepository(Photo::class)->find($secondId);
        $this->assertNotNull($movedFirst);
        $this->assertNotNull($movedSecond);
        $this->assertSame($targetId, (string) $movedFirst->getAlbum()->getId());
        $this->assertSame($targetId, (string) $movedSecond->getAlbum()->getId());
        $this->assertSame(5, $movedFirst->getSortOrder());
        $this->assertSame(6, $movedSecond->getSortOrder());
    }

    public function testMoveClearsCoverOnSourceAlbum(): void
    {
        $this->source->setCoverPhoto($this->first);
        $this->em->flush();

        $this->loginAsAdmin();
        $this->client->jsonRequest('POST', '/api/admin/photos/bulk-move', [
            'ids' => [(string) $this->first->getId()],
            'albumId' => (string) $this->target->getId(),
        ]);

        $this->assertResponseStatusCodeSame(204);

        $this->em->clear();
        $source = $this->em->getRepository(Album::class)->find($this->source->getId());
        $this->assertNotNull($source);
        $this->assertNull($source->getCoverPhoto());
    }

    public function testMoveRejectsDuplicateFilenameAndMovesNothing(): void
    {
        $clash = new Photo($this->target, 'originals/bb/clash.jpg');
        $clash->setFilename('first.jpg');
        $this->em->persist($clash);
        $this->em->flush();

        $this->loginAsAdmin();
        $this->client->jsonRequest('POST', '/api/admin/photos/bulk-move', [
            'ids' => [(string) $this->first->getId(), (string) $this->second->getId()],
            'albumId' => (string) $this->target->getId(),
        ]);

        $this->assertResponseStatusCodeSame(409);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('first.jpg', (string) ($body['detail'] ?? $body['message'] ?? ''));

        $this->em->clear();
        $first = $this->em->getRepository(Photo::class)->find($this->first->getId());
        $second = $this->em->getRepository(Photo::class)->find($this->second->getId());
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame((string) $this->source->getId(), (string) $first->getAlbum()->getId());
        $this->assertSame((string) $this->source->getId(), (string) $second->getAlbum()->getId());
    }

    public function testMoveRejectsEmptyIds(): void
    {
        $this->loginAsAdmin();
        $this->client->jsonRequest('POST', '/api/admin/photos/bulk-move', [
            'ids' => [],
            'albumId' => (string) $this->target->getId(),
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testMoveRejectsSameAlbum(): void
    {
        $this->loginAsAdmin();
        $this->client->jsonRequest('POST', '/api/admin/photos/bulk-move', [
            'ids' => [(string) $this->first->getId(), (string) $this->second->getId()],
            'albumId' => (string) $this->source->getId(),
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testMoveRejectsUnknownAlbum(): void
    {
        $this->loginAsAdmin();
        $this->client->jsonRequest('POST', '/api/admin/photos/bulk-move', [
            'ids' => [(string) $this->first->getId()],
            'albumId' => '00000000-0000-0000-0000-000000000000',
        ]);

        $this->assertResponseStatusCodeSame(404);
    }
}
