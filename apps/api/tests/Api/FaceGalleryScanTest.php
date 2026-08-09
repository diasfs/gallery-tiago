<?php

namespace App\Tests\Api;

use App\Entity\Album;
use App\Entity\AdminUser;
use App\Entity\Face;
use App\Entity\FaceGalleryScan;
use App\Entity\FaceGalleryScanMatch;
use App\Entity\Person;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use App\Message\ScanFaceMessage;
use App\Service\MediaStorage;
use App\Tests\Fake\FakeFaceEmbeddingClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class FaceGalleryScanTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'face-scan@gallery.test';
    private const ADMIN_PASSWORD = 'secret';

    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private Album $album;
    private Photo $photo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        FakeFaceEmbeddingClient::reset();
        $this->facesTransport()->reset();
        $this->clearFixtures();
        $this->loadFixtures();
    }

    protected function tearDown(): void
    {
        $this->clearFixtures();
        FakeFaceEmbeddingClient::reset();
        parent::tearDown();
    }

    private function clearFixtures(): void
    {
        foreach ($this->em->getRepository(FaceGalleryScanMatch::class)->findAll() as $match) {
            $this->em->remove($match);
        }
        foreach ($this->em->getRepository(FaceGalleryScan::class)->findAll() as $scan) {
            $this->em->remove($scan);
        }
        foreach ($this->em->getRepository(Face::class)->findAll() as $face) {
            $this->em->remove($face);
        }
        foreach ($this->em->getRepository(Person::class)->findAll() as $person) {
            $person->setAvatarFace(null);
        }
        $this->em->flush();
        foreach ($this->em->getRepository(Person::class)->findAll() as $person) {
            $this->em->remove($person);
        }
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
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = new AdminUser(self::ADMIN_EMAIL, 'temp');
        $admin->setPassword($hasher->hashPassword($admin, self::ADMIN_PASSWORD));
        $this->em->persist($admin);

        $this->album = new Album('Scan album', 'scan-'.uniqid());
        $this->album->setVisibility(AlbumVisibility::Public);
        $this->em->persist($this->album);

        $this->photo = new Photo($this->album, 'scan.jpg');
        $this->photo->setAvifPath('converted/aa/aa/master.avif');
        $this->photo->setTitle('Scan target');
        $this->em->persist($this->photo);
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

    private function facesTransport(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.faces');

        return $transport;
    }

    /** @return float[] */
    private function unitEmbedding(): array
    {
        $vector = array_fill(0, 512, 0.0);
        $vector[0] = 1.0;

        return $vector;
    }

    public function testListFaceScansReturnsRecent(): void
    {
        $scan = new FaceGalleryScan($this->unitEmbedding(), 0.35);
        $scan->setStatus(FaceGalleryScan::STATUS_CANCELLED);
        $scan->setTotalPhotos(10);
        $this->em->persist($scan);
        $this->em->flush();

        $this->loginAsAdmin();

        $this->client->request('GET', '/api/admin/people/face-scans');
        $this->assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertGreaterThanOrEqual(1, \count($payload['data']));
        $this->assertSame((string) $scan->getId(), $payload['data'][0]['id']);
    }

    public function testCreateScanEnqueuesPhotoJobs(): void
    {
        FakeFaceEmbeddingClient::$nextEmbedding = $this->unitEmbedding();
        $this->loginAsAdmin();

        $this->client->request('POST', '/api/admin/people/face-scans', [], ['file' => $this->fixtureUpload()]);

        $this->assertResponseStatusCodeSame(201);
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('running', $payload['data']['status']);
        $this->assertSame(1, $payload['data']['totalPhotos']);

        $sent = $this->facesTransport()->getSent();
        $this->assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        $this->assertInstanceOf(ScanFaceMessage::class, $message);
        $this->assertSame((string) $this->photo->getId(), $message->getPhotoId());
    }

    public function testConfirmCreatesNamedPersonWithFacesFromSelectedMatches(): void
    {
        $storage = static::getContainer()->get(MediaStorage::class);
        $scan = new FaceGalleryScan($this->unitEmbedding(), 0.35);
        $scan->setStatus(FaceGalleryScan::STATUS_DONE);
        $scan->setTotalPhotos(1);
        $scan->setEnqueuedPhotos(1);
        $scan->setProcessedPhotos(1);
        $scan->setMatchedPhotos(1);
        $this->em->persist($scan);
        $this->em->flush();
        $scanId = (string) $scan->getId();

        $scanCropRelative = \sprintf('face-scans/%s/%s/%s.jpg', substr($scanId, 0, 2), $scanId, $this->photo->getId());
        $scanCropAbsolute = $storage->absolutePath($scanCropRelative);
        $storage->ensureDirectoryFor($scanCropRelative);
        copy(\dirname(__DIR__).'/fixtures/sample.jpg', $scanCropAbsolute);

        $match = new FaceGalleryScanMatch(
            $scan,
            $this->photo,
            0.12,
            10.0,
            20.0,
            40.0,
            50.0,
            $this->unitEmbedding(),
            $scanCropRelative,
        );
        $this->em->persist($match);
        $this->em->flush();

        $this->loginAsAdmin();

        $this->client->jsonRequest('POST', '/api/admin/people/face-scans/'.$scanId.'/confirm', [
            'name' => 'Scan Person',
        ]);

        $this->assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $personId = $payload['data']['personId'];
        $this->assertNotEmpty($personId);

        $this->em->clear();
        $person = $this->em->getRepository(Person::class)->find($personId);
        $this->assertNotNull($person);
        $this->assertTrue($person->isNamed());
        $this->assertSame('Scan Person', $person->getName());
        $this->assertCount(1, $person->getFaces());
        $face = $person->getFaces()->first();
        $this->assertTrue($face->hasEmbedding());
        $this->assertSame($storage->faceCropPath((string) $face->getId()), $face->getCropPath());
        $this->assertFileExists($storage->absolutePath($face->getCropPath()));
    }

    public function testDeleteScanAfterConfirmKeepsFaceCrop(): void
    {
        $storage = static::getContainer()->get(MediaStorage::class);
        $scan = new FaceGalleryScan($this->unitEmbedding(), 0.35);
        $scan->setStatus(FaceGalleryScan::STATUS_DONE);
        $this->em->persist($scan);
        $this->em->flush();
        $scanId = (string) $scan->getId();

        $scanCropRelative = \sprintf('face-scans/%s/%s/%s.jpg', substr($scanId, 0, 2), $scanId, $this->photo->getId());
        $storage->ensureDirectoryFor($scanCropRelative);
        copy(\dirname(__DIR__).'/fixtures/sample.jpg', $storage->absolutePath($scanCropRelative));

        $match = new FaceGalleryScanMatch(
            $scan,
            $this->photo,
            0.12,
            10.0,
            20.0,
            40.0,
            50.0,
            $this->unitEmbedding(),
            $scanCropRelative,
        );
        $this->em->persist($match);
        $this->em->flush();

        $this->loginAsAdmin();
        $this->client->jsonRequest('POST', '/api/admin/people/face-scans/'.$scanId.'/confirm', [
            'name' => 'Kept Person',
        ]);
        $this->assertResponseIsSuccessful();
        $personId = json_decode($this->client->getResponse()->getContent(), true)['data']['personId'];

        $this->em->clear();
        $faceCrop = $this->em->getRepository(Person::class)->find($personId)->getFaces()->first()->getCropPath();

        $this->client->request('DELETE', '/api/admin/people/face-scans/'.$scanId);
        $this->assertResponseStatusCodeSame(204);
        $this->assertFileExists($storage->absolutePath($faceCrop));
        $this->assertNull($this->em->getRepository(FaceGalleryScan::class)->find($scanId));
    }

    public function testDeleteScanMigratesLegacyFaceCrops(): void
    {
        $storage = static::getContainer()->get(MediaStorage::class);
        $scan = new FaceGalleryScan($this->unitEmbedding(), 0.35);
        $scan->setStatus(FaceGalleryScan::STATUS_DONE);
        $this->em->persist($scan);
        $this->em->flush();
        $scanId = (string) $scan->getId();

        $scanCropRelative = \sprintf('face-scans/%s/%s/%s.jpg', substr($scanId, 0, 2), $scanId, $this->photo->getId());
        $storage->ensureDirectoryFor($scanCropRelative);
        copy(\dirname(__DIR__).'/fixtures/sample.jpg', $storage->absolutePath($scanCropRelative));

        $person = new Person();
        $person->setName('Legacy Person');
        $person->setIsNamed(true);
        $this->em->persist($person);

        $face = new Face($this->photo);
        $face->setPerson($person);
        $face->setCropPath($scanCropRelative);
        $face->setEmbedding($this->unitEmbedding());
        $this->em->persist($face);
        $this->em->flush();
        $faceId = (string) $face->getId();

        $this->loginAsAdmin();
        $this->client->request('DELETE', '/api/admin/people/face-scans/'.$scanId);

        $this->assertResponseStatusCodeSame(204);
        $this->assertNull($this->em->getRepository(FaceGalleryScan::class)->find($scanId));

        $this->em->clear();
        $face = $this->em->getRepository(Face::class)->find($faceId);
        $this->assertNotNull($face);
        $this->assertSame($storage->faceCropPath($faceId), $face->getCropPath());
        $this->assertFileExists($storage->absolutePath($face->getCropPath()));
    }

    public function testDeleteTerminalScan(): void
    {
        $scan = new FaceGalleryScan($this->unitEmbedding(), 0.35);
        $scan->setStatus(FaceGalleryScan::STATUS_CANCELLED);
        $this->em->persist($scan);
        $this->em->flush();
        $scanId = (string) $scan->getId();

        $this->loginAsAdmin();

        $this->client->request('DELETE', '/api/admin/people/face-scans/'.$scanId);

        $this->assertResponseStatusCodeSame(204);
        $this->assertNull($this->em->getRepository(FaceGalleryScan::class)->find($scanId));
    }

    public function testCannotDeleteRunningScan(): void
    {
        $scan = new FaceGalleryScan($this->unitEmbedding(), 0.35);
        $scan->setStatus(FaceGalleryScan::STATUS_RUNNING);
        $this->em->persist($scan);
        $this->em->flush();

        $this->loginAsAdmin();

        $this->client->request('DELETE', '/api/admin/people/face-scans/'.$scan->getId());

        $this->assertResponseStatusCodeSame(400);
    }

    private function fixtureUpload(): UploadedFile
    {
        $source = \dirname(__DIR__).'/fixtures/sample.jpg';
        $copy = tempnam(sys_get_temp_dir(), 'face-scan').'.jpg';
        copy($source, $copy);

        return new UploadedFile($copy, 'face.jpg', 'image/jpeg', null, true);
    }
}
