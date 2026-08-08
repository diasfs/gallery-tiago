<?php

namespace App\Tests\Api;

use App\Entity\Album;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use App\Repository\ProcessingSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicDiscoverTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private Photo $memoryPhoto;
    private Photo $todayPhoto;
    private Photo $popularPhoto;
    private Album $popularAlbum;

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
            $album->setParent(null);
            $album->setCoverPhoto(null);
        }
        $this->em->flush();
        foreach ($this->em->getRepository(Album::class)->findAll() as $album) {
            $this->em->remove($album);
        }
        $settings = static::getContainer()->get(ProcessingSettingsRepository::class)->getSingleton();
        $settings->setMostViewedExcludeRootAlbums(false);
        $this->em->flush();
    }

    private function loadFixtures(): void
    {
        $memoryAlbum = new Album('Old summer', 'old-summer');
        $memoryAlbum->setVisibility(AlbumVisibility::Public);
        $memoryAlbum->setTakenAt(new \DateTimeImmutable('2020-07-31T12:00:00Z'));
        $this->em->persist($memoryAlbum);

        $todayAlbum = new Album('This year', 'this-year');
        $todayAlbum->setVisibility(AlbumVisibility::Public);
        $todayAlbum->setTakenAt(new \DateTimeImmutable('2026-07-31T12:00:00Z'));
        $this->em->persist($todayAlbum);

        $this->popularAlbum = new Album('Popular trip', 'popular-trip');
        $this->popularAlbum->setVisibility(AlbumVisibility::Public);
        $this->popularAlbum->setViewCount(42);
        $this->em->persist($this->popularAlbum);

        $privateAlbum = new Album('Secret', 'secret');
        $privateAlbum->setVisibility(AlbumVisibility::Private);
        $privateAlbum->setTakenAt(new \DateTimeImmutable('2019-07-31T12:00:00Z'));
        $privateAlbum->setViewCount(99);
        $this->em->persist($privateAlbum);

        $this->memoryPhoto = new Photo($memoryAlbum, 'originals/aa/memory.jpg');
        $this->memoryPhoto->setTitle('Memory');
        $this->em->persist($this->memoryPhoto);

        $this->todayPhoto = new Photo($todayAlbum, 'originals/bb/today.jpg');
        $this->em->persist($this->todayPhoto);

        $this->popularPhoto = new Photo($this->popularAlbum, 'originals/cc/popular.jpg');
        $this->popularPhoto->setTitle('Hit');
        $this->popularPhoto->setViewCount(120);
        $this->em->persist($this->popularPhoto);

        $privatePhoto = new Photo($privateAlbum, 'originals/dd/private.jpg');
        $privatePhoto->setViewCount(200);
        $this->em->persist($privatePhoto);

        $this->em->flush();
    }

    public function testOnThisDayReturnsPastYearsOnly(): void
    {
        $this->client->request('GET', '/api/discover/on-this-day?month=7&day=31&beforeYear=2026');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(1, $body['meta']['total']);
        $this->assertSame('old-summer', $body['data'][0]['slug']);
        $this->assertSame('2020-07-31T12:00:00+00:00', $body['data'][0]['timelineAt']);
        $this->assertSame(6, $body['data'][0]['yearsAgo']);
    }

    public function testOnThisDayRejectsInvalidDate(): void
    {
        $this->client->request('GET', '/api/discover/on-this-day?month=2&day=31&beforeYear=2026');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testMostViewedAlbumsRanksByViewCount(): void
    {
        $this->client->request('GET', '/api/discover/most-viewed/albums');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(1, $body['meta']['total']);
        $this->assertSame('popular-trip', $body['data'][0]['slug']);
        $this->assertSame(42, $body['data'][0]['viewCount']);
    }

    public function testMostViewedAlbumsCanExcludeRootAlbums(): void
    {
        $child = new Album('Nested hit', 'nested-hit');
        $child->setVisibility(AlbumVisibility::Public);
        $child->setParent($this->popularAlbum);
        $child->setViewCount(7);
        $this->em->persist($child);
        $this->em->flush();

        $settings = static::getContainer()->get(ProcessingSettingsRepository::class)->getSingleton();
        $settings->setMostViewedExcludeRootAlbums(true);
        $this->em->flush();

        $this->client->request('GET', '/api/discover/most-viewed/albums');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(1, $body['meta']['total']);
        $this->assertSame('nested-hit', $body['data'][0]['slug']);
    }
}
