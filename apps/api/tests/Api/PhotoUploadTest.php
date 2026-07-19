<?php

namespace App\Tests\Api;

use App\Entity\Album;
use App\Entity\AdminUser;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use App\Message\ConvertMediaMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PhotoUploadTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'photo-admin@gallery.test';
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
        $album = new Album('Landscapes', 'landscapes-'.uniqid());
        $album->setVisibility(AlbumVisibility::Public);
        $this->em->persist($album);

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

    /**
     * The framework's file-upload handling (and our own MediaStorage) *moves*
     * the underlying file, so each call must hand out a disposable copy —
     * never a direct reference to the checked-in fixture.
     */
    private function fixtureUpload(string $filename = 'sample.jpg', string $mimeType = 'image/jpeg'): UploadedFile
    {
        $source = \dirname(__DIR__).'/fixtures/sample.jpg';
        $copy = tempnam(sys_get_temp_dir(), 'upload').'.jpg';
        copy($source, $copy);

        return new UploadedFile($copy, $filename, $mimeType, null, true);
    }

    public function testUploadRequiresAuthentication(): void
    {
        $this->client->request('POST', \sprintf('/api/admin/albums/%s/photos', $this->album->getId()), [], [
            'file' => $this->fixtureUpload(),
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testAdminUploadCreatesPendingPhotoAndDispatchesConvertMessage(): void
    {
        $this->loginAsAdmin();

        $this->client->request('POST', \sprintf('/api/admin/albums/%s/photos', $this->album->getId()), [], [
            'file' => $this->fixtureUpload(),
        ]);

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('pending', $data['processingStatus']);
        $this->assertSame((string) $this->album->getId(), $data['albumId']);

        $photo = $this->em->getRepository(Photo::class)->find($data['id']);
        $this->assertNotNull($photo);
        $this->assertSame('pending', $photo->getProcessingStatus()->value);
        $this->assertNotSame('', $photo->getOriginalPath());

        $sent = $this->convertTransport()->getSent();
        $this->assertCount(1, $sent);
        $envelope = $sent[0]->getMessage();
        $this->assertInstanceOf(ConvertMediaMessage::class, $envelope);
        $this->assertSame((string) $photo->getId(), $envelope->getPhotoId());
    }

    public function testUploadRejectsUnsupportedFileType(): void
    {
        $this->loginAsAdmin();

        $textFile = tempnam(sys_get_temp_dir(), 'upload').'.txt';
        file_put_contents($textFile, 'not an image');
        $upload = new UploadedFile($textFile, 'notes.txt', 'text/plain', null, true);

        $this->client->request('POST', \sprintf('/api/admin/albums/%s/photos', $this->album->getId()), [], [
            'file' => $upload,
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertCount(0, $this->convertTransport()->getSent());

        unlink($textFile);
    }

    public function testUploadToUnknownAlbumReturns404(): void
    {
        $this->loginAsAdmin();

        $this->client->request('POST', '/api/admin/albums/00000000-0000-0000-0000-000000000000/photos', [], [
            'file' => $this->fixtureUpload(),
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUploadWithoutFileReturns400(): void
    {
        $this->loginAsAdmin();

        $this->client->request('POST', \sprintf('/api/admin/albums/%s/photos', $this->album->getId()));

        $this->assertResponseStatusCodeSame(400);
    }
}
