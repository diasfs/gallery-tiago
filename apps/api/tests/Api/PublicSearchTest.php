<?php

namespace App\Tests\Api;

use App\Entity\Album;
use App\Entity\Face;
use App\Entity\Location;
use App\Entity\Person;
use App\Entity\Photo;
use App\Entity\Tag;
use App\Enum\AlbumVisibility;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicSearchTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private KernelBrowser $client;

    private Album $publicAlbum;
    private Album $privateAlbum;
    private Album $unlistedAlbum;
    private Photo $publicPhoto;
    private Photo $privatePhoto;
    private Location $paris;
    private Tag $beachTag;
    private Person $namedPerson;
    private Person $privateOnlyPerson;

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
            $photo->getTags()->clear();
        }
        $this->em->flush();
        foreach ($this->em->getRepository(Tag::class)->findAll() as $tag) {
            $this->em->remove($tag);
        }
        foreach ($this->em->getRepository(Photo::class)->findAll() as $photo) {
            $this->em->remove($photo);
        }
        foreach ($this->em->getRepository(Location::class)->findAll() as $location) {
            $this->em->remove($location);
        }
        foreach ($this->em->getRepository(Album::class)->findAll() as $album) {
            $this->em->remove($album);
        }
        $this->em->flush();
    }

    private function loadFixtures(): void
    {
        $this->paris = new Location('Louvre');
        $this->paris->setCity('Paris');
        $this->paris->setCountry('France');
        $this->em->persist($this->paris);

        $this->publicAlbum = new Album('Summer in Paris', 'summer-paris-'.uniqid());
        $this->publicAlbum->setVisibility(AlbumVisibility::Public);
        $this->publicAlbum->setDescription('Holiday around the Louvre');
        $this->publicAlbum->setLocation($this->paris);
        $this->publicAlbum->setTakenAt(new \DateTimeImmutable('2024-06-15T12:00:00Z'));
        $this->em->persist($this->publicAlbum);

        $this->privateAlbum = new Album('Secret Vault', 'secret-'.uniqid());
        $this->privateAlbum->setVisibility(AlbumVisibility::Private);
        $this->privateAlbum->setTakenAt(new \DateTimeImmutable('2024-06-15T12:00:00Z'));
        $this->em->persist($this->privateAlbum);

        $this->unlistedAlbum = new Album('Unlisted Share', 'unlisted-'.uniqid());
        $this->unlistedAlbum->setVisibility(AlbumVisibility::Unlisted);
        $this->unlistedAlbum->setTakenAt(new \DateTimeImmutable('2024-06-15T12:00:00Z'));
        $this->em->persist($this->unlistedAlbum);

        $this->beachTag = new Tag('Beach', 'beach');
        $this->em->persist($this->beachTag);

        $this->publicPhoto = new Photo($this->publicAlbum, 'originals/aa/public.jpg');
        $this->publicPhoto->setTitle('Eiffel sunset');
        $this->publicPhoto->setAvifPath('converted/aa/public.avif');
        $this->publicPhoto->addTag($this->beachTag);
        $this->em->persist($this->publicPhoto);

        $this->privatePhoto = new Photo($this->privateAlbum, 'originals/bb/private.jpg');
        $this->privatePhoto->setTitle('Hidden beach');
        $this->privatePhoto->setAvifPath('converted/bb/private.avif');
        $this->privatePhoto->addTag($this->beachTag);
        $this->em->persist($this->privatePhoto);

        $unlistedPhoto = new Photo($this->unlistedAlbum, 'originals/cc/unlisted.jpg');
        $unlistedPhoto->setTitle('Unlisted beach');
        $unlistedPhoto->setAvifPath('converted/cc/unlisted.avif');
        $this->em->persist($unlistedPhoto);

        $this->namedPerson = new Person();
        $this->namedPerson->setName('Fábio Silva');
        $this->namedPerson->setIsNamed(true);
        $this->em->persist($this->namedPerson);

        $this->privateOnlyPerson = new Person();
        $this->privateOnlyPerson->setName('Secret Bob');
        $this->privateOnlyPerson->setIsNamed(true);
        $this->em->persist($this->privateOnlyPerson);

        $this->em->flush();

        $this->attachFace($this->publicPhoto, $this->namedPerson);
        $this->attachFace($this->privatePhoto, $this->privateOnlyPerson);
        $this->em->flush();
    }

    private function attachFace(Photo $photo, Person $person): Face
    {
        $face = new Face($photo);
        $face->setPerson($person);
        $face->setEmbedding(array_fill(0, 512, 0.01));
        $face->setCropPath('faces/aa/'.$photo->getId().'/1.jpg');
        $this->em->persist($face);

        return $face;
    }

    public function testEmptyCriteriaReturnsEmptyResults(): void
    {
        $this->client->request('GET', '/api/search');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame([], $body['data']['albums']);
        $this->assertSame([], $body['data']['photos']);
        $this->assertSame(0, $body['meta']['albums']['total']);
        $this->assertSame(0, $body['meta']['photos']['total']);
    }

    public function testSearchByTitleAndExcludesPrivateUnlisted(): void
    {
        $this->client->request('GET', '/api/search?q=Paris');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $albumTitles = array_column($body['data']['albums'], 'title');
        $this->assertContains('Summer in Paris', $albumTitles);
        $this->assertNotContains('Secret Vault', $albumTitles);
        $this->assertNotContains('Unlisted Share', $albumTitles);
    }

    public function testSearchByDescriptionAndLocation(): void
    {
        $this->client->request('GET', '/api/search?q=Louvre');
        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertNotEmpty($body['data']['albums']);

        $this->client->request('GET', '/api/search?q=Holiday');
        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertNotEmpty($body['data']['albums']);
    }

    public function testSearchMatchesLocationIgnoringAccents(): void
    {
        $location = new Location('Borússia');
        $location->setCity('Dortmund');
        $location->setCountry('Germany');
        $this->em->persist($location);

        $album = new Album('Match day', 'borussia-album-'.uniqid());
        $album->setVisibility(AlbumVisibility::Public);
        $album->setLocation($location);
        $this->em->persist($album);
        $this->em->flush();

        $this->client->request('GET', '/api/search?q=borussia');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $albumTitles = array_column($body['data']['albums'], 'title');
        $this->assertContains('Match day', $albumTitles);
    }

    public function testSearchPhotosByTitle(): void
    {
        $this->client->request('GET', '/api/search?q=Eiffel');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $photoTitles = array_column($body['data']['photos'], 'title');
        $this->assertContains('Eiffel sunset', $photoTitles);
        $this->assertNotContains('Hidden beach', $photoTitles);
        $this->assertNotContains('Unlisted beach', $photoTitles);
    }

    public function testSearchByYear(): void
    {
        $this->client->request('GET', '/api/search?year=2024');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertNotEmpty($body['data']['albums']);
        $this->assertContains('Summer in Paris', array_column($body['data']['albums'], 'title'));

        $this->client->request('GET', '/api/search?year=2010');
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame([], $body['data']['albums']);
        $this->assertSame([], $body['data']['photos']);
    }

    public function testSearchByDateRange(): void
    {
        $this->client->request('GET', '/api/search?from=2024-06-01&to=2024-06-30');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertContains('Summer in Paris', array_column($body['data']['albums'], 'title'));
        $this->assertNotEmpty($body['data']['photos']);

        $this->client->request('GET', '/api/search?from=2023-01-01&to=2023-12-31');
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame([], $body['data']['albums']);
    }

    public function testSearchByPersonAndTag(): void
    {
        $this->client->request('GET', '/api/search?person=Fábio&tag=beach');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertContains('Summer in Paris', array_column($body['data']['albums'], 'title'));
        $this->assertContains('Eiffel sunset', array_column($body['data']['photos'], 'title'));
        $this->assertNotContains('Hidden beach', array_column($body['data']['photos'], 'title'));
    }

    public function testSuggestPeopleAndTagsHidePrivateOnly(): void
    {
        $this->client->request('GET', '/api/people?q=fabio');
        $this->assertResponseIsSuccessful();
        $people = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $names = array_column($people, 'name');
        $this->assertContains('Fábio Silva', $names);

        $this->client->request('GET', '/api/people?q=Secret');
        $people = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame([], $people);

        $this->client->request('GET', '/api/tags?q=beach');
        $this->assertResponseIsSuccessful();
        $tags = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertContains('beach', array_column($tags, 'slug'));
    }

    public function testTagsIndexReturnsPublicTagsAlphabeticallyWithPhotoCount(): void
    {
        $zebra = new Tag('Zebra', 'zebra');
        $this->em->persist($zebra);
        $this->publicPhoto->addTag($zebra);

        $secretOnly = new Tag('SecretOnly', 'secret-only');
        $this->em->persist($secretOnly);
        $this->privatePhoto->addTag($secretOnly);
        $this->em->flush();

        $this->client->request('GET', '/api/tags?index=1');

        $this->assertResponseIsSuccessful();
        $tags = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $slugs = array_column($tags, 'slug');

        $this->assertSame(['beach', 'zebra'], $slugs);
        $this->assertNotContains('secret-only', $slugs);

        $bySlug = array_column($tags, null, 'slug');
        $this->assertSame(1, $bySlug['beach']['photoCount']);
        $this->assertSame(1, $bySlug['zebra']['photoCount']);
        $this->assertArrayHasKey('id', $bySlug['beach']);
        $this->assertArrayHasKey('name', $bySlug['beach']);
    }
}
