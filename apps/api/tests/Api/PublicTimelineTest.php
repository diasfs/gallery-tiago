<?php

namespace App\Tests\Api;

use App\Entity\Album;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicTimelineTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private Photo $junePhoto;
    private Photo $julyPhoto;
    private Photo $privatePhoto;

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
        $this->em->flush();
    }

    private function loadFixtures(): void
    {
        $publicJune = new Album('June trip', 'june-trip');
        $publicJune->setVisibility(AlbumVisibility::Public);
        $publicJune->setTakenAt(new \DateTimeImmutable('2024-06-10T00:00:00Z'));
        $this->em->persist($publicJune);

        $publicJuly = new Album('July trip', 'july-trip');
        $publicJuly->setVisibility(AlbumVisibility::Unlisted);
        $publicJuly->setTakenAt(new \DateTimeImmutable('2024-07-05T00:00:00Z'));
        $this->em->persist($publicJuly);

        $private = new Album('Private', 'private-trip');
        $private->setVisibility(AlbumVisibility::Private);
        $private->setTakenAt(new \DateTimeImmutable('2024-06-20T00:00:00Z'));
        $this->em->persist($private);

        $this->junePhoto = new Photo($publicJune, 'originals/aa/june.jpg');
        $this->junePhoto->setTitle('June shot');
        $this->em->persist($this->junePhoto);

        $this->julyPhoto = new Photo($publicJuly, 'originals/bb/july.jpg');
        $this->julyPhoto->setTitle('July shot');
        $this->em->persist($this->julyPhoto);

        $this->privatePhoto = new Photo($private, 'originals/cc/private.jpg');
        $this->em->persist($this->privatePhoto);

        $this->em->flush();
    }

    public function testMonthsListGroupsVisiblePhotos(): void
    {
        $this->client->request('GET', '/api/timeline/months');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame(
            [
                ['year' => 2024, 'month' => 7, 'photoCount' => 1],
                ['year' => 2024, 'month' => 6, 'photoCount' => 1],
            ],
            $data,
        );
    }

    public function testPhotosEndpointReturnsPaginatedMonth(): void
    {
        $this->client->request('GET', '/api/timeline/photos?year=2024&month=6');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(1, $body['meta']['total']);
        $this->assertSame((string) $this->junePhoto->getId(), $body['data'][0]['id']);
        $this->assertSame('June shot', $body['data'][0]['title']);
    }

    public function testPhotosEndpointRejectsInvalidMonth(): void
    {
        $this->client->request('GET', '/api/timeline/photos?year=2024&month=13');

        $this->assertResponseStatusCodeSame(400);
    }
}
