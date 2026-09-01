<?php

namespace App\Tests\Api;

use App\Entity\Album;
use App\Entity\AdminUser;
use App\Entity\Face;
use App\Entity\Person;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use App\Service\AvifConverter;
use App\Service\MediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Process\Process;

final class PhotoRotateTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'rotate-admin@gallery.test';
    private const ADMIN_PASSWORD = 'correct-horse-battery-staple';

    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private Album $album;
    private Photo $photo;
    private Person $person;

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
        foreach ($this->em->getRepository(Face::class)->findAll() as $face) {
            $this->em->remove($face);
        }
        $this->em->flush();
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
        $this->album = new Album('Rotate album', 'rotate-album-'.uniqid());
        $this->album->setVisibility(AlbumVisibility::Public);
        $this->em->persist($this->album);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = new AdminUser(self::ADMIN_EMAIL, 'temp');
        $admin->setPassword($hasher->hashPassword($admin, self::ADMIN_PASSWORD));
        $this->em->persist($admin);

        $this->photo = new Photo($this->album, 'originals/aa/rotate-source.jpg');
        $this->photo->setWidth(800);
        $this->photo->setHeight(600);
        $this->em->persist($this->photo);
        $this->em->flush();

        /** @var MediaStorage $storage */
        $storage = static::getContainer()->get(MediaStorage::class);
        $fixture = \dirname(__DIR__).'/fixtures/sample.jpg';
        $avifRelative = $storage->avifMasterPath((string) $this->photo->getId());
        $avifAbsolute = $storage->absolutePath($avifRelative);
        $storage->ensureDirectoryFor($avifRelative);

        $process = new Process(['vips', 'copy', $fixture, $avifAbsolute.'[Q=80]']);
        $process->run();
        if (!$process->isSuccessful()) {
            self::markTestSkipped('vips is required to run photo rotate tests.');
        }

        $thumbRelativeBySize = [];
        foreach (AvifConverter::THUMBNAIL_SIZES as $size) {
            $thumbRelativeBySize[(string) $size] = $storage->thumbPath((string) $this->photo->getId(), $size);
        }
        $this->photo->setAvifPath($avifRelative);
        $this->photo->setOriginalPath(null);
        $this->photo->setThumbPaths($thumbRelativeBySize);

        $this->person = new Person();
        $this->person->setName('Rotate Person');
        $this->person->setIsNamed(true);
        $this->em->persist($this->person);

        $face = new Face($this->photo);
        $face->setPerson($this->person);
        $face->setX(100.0);
        $face->setY(50.0);
        $face->setWidth(80.0);
        $face->setHeight(100.0);
        $face->setEmbedding(array_fill(0, 512, 0.01));
        $this->em->persist($face);
        $this->em->flush();
        $face->setCropPath($storage->writeFaceCrop((string) $face->getId(), (string) file_get_contents($fixture)));
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

    public function testRotatePhotoRequiresAuthentication(): void
    {
        $this->client->jsonRequest('POST', '/api/admin/photos/'.$this->photo->getId().'/rotate', ['degrees' => 90]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testRotatePhotoNinetyDegreesClockwise(): void
    {
        $this->loginAsAdmin();

        $this->client->jsonRequest('POST', '/api/admin/photos/'.$this->photo->getId().'/rotate', ['degrees' => 90]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame(600, $data['width']);
        $this->assertSame(800, $data['height']);

        $face = $data['faces'][0];
        $this->assertEquals(450.0, $face['x']);
        $this->assertEquals(100.0, $face['y']);
        $this->assertEquals(100.0, $face['width']);
        $this->assertEquals(80.0, $face['height']);
    }
}
