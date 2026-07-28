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

/**
 * Design spec §11: "Face crops and person pages are public when the related
 * photos are publicly reachable (same visibility rules as photos/albums)."
 * The same principle applies to tag and location pages, and album ancestor
 * breadcrumbs must never leak a private ancestor's title/slug.
 */
final class PublicVisibilityGateTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private Album $publicAlbum;
    private Album $privateAlbum;
    private Photo $publicPhoto;
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
        $this->publicAlbum = new Album('Landscapes', 'landscapes-'.uniqid());
        $this->publicAlbum->setVisibility(AlbumVisibility::Public);
        $this->em->persist($this->publicAlbum);

        $this->privateAlbum = new Album('Secret', 'secret-'.uniqid());
        $this->privateAlbum->setVisibility(AlbumVisibility::Private);
        $this->em->persist($this->privateAlbum);

        $this->publicPhoto = new Photo($this->publicAlbum, 'originals/aa/aaaa.jpg');
        $this->publicPhoto->setAvifPath('converted/aa/aaaa/master.avif');
        $this->em->persist($this->publicPhoto);

        $this->privatePhoto = new Photo($this->privateAlbum, 'originals/bb/bbbb.jpg');
        $this->privatePhoto->setAvifPath('converted/bb/bbbb/master.avif');
        $this->em->persist($this->privatePhoto);

        $this->em->flush();
    }

    private function detectedFace(Photo $photo, Person $person): Face
    {
        $face = new Face($photo);
        $face->setPerson($person);
        $face->setEmbedding(array_fill(0, 512, 0.01));
        $face->setCropPath('faces/aa/'.$photo->getId().'/1.jpg');
        $this->em->persist($face);

        return $face;
    }

    // --- Person show ---------------------------------------------------------

    public function testPersonShowReturns404WhenOnlyPrivatePhotos(): void
    {
        $person = new Person();
        $person->setName('Ana');
        $person->setIsNamed(true);
        $this->em->persist($person);
        $this->detectedFace($this->privatePhoto, $person);
        $this->em->flush();

        $this->client->request('GET', '/api/people/'.$person->getId());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testPersonShowSucceedsWhenAtLeastOnePhotoIsVisible(): void
    {
        $person = new Person();
        $person->setName('Ana');
        $person->setIsNamed(true);
        $this->em->persist($person);
        $this->detectedFace($this->publicPhoto, $person);
        $this->detectedFace($this->privatePhoto, $person);
        $this->em->flush();

        $this->client->request('GET', '/api/people/'.$person->getId());

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('Ana', $data['name']);
    }

    public function testPersonShowReturns404WhenPersonHasNoPhotosAtAll(): void
    {
        $person = new Person();
        $person->setName('Ana');
        $person->setIsNamed(true);
        $this->em->persist($person);
        $this->em->flush();

        $this->client->request('GET', '/api/people/'.$person->getId());

        $this->assertResponseStatusCodeSame(404);
    }

    // --- Tag show --------------------------------------------------------------

    public function testTagShowReturns404WhenOnlyPrivatePhotos(): void
    {
        $tag = new Tag('Beach', 'beach-'.uniqid());
        $this->privatePhoto->addTag($tag);
        $this->em->persist($tag);
        $this->em->flush();

        $this->client->request('GET', '/api/tags/'.$tag->getSlug());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testTagShowSucceedsWhenAtLeastOnePhotoIsVisible(): void
    {
        $tag = new Tag('Beach', 'beach-'.uniqid());
        $this->publicPhoto->addTag($tag);
        $this->privatePhoto->addTag($tag);
        $this->em->persist($tag);
        $this->em->flush();

        $this->client->request('GET', '/api/tags/'.$tag->getSlug());

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('Beach', $data['tag']['name']);
        $this->assertArrayNotHasKey('photos', $data);

        $this->client->request('GET', '/api/tags/'.$tag->getSlug().'/photos?perPage=1');
        $this->assertResponseIsSuccessful();
        $photosBody = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $photosBody['data']);
        $this->assertSame(1, $photosBody['meta']['total']);
        $this->assertSame(1, $photosBody['meta']['perPage']);
    }

    public function testTagShowReturns404WhenTagHasNoPhotosAtAll(): void
    {
        $tag = new Tag('Beach', 'beach-'.uniqid());
        $this->em->persist($tag);
        $this->em->flush();

        $this->client->request('GET', '/api/tags/'.$tag->getSlug());

        $this->assertResponseStatusCodeSame(404);
    }

    // --- Location show -----------------------------------------------------------

    public function testLocationShowReturns404WhenOnlyPrivatePhotos(): void
    {
        $location = new Location('Paris');
        $this->em->persist($location);
        $this->privateAlbum->setLocation($location);
        $this->em->flush();

        $this->client->request('GET', '/api/locations/'.$location->getId());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testLocationShowSucceedsWhenAtLeastOnePhotoIsVisible(): void
    {
        $location = new Location('Paris');
        $this->em->persist($location);
        $this->publicAlbum->setLocation($location);
        $this->em->flush();

        $this->client->request('GET', '/api/locations/'.$location->getId());

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('Paris', $data['location']['name']);
        $this->assertArrayNotHasKey('photos', $data);

        $this->client->request('GET', '/api/locations/'.$location->getId().'/photos?perPage=1');
        $this->assertResponseIsSuccessful();
        $photosBody = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $photosBody['data']);
        $this->assertSame(1, $photosBody['meta']['total']);
        $this->assertSame(1, $photosBody['meta']['perPage']);
    }

    public function testLocationShowReturns404WhenLocationHasNoPhotosAtAll(): void
    {
        $location = new Location('Paris');
        $this->em->persist($location);
        $this->em->flush();

        $this->client->request('GET', '/api/locations/'.$location->getId());

        $this->assertResponseStatusCodeSame(404);
    }

    // --- Album ancestors: never leak a private ancestor ------------------------------

    public function testAlbumAncestorsStopAtPrivateGrandparent(): void
    {
        $privateRoot = new Album('Secret Root', 'secret-root-'.uniqid());
        $privateRoot->setVisibility(AlbumVisibility::Private);
        $this->em->persist($privateRoot);

        $publicMiddle = new Album('Middle', 'middle-'.uniqid());
        $publicMiddle->setVisibility(AlbumVisibility::Public);
        $publicMiddle->setParent($privateRoot);
        $this->em->persist($publicMiddle);

        $publicLeaf = new Album('Leaf', 'leaf-'.uniqid());
        $publicLeaf->setVisibility(AlbumVisibility::Public);
        $publicLeaf->setParent($publicMiddle);
        $this->em->persist($publicLeaf);

        $this->em->flush();

        $this->client->request('GET', '/api/albums/'.$publicLeaf->getSlug());

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];

        $ancestorSlugs = array_column($data['ancestors'], 'slug');
        $this->assertContains($publicMiddle->getSlug(), $ancestorSlugs);
        $this->assertNotContains($privateRoot->getSlug(), $ancestorSlugs);
        $this->assertNotContains('Secret Root', array_column($data['ancestors'], 'title'));
    }

    public function testAlbumAncestorsEmptyWhenParentIsPrivate(): void
    {
        $privateRoot = new Album('Secret Root', 'secret-root-'.uniqid());
        $privateRoot->setVisibility(AlbumVisibility::Private);
        $this->em->persist($privateRoot);

        $publicChild = new Album('Child', 'child-'.uniqid());
        $publicChild->setVisibility(AlbumVisibility::Public);
        $publicChild->setParent($privateRoot);
        $this->em->persist($publicChild);

        $this->em->flush();

        $this->client->request('GET', '/api/albums/'.$publicChild->getSlug());

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];

        $this->assertSame([], $data['ancestors']);
    }

    public function testAlbumParentSlugIsNullWhenParentIsPrivate(): void
    {
        $privateRoot = new Album('Secret Root', 'secret-root-'.uniqid());
        $privateRoot->setVisibility(AlbumVisibility::Private);
        $this->em->persist($privateRoot);

        $publicChild = new Album('Child', 'child-'.uniqid());
        $publicChild->setVisibility(AlbumVisibility::Public);
        $publicChild->setParent($privateRoot);
        $this->em->persist($publicChild);

        $this->em->flush();

        $this->client->request('GET', '/api/albums/'.$publicChild->getSlug());

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];

        $this->assertNull($data['parentSlug']);
        $this->assertStringNotContainsString($privateRoot->getSlug(), (string) $this->client->getResponse()->getContent());
    }
}
