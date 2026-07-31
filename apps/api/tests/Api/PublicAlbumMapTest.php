<?php

namespace App\Tests\Api;

use App\Entity\Album;
use App\Entity\Location;
use App\Enum\AlbumVisibility;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicAlbumMapTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private KernelBrowser $client;

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
        foreach ($this->em->getRepository(Album::class)->findAll() as $album) {
            $album->setLocation(null);
            $album->setParent(null);
            $this->em->remove($album);
        }
        foreach ($this->em->getRepository(Location::class)->findAll() as $location) {
            $this->em->remove($location);
        }
        $this->em->flush();
    }

    private function loadFixtures(): void
    {
        $withCoords = new Location('Eiffel Tower');
        $withCoords->setCity('Paris');
        $withCoords->setCountry('France');
        $withCoords->setLatitude(48.8584);
        $withCoords->setLongitude(2.2945);
        $this->em->persist($withCoords);

        $noCoords = new Location('Somewhere');
        $noCoords->setCity('Unknown');
        $this->em->persist($noCoords);

        $public = new Album('Paris Trip', 'paris-trip');
        $public->setVisibility(AlbumVisibility::Public);
        $public->setLocation($withCoords);
        $this->em->persist($public);

        $publicNoCoords = new Album('No Pin', 'no-pin');
        $publicNoCoords->setVisibility(AlbumVisibility::Public);
        $publicNoCoords->setLocation($noCoords);
        $this->em->persist($publicNoCoords);

        $private = new Album('Secret Spot', 'secret-spot');
        $private->setVisibility(AlbumVisibility::Private);
        $private->setLocation($withCoords);
        $this->em->persist($private);

        $unlisted = new Album('Unlisted Spot', 'unlisted-spot');
        $unlisted->setVisibility(AlbumVisibility::Unlisted);
        $unlisted->setLocation($withCoords);
        $this->em->persist($unlisted);

        $childLoc = new Location('Louvre');
        $childLoc->setLatitude(48.8606);
        $childLoc->setLongitude(2.3376);
        $this->em->persist($childLoc);

        $child = new Album('Museum Day', 'museum-day');
        $child->setVisibility(AlbumVisibility::Public);
        $child->setParent($public);
        $child->setLocation($childLoc);
        $this->em->persist($child);

        $this->em->flush();
    }

    public function testMapReturnsOnlyPublicAlbumsWithCoordinates(): void
    {
        $this->client->request('GET', '/api/albums/map');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $slugs = array_column($body['data'], 'slug');

        $this->assertContains('paris-trip', $slugs);
        $this->assertContains('museum-day', $slugs);
        $this->assertNotContains('no-pin', $slugs);
        $this->assertNotContains('secret-spot', $slugs);
        $this->assertNotContains('unlisted-spot', $slugs);
        $this->assertCount(2, $body['data']);

        $bySlug = [];
        foreach ($body['data'] as $row) {
            $bySlug[$row['slug']] = $row;
        }
        $this->assertSame(48.8584, $bySlug['paris-trip']['location']['latitude']);
        $this->assertSame(2.2945, $bySlug['paris-trip']['location']['longitude']);
        $this->assertSame(48.8606, $bySlug['museum-day']['location']['latitude']);
        $this->assertSame(2.3376, $bySlug['museum-day']['location']['longitude']);
    }
}
