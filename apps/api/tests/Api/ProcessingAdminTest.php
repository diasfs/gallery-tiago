<?php

namespace App\Tests\Api;

use App\Entity\AdminUser;
use App\Entity\Album;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use App\Enum\MediaStatus;
use App\Message\ConvertMediaMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProcessingAdminTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'processing-admin@gallery.test';
    private const ADMIN_PASSWORD = 'correct-horse-battery-staple';

    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private Album $album;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearFixtures();
        $this->album = $this->loadFixtures();
        $this->convertTransport()->reset();
    }

    protected function tearDown(): void
    {
        $this->clearFixtures();
        parent::tearDown();
    }

    public function testSummaryReturnsStatusCounts(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/processing/summary');
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame(1, $data['media']['pending'] ?? 0);
        $this->assertSame(1, $data['media']['failed'] ?? 0);
        $this->assertSame(1, $data['media']['done'] ?? 0);
    }

    public function testPhotosFilterAndPagination(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/processing/photos?stage=media&status=failed&page=1&perPage=10');
        $this->assertResponseIsSuccessful();

        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $body['data']);
        $this->assertSame('failed', $body['data'][0]['mediaStatus']);
        $this->assertTrue($body['data'][0]['hasOriginal']);
        $this->assertSame(1, $body['meta']['total']);
    }

    public function testEnqueueConvertOnlyPendingWithOriginal(): void
    {
        $this->loginAsAdmin();
        $pending = $this->em->getRepository(Photo::class)->findOneBy(['title' => 'Pending shot']);
        $this->assertNotNull($pending);

        $this->client->jsonRequest('POST', '/api/admin/processing/enqueue-convert', [
            'photoIds' => [(string) $pending->getId()],
        ]);
        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame(1, $data['enqueued']);

        $messages = $this->convertTransport()->get();
        $this->assertCount(1, $messages);
        $this->assertInstanceOf(ConvertMediaMessage::class, $messages[0]->getMessage());
        $this->assertSame((string) $pending->getId(), $messages[0]->getMessage()->getPhotoId());
    }

    public function testEnqueueConvertRejectsTooManyIds(): void
    {
        $this->loginAsAdmin();
        $ids = array_fill(0, 101, '00000000-0000-0000-0000-000000000001');
        $this->client->jsonRequest('POST', '/api/admin/processing/enqueue-convert', [
            'photoIds' => $ids,
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testReprocessBulk(): void
    {
        $this->loginAsAdmin();
        $failed = $this->em->getRepository(Photo::class)->findOneBy(['title' => 'Failed shot']);
        $this->assertNotNull($failed);

        $this->client->jsonRequest('POST', '/api/admin/processing/reprocess', [
            'photoIds' => [(string) $failed->getId()],
            'scope' => 'all',
        ]);
        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame(1, $data['processed']);
        $this->assertSame(0, $data['skipped']);
        $this->assertGreaterThanOrEqual(1, \count($this->convertTransport()->get()));
    }

    public function testSummaryAndPhotosIncludeQueuedStatus(): void
    {
        $queued = new Photo($this->album, null);
        $queued->setTitle('Queued tags');
        $queued->setMediaStatus(MediaStatus::Done);
        $queued->setAvifPath('converted/aa/queued/master.avif');
        $queued->setTagsStatus(\App\Enum\TagsStatus::Queued);
        $queued->setFacesStatus(\App\Enum\FacesStatus::Queued);
        $this->em->persist($queued);
        $this->em->flush();

        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/processing/summary');
        $this->assertResponseIsSuccessful();
        $summary = json_decode($this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame(1, $summary['tags']['queued'] ?? 0);
        $this->assertSame(1, $summary['faces']['queued'] ?? 0);

        $this->client->request('GET', '/api/admin/processing/photos?stage=tags&status=queued&page=1&perPage=10');
        $this->assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $body['data']);
        $this->assertSame('queued', $body['data'][0]['tagsStatus']);
        $this->assertSame(1, $body['meta']['total']);
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

    private function loadFixtures(): Album
    {
        $album = new Album('Processing Album', 'processing-'.uniqid());
        $album->setVisibility(AlbumVisibility::Public);
        $this->em->persist($album);

        $pending = new Photo($album, 'originals/aa/pending.jpg');
        $pending->setTitle('Pending shot');
        $pending->setMediaStatus(MediaStatus::Pending);
        $this->em->persist($pending);

        $failed = new Photo($album, 'originals/aa/failed.jpg');
        $failed->setTitle('Failed shot');
        $failed->setMediaStatus(MediaStatus::Failed);
        $failed->setProcessingError('media: boom');
        $this->em->persist($failed);

        $done = new Photo($album, null);
        $done->setTitle('Done shot');
        $done->setMediaStatus(MediaStatus::Done);
        $done->setAvifPath('converted/aa/done/master.avif');
        $this->em->persist($done);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = new AdminUser(self::ADMIN_EMAIL, 'temp');
        $admin->setPassword($hasher->hashPassword($admin, self::ADMIN_PASSWORD));
        $this->em->persist($admin);

        $this->em->flush();

        return $album;
    }

    private function loginAsAdmin(): void
    {
        $this->client->jsonRequest('POST', '/api/admin/login', [
            'email' => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
        ]);
        $this->assertResponseIsSuccessful();
    }

    private function convertTransport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.convert');
    }
}
