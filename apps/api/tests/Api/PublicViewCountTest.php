<?php

namespace App\Tests\Api;

use App\Entity\Album;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use App\Tests\Fake\InMemoryViewDeduplicator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicViewCountTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private Album $publicAlbum;
    private Album $privateAlbum;
    private Photo $publicPhoto;
    private Photo $coverPhoto;
    private Photo $privatePhoto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        static::getContainer()->get(InMemoryViewDeduplicator::class)->reset();
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
        foreach ($this->em->getRepository(Album::class)->findAll() as $album) {
            $album->setCoverPhoto(null);
        }
        $this->em->flush();
        foreach ($this->em->getRepository(Photo::class)->findAll() as $photo) {
            $this->em->remove($photo);
        }
        foreach ($this->em->getRepository(Album::class)->findAll() as $album) {
            $this->em->remove($album);
        }
        $this->em->flush();
    }

    private function loadFixtures(): void
    {
        $this->publicAlbum = new Album('Landscapes', 'landscapes-views');
        $this->publicAlbum->setVisibility(AlbumVisibility::Public);
        $this->publicAlbum->setViewCount(10);
        $this->em->persist($this->publicAlbum);

        $this->privateAlbum = new Album('Secret', 'secret-views');
        $this->privateAlbum->setVisibility(AlbumVisibility::Private);
        $this->privateAlbum->setViewCount(5);
        $this->em->persist($this->privateAlbum);

        $this->coverPhoto = new Photo($this->publicAlbum, 'originals/aa/cover.jpg');
        $this->coverPhoto->setTitle('Cover');
        $this->coverPhoto->setAvifPath('avif/aa/cover.avif');
        $this->coverPhoto->setThumbPaths(['medium' => 'thumbs/aa/cover-medium.avif']);
        $this->coverPhoto->setViewCount(3);
        $this->em->persist($this->coverPhoto);

        $this->publicPhoto = new Photo($this->publicAlbum, 'originals/aa/photo.jpg');
        $this->publicPhoto->setTitle('Peak');
        $this->publicPhoto->setViewCount(7);
        $this->em->persist($this->publicPhoto);

        $this->privatePhoto = new Photo($this->privateAlbum, 'originals/bb/secret.jpg');
        $this->privatePhoto->setViewCount(2);
        $this->em->persist($this->privatePhoto);

        $this->em->flush();
        $this->publicAlbum->setCoverPhoto($this->coverPhoto);
        $this->em->flush();
    }

    public function testPhotoDetailDoesNotIncrementViewCount(): void
    {
        $this->client->request('GET', '/api/photos/'.$this->publicPhoto->getId());

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame(7, $data['viewCount']);

        $this->em->clear();
        $photo = $this->em->getRepository(Photo::class)->find($this->publicPhoto->getId());
        $this->assertNotNull($photo);
        $this->assertSame(7, $photo->getViewCount());
    }

    public function testPhotoViewIsCountedOncePerVisitorWithinTheWindow(): void
    {
        $url = '/api/photos/'.$this->publicPhoto->getId().'/view';

        $this->client->request('POST', $url);
        $this->assertResponseIsSuccessful();
        $first = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame(8, $first['viewCount']);
        $this->assertNotNull($this->client->getCookieJar()->get('gallery_visitor'));

        $this->client->request('POST', $url);
        $this->assertResponseIsSuccessful();
        $duplicate = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame(8, $duplicate['viewCount']);

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', $url);
        $this->assertResponseIsSuccessful();
        $otherVisitor = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame(9, $otherVisitor['viewCount']);
    }

    public function testAlbumDetailDoesNotIncrementViewCount(): void
    {
        $this->client->request('GET', '/api/albums/'.$this->publicAlbum->getSlug());

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame(10, $data['viewCount']);
        $this->assertArrayHasKey('coverPhoto', $data);
        $this->assertSame((string) $this->coverPhoto->getId(), $data['coverPhoto']['id']);
        $this->assertSame('avif/aa/cover.avif', $data['coverPhoto']['avifPath']);
        $this->assertSame(['medium' => 'thumbs/aa/cover-medium.avif'], $data['coverPhoto']['thumbPaths']);
        $this->assertArrayNotHasKey('viewCount', $data['coverPhoto']);

        $this->em->clear();
        $album = $this->em->getRepository(Album::class)->find($this->publicAlbum->getId());
        $cover = $this->em->getRepository(Photo::class)->find($this->coverPhoto->getId());
        $this->assertNotNull($album);
        $this->assertNotNull($cover);
        $this->assertSame(10, $album->getViewCount());
        $this->assertSame(3, $cover->getViewCount());
    }

    public function testAlbumViewIsCountedOncePerVisitorWithinTheWindow(): void
    {
        $url = '/api/albums/'.$this->publicAlbum->getSlug().'/view';

        $this->client->request('POST', $url);
        $this->assertResponseIsSuccessful();
        $first = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame(11, $first['viewCount']);

        $this->client->request('POST', $url);
        $this->assertResponseIsSuccessful();
        $duplicate = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame(11, $duplicate['viewCount']);
    }

    public function testPhotoAndAlbumCountersAreIndependent(): void
    {
        $this->client->request('POST', '/api/photos/'.$this->publicPhoto->getId().'/view');
        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $album = $this->em->getRepository(Album::class)->find($this->publicAlbum->getId());
        $this->assertNotNull($album);
        $this->assertSame(10, $album->getViewCount());
    }

    public function testPrivatePhotoDoesNotIncrement(): void
    {
        $this->client->request('POST', '/api/photos/'.$this->privatePhoto->getId().'/view');
        $this->assertResponseStatusCodeSame(404);

        $this->em->clear();
        $photo = $this->em->getRepository(Photo::class)->find($this->privatePhoto->getId());
        $this->assertNotNull($photo);
        $this->assertSame(2, $photo->getViewCount());
    }

    public function testPrivateAlbumDoesNotIncrement(): void
    {
        $this->client->request('POST', '/api/albums/'.$this->privateAlbum->getSlug().'/view');
        $this->assertResponseStatusCodeSame(404);

        $this->em->clear();
        $album = $this->em->getRepository(Album::class)->find($this->privateAlbum->getId());
        $this->assertNotNull($album);
        $this->assertSame(5, $album->getViewCount());
    }

    public function testUnknownPhotoDoesNotErrorOnIncrement(): void
    {
        $this->client->request('GET', '/api/photos/00000000-0000-0000-0000-000000000000');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAlbumListExposesViewCountWithoutIncrementing(): void
    {
        $this->client->request('GET', '/api/albums');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertCount(1, $data);
        $this->assertSame(10, $data[0]['viewCount']);
        $this->assertNotNull($data[0]['coverPhoto']);
        $this->assertSame('avif/aa/cover.avif', $data[0]['coverPhoto']['avifPath']);

        $this->em->clear();
        $album = $this->em->getRepository(Album::class)->find($this->publicAlbum->getId());
        $cover = $this->em->getRepository(Photo::class)->find($this->coverPhoto->getId());
        $this->assertNotNull($album);
        $this->assertNotNull($cover);
        $this->assertSame(10, $album->getViewCount());
        $this->assertSame(3, $cover->getViewCount());
    }

    public function testAlbumPhotosListExposesViewCountWithoutIncrementing(): void
    {
        $this->client->request('GET', '/api/albums/'.$this->publicAlbum->getSlug().'/photos');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $byId = [];
        foreach ($data as $row) {
            $byId[$row['id']] = $row['viewCount'];
        }
        $this->assertSame(3, $byId[(string) $this->coverPhoto->getId()]);
        $this->assertSame(7, $byId[(string) $this->publicPhoto->getId()]);

        $this->em->clear();
        $cover = $this->em->getRepository(Photo::class)->find($this->coverPhoto->getId());
        $photo = $this->em->getRepository(Photo::class)->find($this->publicPhoto->getId());
        $this->assertNotNull($cover);
        $this->assertNotNull($photo);
        $this->assertSame(3, $cover->getViewCount());
        $this->assertSame(7, $photo->getViewCount());
    }
}
