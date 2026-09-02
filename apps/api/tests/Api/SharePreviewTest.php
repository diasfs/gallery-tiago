<?php

namespace App\Tests\Api;

use App\Entity\Album;
use App\Entity\Location;
use App\Entity\Person;
use App\Entity\Photo;
use App\Entity\Tag;
use App\Enum\AlbumVisibility;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SharePreviewTest extends WebTestCase
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
        foreach ($this->em->getRepository(Person::class)->findAll() as $person) {
            $this->em->remove($person);
        }
        foreach ($this->em->getRepository(Tag::class)->findAll() as $tag) {
            $this->em->remove($tag);
        }
        foreach ($this->em->getRepository(Location::class)->findAll() as $location) {
            $this->em->remove($location);
        }
        $this->em->flush();
    }

    public function testPhotoSharePreviewReturnsOpenGraphHtmlForCrawlers(): void
    {
        $album = new Album('Trip', 'trip-share');
        $album->setVisibility(AlbumVisibility::Public);
        $this->em->persist($album);
        $photo = new Photo($album, 'originals/aa/share.jpg');
        $photo->setFilename('share.jpg');
        $photo->setTitle('Sunset');
        $photo->setThumbPaths(['1280' => 'converted/ab/thumb.avif']);
        $photo->setWidth(1280);
        $photo->setHeight(960);
        $this->em->persist($photo);
        $this->em->flush();

        $this->client->request(
            'GET',
            '/photos/'.$photo->getId()->toRfc4122(),
            server: ['HTTP_USER_AGENT' => 'facebookexternalhit/1.1'],
        );

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('og:title', $html);
        $this->assertStringContainsString('Sunset · Gallery', $html);
        $this->assertStringContainsString('og:image', $html);
        $this->assertStringContainsString('og:image:type', $html);
        $this->assertStringContainsString('image/avif', $html);
        $this->assertStringContainsString('/converted/ab/thumb.avif', $html);
        $this->assertStringContainsString('http://localhost:5173/trip-share/share.jpg', $html);
    }

    public function testRootPhotoSharePreviewReturnsOpenGraphHtmlForCrawlers(): void
    {
        $album = new Album('Trip', 'trip-root');
        $album->setVisibility(AlbumVisibility::Public);
        $this->em->persist($album);
        $photo = new Photo($album, 'originals/aa/root.jpg');
        $photo->setFilename('root-shot.jpg');
        $photo->setTitle('Root shot');
        $photo->setThumbPaths(['1280' => 'converted/ab/root.avif']);
        $this->em->persist($photo);
        $this->em->flush();

        $this->client->request(
            'GET',
            '/trip-root/root-shot.jpg',
            server: ['HTTP_USER_AGENT' => 'facebookexternalhit/1.1'],
        );

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Root shot · Gallery', $html);
        $this->assertStringContainsString('http://localhost:5173/trip-root/root-shot.jpg', $html);
    }

    public function testRootAlbumSharePreviewReturnsOpenGraphHtmlForCrawlers(): void
    {
        $album = new Album('Beach', 'beach-root');
        $album->setVisibility(AlbumVisibility::Public);
        $album->setDescription('Sandy days');
        $this->em->persist($album);
        $this->em->flush();

        $this->client->request(
            'GET',
            '/beach-root',
            server: ['HTTP_USER_AGENT' => 'Twitterbot/1.0'],
        );

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Beach · Gallery', $html);
        $this->assertStringContainsString('http://localhost:5173/beach-root', $html);
    }

    public function testPhotoSharePreviewUsesForwardedPublicHost(): void
    {
        $album = new Album('Trip', 'trip-share-host');
        $album->setVisibility(AlbumVisibility::Public);
        $this->em->persist($album);
        $photo = new Photo($album, 'originals/aa/share.jpg');
        $photo->setFilename('share.jpg');
        $photo->setTitle('Sunset');
        $photo->setThumbPaths(['1280' => 'converted/ab/thumb.avif']);
        $this->em->persist($photo);
        $this->em->flush();

        $this->client->request(
            'GET',
            '/trip-share-host/share.jpg',
            server: [
                'HTTP_USER_AGENT' => 'facebookexternalhit/1.1',
                'HTTP_X_FORWARDED_HOST' => 'vite.dias.poa.br',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ],
        );

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('https://vite.dias.poa.br/trip-share-host/share.jpg', $html);
        $this->assertStringContainsString(
            'https://vite.dias.poa.br/converted/ab/thumb.avif',
            $html,
        );
    }

    public function testPhotoSharePreviewRedirectsBrowsersToCanonicalRootUrl(): void
    {
        $album = new Album('Trip', 'trip-share-redirect');
        $album->setVisibility(AlbumVisibility::Public);
        $this->em->persist($album);
        $photo = new Photo($album, 'originals/aa/share.jpg');
        $photo->setFilename('share.jpg');
        $this->em->persist($photo);
        $this->em->flush();

        $this->client->request('GET', '/photos/'.$photo->getId()->toRfc4122());

        $this->assertResponseRedirects('http://localhost:5173/trip-share-redirect/share.jpg');
    }

    public function testAlbumSharePreviewHidesPrivateAlbums(): void
    {
        $album = new Album('Secret', 'secret-share');
        $album->setVisibility(AlbumVisibility::Private);
        $this->em->persist($album);
        $this->em->flush();

        $this->client->request(
            'GET',
            '/albums/'.$album->getSlug(),
            server: ['HTTP_USER_AGENT' => 'Twitterbot/1.0'],
        );

        $this->assertResponseStatusCodeSame(404);
    }

    public function testRootAlbumSharePreviewHidesReservedSlug(): void
    {
        $this->client->request(
            'GET',
            '/admin',
            server: ['HTTP_USER_AGENT' => 'Twitterbot/1.0'],
        );

        $this->assertResponseStatusCodeSame(404);
    }

    public function testHomeSharePreviewReturnsOpenGraphHtmlForCrawlers(): void
    {
        $this->client->request(
            'GET',
            '/',
            server: ['HTTP_USER_AGENT' => 'WhatsApp/2.0'],
        );

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('og:title', $html);
        $this->assertStringContainsString('Gallery', $html);
        $this->assertStringContainsString('http://localhost:5173/', $html);
    }

    public function testStaticPageSharePreviewReturnsOpenGraphHtmlForCrawlers(): void
    {
        $this->client->request(
            'GET',
            '/search',
            server: ['HTTP_USER_AGENT' => 'Twitterbot/1.0'],
        );

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Busca · Gallery', $html);
        $this->assertStringContainsString('http://localhost:5173/search', $html);
    }

    public function testStaticPageSharePreviewRedirectsBrowsers(): void
    {
        $this->client->request('GET', '/map');

        $this->assertResponseRedirects('http://localhost:5173/map');
    }

    public function testPersonSharePreviewReturnsOpenGraphHtmlForCrawlers(): void
    {
        $album = new Album('Trip', 'person-album');
        $album->setVisibility(AlbumVisibility::Public);
        $this->em->persist($album);
        $photo = new Photo($album, 'originals/aa/person.jpg');
        $photo->setFilename('person.jpg');
        $photo->setTitle('Group');
        $photo->setThumbPaths(['1280' => 'converted/ab/person.avif']);
        $photo->setWidth(1280);
        $photo->setHeight(960);
        $this->em->persist($photo);

        $person = new Person();
        $person->setName('Ana Costa');
        $person->setIsNamed(true);
        $this->em->persist($person);

        $face = new \App\Entity\Face($photo);
        $face->setPerson($person);
        $this->em->persist($face);
        $this->em->flush();

        $this->client->request(
            'GET',
            '/people/'.$person->getId()->toRfc4122(),
            server: ['HTTP_USER_AGENT' => 'facebookexternalhit/1.1'],
        );

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Ana Costa · Gallery', $html);
        $this->assertStringContainsString('og:image', $html);
        $this->assertStringContainsString('/converted/ab/person.avif', $html);
    }

    public function testPersonSharePreviewHidesPersonWithoutVisiblePhotos(): void
    {
        $person = new Person();
        $person->setName('Solo');
        $person->setIsNamed(true);
        $this->em->persist($person);
        $this->em->flush();

        $this->client->request(
            'GET',
            '/people/'.$person->getId()->toRfc4122(),
            server: ['HTTP_USER_AGENT' => 'facebookexternalhit/1.1'],
        );

        $this->assertResponseStatusCodeSame(404);
    }

    public function testTagSharePreviewReturnsOpenGraphHtmlForCrawlers(): void
    {
        $album = new Album('Trip', 'tag-album');
        $album->setVisibility(AlbumVisibility::Public);
        $this->em->persist($album);
        $photo = new Photo($album, 'originals/aa/tag.jpg');
        $photo->setFilename('tag.jpg');
        $photo->setTitle('Beach day');
        $photo->setThumbPaths(['1280' => 'converted/ab/tag.avif']);
        $this->em->persist($photo);

        $tag = new Tag('Beach', 'beach');
        $photo->addTag($tag);
        $this->em->persist($tag);
        $this->em->flush();

        $this->client->request(
            'GET',
            '/tags/beach',
            server: ['HTTP_USER_AGENT' => 'Twitterbot/1.0'],
        );

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Beach · Gallery', $html);
        $this->assertStringContainsString('og:image', $html);
    }

    public function testTagSharePreviewHidesTagWithoutVisiblePhotos(): void
    {
        $tag = new Tag('Beach', 'beach-empty');
        $this->em->persist($tag);
        $this->em->flush();

        $this->client->request(
            'GET',
            '/tags/beach-empty',
            server: ['HTTP_USER_AGENT' => 'Twitterbot/1.0'],
        );

        $this->assertResponseStatusCodeSame(404);
    }

    public function testLocationSharePreviewReturnsOpenGraphHtmlForCrawlers(): void
    {
        $location = new Location('Paris');
        $location->setCity('Paris');
        $location->setCountry('França');
        $this->em->persist($location);

        $album = new Album('Trip', 'location-album');
        $album->setVisibility(AlbumVisibility::Public);
        $album->setLocation($location);
        $this->em->persist($album);
        $photo = new Photo($album, 'originals/aa/location.jpg');
        $photo->setFilename('location.jpg');
        $photo->setTitle('Eiffel');
        $photo->setThumbPaths(['1280' => 'converted/ab/location.avif']);
        $this->em->persist($photo);
        $this->em->flush();

        $this->client->request(
            'GET',
            '/locations/'.$location->getId()->toRfc4122(),
            server: ['HTTP_USER_AGENT' => 'WhatsApp/2.0'],
        );

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Paris · Gallery', $html);
        $this->assertStringContainsString('og:image', $html);
    }

    public function testLocationSharePreviewHidesLocationWithoutVisiblePhotos(): void
    {
        $location = new Location('Empty');
        $this->em->persist($location);
        $this->em->flush();

        $this->client->request(
            'GET',
            '/locations/'.$location->getId()->toRfc4122(),
            server: ['HTTP_USER_AGENT' => 'WhatsApp/2.0'],
        );

        $this->assertResponseStatusCodeSame(404);
    }

    public function testSharePreviewRoutesDoNotInterceptApiAlbums(): void
    {
        $this->client->request('GET', '/api/albums');

        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $payload);
        $this->assertArrayHasKey('meta', $payload);
    }
}
