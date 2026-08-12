<?php

namespace App\Tests\Api;

use App\Entity\AdminUser;
use App\Entity\Album;
use App\Entity\Face;
use App\Entity\Person;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use App\Service\MediaStorage;
use App\Tests\Fake\FakeFaceEmbeddingClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PersonFaceAdminTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'person-face@gallery.test';
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
        $this->clearFixtures();
        $this->loadFixtures();
    }

    protected function tearDown(): void
    {
        $this->clearFixtures();
        FakeFaceEmbeddingClient::reset();
        parent::tearDown();
    }

    public function testAddReferenceFacesFromUpload(): void
    {
        $person = new Person();
        $person->setName('Ref');
        $person->setIsNamed(true);
        $this->em->persist($person);
        $this->em->flush();
        $personId = (string) $person->getId();

        $this->loginAsAdmin();
        $this->client->request('POST', '/api/admin/people/'.$personId.'/faces', [], [
            'files' => [$this->fixtureUpload()],
        ]);

        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(1, $payload['meta']['added']);
        $this->assertSame([], $payload['meta']['skipped']);
        $this->assertCount(1, $payload['data']['faces']);
        $this->assertTrue($payload['data']['faces'][0]['hasEmbedding']);
        $this->assertNull($payload['data']['faces'][0]['photoId']);

        $storage = static::getContainer()->get(MediaStorage::class);
        $this->assertFileExists($storage->absolutePath($payload['data']['faces'][0]['cropPath']));
    }

    public function testAddReferenceFacesSkipsNonMatchingWhenPersonHasEmbeddings(): void
    {
        $person = new Person();
        $person->setName('Known');
        $person->setIsNamed(true);
        $this->em->persist($person);
        $face = new Face($this->photo);
        $face->setPerson($person);
        $face->setEmbedding($this->unitEmbedding());
        $this->em->persist($face);
        $this->em->flush();

        FakeFaceEmbeddingClient::$nextDetections = [[
            'embedding' => $this->farEmbedding(),
            'x' => 0.0,
            'y' => 0.0,
            'width' => 10.0,
            'height' => 10.0,
            'cropJpeg' => "\xff\xd8fake",
        ]];

        $this->loginAsAdmin();
        $this->client->request('POST', '/api/admin/people/'.$person->getId().'/faces', [], [
            'files' => [$this->fixtureUpload()],
        ]);

        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(0, $payload['meta']['added']);
        $this->assertCount(1, $payload['meta']['skipped']);
        $this->assertCount(1, $payload['data']['faces']);
    }

    public function testDeleteFaceRemovesCropAndClearsAvatar(): void
    {
        $storage = static::getContainer()->get(MediaStorage::class);
        $person = new Person();
        $person->setName('Delete me');
        $person->setIsNamed(true);
        $this->em->persist($person);
        $face = new Face(null);
        $face->setPerson($person);
        $face->setEmbedding($this->unitEmbedding());
        $this->em->persist($face);
        $this->em->flush();
        $crop = $storage->writeFaceCrop((string) $face->getId(), "\xff\xd8fake");
        $face->setCropPath($crop);
        $person->setAvatarFace($face);
        $this->em->flush();
        $faceId = (string) $face->getId();

        $this->loginAsAdmin();
        $this->client->request('DELETE', '/api/admin/people/'.$person->getId().'/faces/'.$faceId);

        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame([], $payload['data']['faces']);
        $this->assertNull($payload['data']['avatarFaceId']);
        $this->assertFileDoesNotExist($storage->absolutePath($crop));
    }

    private function clearFixtures(): void
    {
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

        $this->album = new Album('Faces album', 'faces-'.uniqid());
        $this->album->setVisibility(AlbumVisibility::Public);
        $this->em->persist($this->album);

        $this->photo = new Photo($this->album, 'shot.jpg');
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

    /** @return float[] */
    private function unitEmbedding(): array
    {
        $vector = array_fill(0, 512, 0.0);
        $vector[0] = 1.0;

        return $vector;
    }

    /** @return float[] */
    private function farEmbedding(): array
    {
        $vector = array_fill(0, 512, 0.0);
        $vector[1] = 1.0;

        return $vector;
    }

    private function fixtureUpload(): UploadedFile
    {
        $source = \dirname(__DIR__).'/fixtures/sample.jpg';
        $copy = tempnam(sys_get_temp_dir(), 'person-face').'.jpg';
        copy($source, $copy);

        return new UploadedFile($copy, 'face.jpg', 'image/jpeg', null, true);
    }
}
