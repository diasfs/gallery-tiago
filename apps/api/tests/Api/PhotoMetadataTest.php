<?php

namespace App\Tests\Api;

use App\Entity\Album;
use App\Entity\AdminUser;
use App\Entity\Location;
use App\Entity\Photo;
use App\Entity\Tag;
use App\Enum\AlbumVisibility;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PhotoMetadataTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'metadata-admin@gallery.test';
    private const ADMIN_PASSWORD = 'correct-horse-battery-staple';

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
        foreach ($this->em->getRepository(Photo::class)->findAll() as $photo) {
            $this->em->remove($photo);
        }
        foreach ($this->em->getRepository(Album::class)->findAll() as $album) {
            $this->em->remove($album);
        }
        foreach ($this->em->getRepository(Tag::class)->findAll() as $tag) {
            $this->em->remove($tag);
        }
        foreach ($this->em->getRepository(Location::class)->findAll() as $location) {
            $this->em->remove($location);
        }
        foreach ($this->em->getRepository(AdminUser::class)->findAll() as $admin) {
            $this->em->remove($admin);
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
        $this->publicPhoto->setAvifPath('originals/aa/aaaa.avif');
        $this->publicPhoto->setThumbPaths(['320' => 'thumbs/aa/aaaa-320.avif']);
        $this->em->persist($this->publicPhoto);

        $this->privatePhoto = new Photo($this->privateAlbum, 'originals/bb/bbbb.jpg');
        $this->em->persist($this->privatePhoto);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = new AdminUser(self::ADMIN_EMAIL, 'temp');
        $admin->setPassword($hasher->hashPassword($admin, self::ADMIN_PASSWORD));
        $this->em->persist($admin);

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

    // --- PATCH /api/admin/photos/{id} --------------------------------------

    public function testPatchPhotoRequiresAuthentication(): void
    {
        $this->client->jsonRequest('PATCH', '/api/admin/photos/'.$this->publicPhoto->getId(), [
            'title' => 'New title',
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testAdminCanPatchTitle(): void
    {
        $this->loginAsAdmin();

        $this->client->jsonRequest('PATCH', '/api/admin/photos/'.$this->publicPhoto->getId(), [
            'title' => 'Sunset over the bay',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('Sunset over the bay', $data['title']);

        $photo = $this->em->getRepository(Photo::class)->find($this->publicPhoto->getId());
        $this->assertSame('Sunset over the bay', $photo->getTitle());
    }

    public function testAdminCanPatchAlbumTakenAtAndLocation(): void
    {
        $this->loginAsAdmin();

        $location = new Location('Golden Gate Bridge');
        $location->setCity('San Francisco');
        $this->em->persist($location);
        $this->em->flush();

        $this->client->jsonRequest('PATCH', '/api/admin/albums/'.$this->publicAlbum->getId(), [
            'takenAt' => '2025-06-01T10:00:00+00:00',
            'locationId' => (string) $location->getId(),
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('2025-06-01T10:00:00+00:00', $data['takenAt']);
        $this->assertSame((string) $location->getId(), $data['location']['id']);

        $album = $this->em->getRepository(Album::class)->find($this->publicAlbum->getId());
        $this->assertNotNull($album->getTakenAt());
        $this->assertSame('Golden Gate Bridge', $album->getLocation()->getName());
    }

    public function testAdminCanPatchAlbumTakenAtEndRange(): void
    {
        $this->loginAsAdmin();

        $this->client->jsonRequest('PATCH', '/api/admin/albums/'.$this->publicAlbum->getId(), [
            'takenAt' => '2024-06-01T00:00:00+00:00',
            'takenAtEnd' => '2024-06-05T00:00:00+00:00',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('2024-06-01T00:00:00+00:00', $data['takenAt']);
        $this->assertSame('2024-06-05T00:00:00+00:00', $data['takenAtEnd']);
    }

    public function testAdminRejectsInvertedTakenAtRange(): void
    {
        $this->loginAsAdmin();

        $this->client->jsonRequest('PATCH', '/api/admin/albums/'.$this->publicAlbum->getId(), [
            'takenAt' => '2024-06-05T00:00:00+00:00',
            'takenAtEnd' => '2024-06-01T00:00:00+00:00',
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testAdminCanAssignTagsToPhoto(): void
    {
        $this->loginAsAdmin();

        $tag = new Tag('Sunset', 'sunset');
        $this->em->persist($tag);
        $this->em->flush();

        $this->client->jsonRequest('PATCH', '/api/admin/photos/'.$this->publicPhoto->getId(), [
            'tagIds' => [(string) $tag->getId()],
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertCount(1, $data['tags']);
        $this->assertSame('sunset', $data['tags'][0]['slug']);

        $photo = $this->em->getRepository(Photo::class)->find($this->publicPhoto->getId());
        $this->assertCount(1, $photo->getTags());
    }

    public function testPatchAlbumWithUnknownLocationReturns404(): void
    {
        $this->loginAsAdmin();

        $this->client->jsonRequest('PATCH', '/api/admin/albums/'.$this->publicAlbum->getId(), [
            'locationId' => '00000000-0000-0000-0000-000000000000',
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testPatchWithUnknownTagReturns404(): void
    {
        $this->loginAsAdmin();

        $this->client->jsonRequest('PATCH', '/api/admin/photos/'.$this->publicPhoto->getId(), [
            'tagIds' => ['00000000-0000-0000-0000-000000000000'],
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testPatchUnknownPhotoReturns404(): void
    {
        $this->loginAsAdmin();

        $this->client->jsonRequest('PATCH', '/api/admin/photos/00000000-0000-0000-0000-000000000000', [
            'title' => 'X',
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testPatchCanClearAlbumLocation(): void
    {
        $this->loginAsAdmin();

        $location = new Location('Somewhere');
        $this->em->persist($location);
        $this->publicAlbum->setLocation($location);
        $this->em->flush();

        $this->client->jsonRequest('PATCH', '/api/admin/albums/'.$this->publicAlbum->getId(), [
            'locationId' => null,
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertNull($data['location']);
    }

    public function testPatchCanClearPhotoTags(): void
    {
        $this->loginAsAdmin();

        $tag = new Tag('Old', 'old');
        $this->em->persist($tag);
        $this->publicPhoto->addTag($tag);
        $this->em->flush();

        $this->client->jsonRequest('PATCH', '/api/admin/photos/'.$this->publicPhoto->getId(), [
            'tagIds' => [],
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame([], $data['tags']);
    }

    // --- Locations ----------------------------------------------------------

    public function testLocationEndpointsRequireAuthentication(): void
    {
        $this->client->request('GET', '/api/admin/locations');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testAdminCanCreateLocation(): void
    {
        $this->loginAsAdmin();

        $this->client->jsonRequest('POST', '/api/admin/locations', [
            'name' => 'Eiffel Tower',
            'city' => 'Paris',
            'country' => 'France',
            'latitude' => 48.8584,
            'longitude' => 2.2945,
        ]);

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('Eiffel Tower', $data['name']);
        $this->assertSame('Paris', $data['city']);
        $this->assertSame(48.8584, $data['latitude']);
    }

    public function testAdminCreateLocationRequiresName(): void
    {
        $this->loginAsAdmin();

        $this->client->jsonRequest('POST', '/api/admin/locations', ['city' => 'Nowhere']);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testAdminCanSearchLocationsByQuery(): void
    {
        $this->loginAsAdmin();

        $this->em->persist(new Location('Golden Gate Bridge'));
        $this->em->persist(new Location('Tower Bridge'));
        $this->em->flush();

        $this->client->request('GET', '/api/admin/locations?q=golden');

        $this->assertResponseIsSuccessful();
        $names = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['data'], 'name');
        $this->assertContains('Golden Gate Bridge', $names);
        $this->assertNotContains('Tower Bridge', $names);
    }

    // --- Tags -----------------------------------------------------------

    public function testTagEndpointRequiresAuthentication(): void
    {
        $this->client->jsonRequest('POST', '/api/admin/tags', ['name' => 'Sunset']);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testAdminCanCreateTag(): void
    {
        $this->loginAsAdmin();

        $this->client->jsonRequest('POST', '/api/admin/tags', ['name' => 'Sunset']);

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('Sunset', $data['name']);
        $this->assertSame('sunset', $data['slug']);
    }

    public function testAdminCreateTagRequiresName(): void
    {
        $this->loginAsAdmin();

        $this->client->jsonRequest('POST', '/api/admin/tags', []);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testAdminCreateDuplicateTagSlugReturns409(): void
    {
        $this->loginAsAdmin();

        $this->em->persist(new Tag('Sunset', 'sunset'));
        $this->em->flush();

        $this->client->jsonRequest('POST', '/api/admin/tags', ['name' => 'Sunset']);

        $this->assertResponseStatusCodeSame(409);
    }

    public function testAdminCanListTagsWithPhotoCount(): void
    {
        $this->loginAsAdmin();

        $tag = new Tag('dog', 'dog');
        $this->em->persist($tag);
        $this->publicPhoto->addTag($tag);
        $this->em->flush();

        $this->client->request('GET', '/api/admin/tags');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertNotEmpty($data);
        $dog = null;
        foreach ($data as $row) {
            if ('dog' === $row['slug']) {
                $dog = $row;
                break;
            }
        }
        $this->assertNotNull($dog);
        $this->assertSame('dog', $dog['name']);
        $this->assertSame(1, $dog['photoCount']);
    }

    public function testAdminCanTranslateTagWithoutChangingSlug(): void
    {
        $this->loginAsAdmin();

        $tag = new Tag('dog', 'dog');
        $this->em->persist($tag);
        $this->em->flush();
        $tagId = (string) $tag->getId();

        $this->client->jsonRequest('PATCH', '/api/admin/tags/'.$tagId, ['name' => 'cachorro']);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('cachorro', $data['name']);
        $this->assertSame('dog', $data['slug']);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Tag::class)->find($tag->getId());
        $this->assertNotNull($reloaded);
        $this->assertSame('cachorro', $reloaded->getName());
        $this->assertSame('dog', $reloaded->getSlug());
    }

    public function testAdminCanDeleteTag(): void
    {
        $this->loginAsAdmin();

        $tag = new Tag('dog', 'dog');
        $this->em->persist($tag);
        $this->publicPhoto->addTag($tag);
        $this->em->flush();
        $tagId = (string) $tag->getId();

        $this->client->jsonRequest('DELETE', '/api/admin/tags/'.$tagId);

        $this->assertResponseStatusCodeSame(204);
        $this->em->clear();
        $this->assertNull($this->em->getRepository(Tag::class)->find($tagId));
        $this->assertCount(0, $this->em->getRepository(Photo::class)->find($this->publicPhoto->getId())->getTags());
    }

    // --- Public photo detail -----------------------------------------------

    public function testPublicPhotoDetailExposesRelativeOriginalPathOnly(): void
    {
        $this->client->request('GET', '/api/photos/'.$this->publicPhoto->getId());

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $data = json_decode($body, true)['data'];
        $this->assertSame('originals/aa/aaaa.jpg', $data['originalPath']);
        $this->assertStringNotContainsString('/var/gallery', $body);
        $this->assertSame($this->publicAlbum->getTitle(), $data['albumTitle']);
        $this->assertSame($this->publicAlbum->getSlug(), $data['albumSlug']);
        $this->assertSame([], $data['albumAncestors']);
    }

    public function testPublicPhotoDetailIncludesAlbumAncestorsStoppingAtPrivate(): void
    {
        $privateRoot = new Album('Secret Root', 'secret-root-'.uniqid());
        $privateRoot->setVisibility(AlbumVisibility::Private);
        $this->em->persist($privateRoot);

        $publicParent = new Album('Visible Parent', 'visible-parent-'.uniqid());
        $publicParent->setVisibility(AlbumVisibility::Public);
        $publicParent->setParent($privateRoot);
        $this->em->persist($publicParent);

        $this->publicAlbum->setParent($publicParent);
        $this->em->flush();

        $this->client->request('GET', '/api/photos/'.$this->publicPhoto->getId());

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame($this->publicAlbum->getTitle(), $data['albumTitle']);
        $this->assertSame(
            [['slug' => $publicParent->getSlug(), 'title' => 'Visible Parent']],
            $data['albumAncestors'],
        );
        $this->assertStringNotContainsString('Secret Root', (string) $this->client->getResponse()->getContent());
        $this->assertStringNotContainsString($privateRoot->getSlug(), (string) $this->client->getResponse()->getContent());
    }

    public function testPublicPhotoDetailIncludesTags(): void
    {
        $this->loginAsAdmin();
        $tag = new Tag('Sunset', 'sunset');
        $this->em->persist($tag);
        $this->publicPhoto->addTag($tag);
        $this->em->flush();

        $this->client->request('GET', '/api/photos/'.$this->publicPhoto->getId());

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertCount(1, $data['tags']);
        $this->assertSame('sunset', $data['tags'][0]['slug']);
    }

    public function testPublicAlbumDetailIncludesLocationAndTakenAt(): void
    {
        $location = new Location('Golden Gate Bridge');
        $this->em->persist($location);
        $this->publicAlbum->setLocation($location);
        $this->publicAlbum->setTakenAt(new \DateTimeImmutable('2025-06-01T10:00:00+00:00'));
        $this->em->flush();

        $this->client->request('GET', '/api/albums/'.$this->publicAlbum->getSlug());

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('Golden Gate Bridge', $data['location']['name']);
        $this->assertSame('2025-06-01T10:00:00+00:00', $data['takenAt']);
    }

    public function testPublicPhotoDetailReturns404ForPrivateAlbum(): void
    {
        $this->client->request('GET', '/api/photos/'.$this->privatePhoto->getId());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testPublicPhotoDetailReturns404ForUnknownId(): void
    {
        $this->client->request('GET', '/api/photos/00000000-0000-0000-0000-000000000000');

        $this->assertResponseStatusCodeSame(404);
    }
}
