<?php

namespace App\Tests\Api;

use App\Entity\Album;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicPhotoByPathTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearFixtures();
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
        $this->em->flush();
    }

    public function testGetPhotoByAlbumSlugAndFilename(): void
    {
        $album = new Album('Summer', 'summer-path');
        $album->setVisibility(AlbumVisibility::Public);
        $this->em->persist($album);

        $first = new Photo($album, 'originals/aa/first.jpg');
        $first->setFilename('DSC_0001.jpg');
        $first->setSortOrder(0);
        $this->em->persist($first);

        $second = new Photo($album, 'originals/aa/second.jpg');
        $second->setFilename('DSC_0002.jpg');
        $second->setTitle('Second shot');
        $second->setSortOrder(1);
        $this->em->persist($second);

        $this->em->flush();

        $this->client->request('GET', '/api/albums/summer-path/photos/DSC_0002.jpg');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame((string) $second->getId(), $data['id']);
        $this->assertSame('summer-path', $data['albumSlug']);
        $this->assertSame('DSC_0002.jpg', $data['filename']);
        $this->assertSame('DSC_0001.jpg', $data['prevFilename']);
        $this->assertNull($data['nextFilename']);
        $this->assertSame((string) $first->getId(), $data['prevId']);
        $this->assertNull($data['nextId']);
    }

    public function testGetPhotoByPathReturns404ForUnknownFilename(): void
    {
        $album = new Album('Summer', 'summer-missing');
        $album->setVisibility(AlbumVisibility::Public);
        $this->em->persist($album);
        $this->em->flush();

        $this->client->request('GET', '/api/albums/summer-missing/photos/missing.jpg');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testGetPhotoByPathSupportsEncodedFilename(): void
    {
        $album = new Album('Summer', 'summer-encoded');
        $album->setVisibility(AlbumVisibility::Public);
        $this->em->persist($album);

        $photo = new Photo($album, 'originals/aa/spaced.jpg');
        $photo->setFilename('my photo.jpg');
        $this->em->persist($photo);
        $this->em->flush();

        $this->client->request('GET', '/api/albums/summer-encoded/photos/my%20photo.jpg');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('my photo.jpg', $data['filename']);
    }

    public function testGetPhotoByPathHidesPrivateAlbumPhotos(): void
    {
        $album = new Album('Secret', 'secret-path');
        $album->setVisibility(AlbumVisibility::Private);
        $this->em->persist($album);

        $photo = new Photo($album, 'originals/aa/secret.jpg');
        $photo->setFilename('secret.jpg');
        $this->em->persist($photo);
        $this->em->flush();

        $this->client->request('GET', '/api/albums/secret-path/photos/secret.jpg');

        $this->assertResponseStatusCodeSame(404);
    }
}
