<?php

namespace App\Tests\Api;

use App\Entity\Album;
use App\Entity\AdminUser;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AlbumVisibilityTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'album-admin@gallery.test';
    private const ADMIN_PASSWORD = 'correct-horse-battery-staple';

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
        $public = new Album('Landscapes', 'landscapes');
        $public->setVisibility(AlbumVisibility::Public);
        $this->em->persist($public);

        $unlisted = new Album('Family (hidden)', 'family-hidden');
        $unlisted->setVisibility(AlbumVisibility::Unlisted);
        $this->em->persist($unlisted);

        $private = new Album('Secret', 'secret');
        $private->setVisibility(AlbumVisibility::Private);
        $this->em->persist($private);

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

    // --- Public endpoints -------------------------------------------------

    public function testPrivateAlbumNotVisiblePublicly(): void
    {
        $this->client->request('GET', '/api/albums/secret');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUnlistedAlbumReachableBySlug(): void
    {
        $this->client->request('GET', '/api/albums/family-hidden');

        $this->assertResponseIsSuccessful();
    }

    public function testPublicAlbumReachableBySlug(): void
    {
        $this->client->request('GET', '/api/albums/landscapes');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('landscapes', $data['slug']);
        $this->assertSame('public', $data['visibility']);
    }

    public function testUnknownSlugReturns404(): void
    {
        $this->client->request('GET', '/api/albums/does-not-exist');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testPublicListExcludesUnlisted(): void
    {
        $this->client->request('GET', '/api/albums');

        $slugs = array_column(json_decode($this->client->getResponse()->getContent(), true)['data'], 'slug');
        $this->assertNotContains('family-hidden', $slugs);
    }

    public function testPublicListExcludesPrivate(): void
    {
        $this->client->request('GET', '/api/albums');

        $slugs = array_column(json_decode($this->client->getResponse()->getContent(), true)['data'], 'slug');
        $this->assertNotContains('secret', $slugs);
    }

    public function testPublicListIncludesPublicRoot(): void
    {
        $this->client->request('GET', '/api/albums');

        $this->assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $slugs = array_column($body['data'], 'slug');
        $this->assertContains('landscapes', $slugs);
        $this->assertArrayHasKey('meta', $body);
        $this->assertSame(1, $body['meta']['page']);
        $this->assertGreaterThanOrEqual(1, $body['meta']['total']);
    }

    public function testPublicAlbumShowOmitsEmbeddedPhotosAndChildren(): void
    {
        $this->client->request('GET', '/api/albums/landscapes');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('landscapes', $data['slug']);
        $this->assertArrayNotHasKey('photos', $data);
        $this->assertArrayNotHasKey('children', $data);
        $this->assertArrayHasKey('ancestors', $data);
    }

    public function testPublicAlbumPhotosEndpointIsPaginated(): void
    {
        $this->client->request('GET', '/api/albums/landscapes/photos');

        $this->assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($body['data']);
        $this->assertSame(1, $body['meta']['page']);
        $this->assertArrayHasKey('total', $body['meta']);
    }

    public function testPublicAlbumChildrenOrderedByLegacyIdDescNotSortOrder(): void
    {
        $parent = $this->em->getRepository(Album::class)->findOneBy(['slug' => 'landscapes']);
        $this->assertNotNull($parent);

        // Higher sortOrder must NOT win — siblings follow legacy id_album DESC.
        $older = new Album('Older Child', 'older-child');
        $older->setVisibility(AlbumVisibility::Public);
        $older->setParent($parent);
        $older->setSortOrder(1);
        $older->setLegacyId(100);
        $this->em->persist($older);

        $newer = new Album('Newer Child', 'newer-child');
        $newer->setVisibility(AlbumVisibility::Public);
        $newer->setParent($parent);
        $newer->setSortOrder(99);
        $newer->setLegacyId(500);
        $this->em->persist($newer);

        // Native (no legacyId) sorts ahead of imported siblings.
        $native = new Album('Native Child', 'native-child');
        $native->setVisibility(AlbumVisibility::Public);
        $native->setParent($parent);
        $native->setSortOrder(0);
        $this->em->persist($native);

        $this->em->flush();

        $this->client->request('GET', '/api/albums/landscapes/children');

        $this->assertResponseIsSuccessful();
        $slugs = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['data'], 'slug');
        $this->assertSame(['native-child', 'newer-child', 'older-child'], $slugs);
    }

    public function testPublicRecentAlbumsOrderedByLegacyIdDescWithLimit(): void
    {
        $parent = $this->em->getRepository(Album::class)->findOneBy(['slug' => 'landscapes']);
        $this->assertNotNull($parent);
        $parent->setLegacyId(50);

        $nested = new Album('Nested Recent', 'nested-recent');
        $nested->setVisibility(AlbumVisibility::Public);
        $nested->setParent($parent);
        $nested->setLegacyId(500);
        $this->em->persist($nested);

        $olderLegacy = new Album('Older Legacy', 'older-legacy');
        $olderLegacy->setVisibility(AlbumVisibility::Public);
        $olderLegacy->setParent($parent);
        $olderLegacy->setLegacyId(100);
        $this->em->persist($olderLegacy);

        $native = new Album('Native Recent', 'native-recent');
        $native->setVisibility(AlbumVisibility::Public);
        $this->em->persist($native);

        $unlisted = $this->em->getRepository(Album::class)->findOneBy(['slug' => 'family-hidden']);
        $private = $this->em->getRepository(Album::class)->findOneBy(['slug' => 'secret']);
        $this->assertNotNull($unlisted);
        $this->assertNotNull($private);
        $unlisted->setLegacyId(999);
        $private->setLegacyId(1000);

        $this->em->flush();

        $this->client->request('GET', '/api/albums/recent?limit=2');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(['native-recent', 'nested-recent'], array_column($body['data'], 'slug'));
        $this->assertSame(2, $body['meta']['limit']);
        $this->assertNotContains('family-hidden', array_column($body['data'], 'slug'));
        $this->assertNotContains('secret', array_column($body['data'], 'slug'));
    }

    // --- Admin endpoints ----------------------------------------------------

    public function testAdminAlbumListRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/admin/albums');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testAdminAlbumListIncludesAllVisibilities(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', '/api/admin/albums');

        $this->assertResponseIsSuccessful();
        $slugs = array_column(json_decode($this->client->getResponse()->getContent(), true)['data'], 'slug');
        $this->assertContains('secret', $slugs);
        $this->assertContains('family-hidden', $slugs);
        $this->assertContains('landscapes', $slugs);
    }

    public function testAdminCanCreateAlbum(): void
    {
        $this->loginAsAdmin();

        $this->client->jsonRequest('POST', '/api/admin/albums', [
            'title' => 'New Album',
            'slug' => 'new-album',
            'visibility' => 'public',
        ]);

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('new-album', $data['slug']);
        $this->assertSame('public', $data['visibility']);
    }

    public function testAdminCreateAlbumRejectsInvalidVisibility(): void
    {
        $this->loginAsAdmin();

        $this->client->jsonRequest('POST', '/api/admin/albums', [
            'title' => 'Bad Album',
            'slug' => 'bad-album',
            'visibility' => 'nonsense',
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testAdminCanUpdateAlbumVisibility(): void
    {
        $this->loginAsAdmin();
        $secret = $this->em->getRepository(Album::class)->findOneBy(['slug' => 'secret']);

        $this->client->jsonRequest('PATCH', '/api/admin/albums/'.$secret->getId(), [
            'visibility' => 'public',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('public', $data['visibility']);
    }

    public function testAdminGetUnknownAlbumReturns404(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', '/api/admin/albums/00000000-0000-0000-0000-000000000000');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteAlbumWithChildrenCascades(): void
    {
        $this->loginAsAdmin();
        $parent = $this->em->getRepository(Album::class)->findOneBy(['slug' => 'landscapes']);
        $this->assertNotNull($parent);
        $child = new Album('Mountains', 'mountains');
        $child->setParent($parent);
        $this->em->persist($child);
        $this->em->flush();
        $parentId = (string) $parent->getId();
        $childId = (string) $child->getId();

        $this->client->request('DELETE', '/api/admin/albums/'.$parentId);

        $this->assertResponseStatusCodeSame(204);
        $this->em->clear();
        $this->assertNull($this->em->getRepository(Album::class)->find($parentId));
        $this->assertNull($this->em->getRepository(Album::class)->find($childId));
    }

    public function testDeleteAlbumWithPhotosCascades(): void
    {
        $this->loginAsAdmin();
        $album = $this->em->getRepository(Album::class)->findOneBy(['slug' => 'landscapes']);
        $this->assertNotNull($album);
        $photo = new Photo($album, '/tmp/original.jpg');
        $this->em->persist($photo);
        $this->em->flush();
        $albumId = (string) $album->getId();
        $photoId = (string) $photo->getId();

        $this->client->request('DELETE', '/api/admin/albums/'.$albumId);

        $this->assertResponseStatusCodeSame(204);
        $this->em->clear();
        $this->assertNull($this->em->getRepository(Album::class)->find($albumId));
        $this->assertNull($this->em->getRepository(Photo::class)->find($photoId));
    }

    public function testDeleteEmptyAlbumSucceeds(): void
    {
        $this->loginAsAdmin();
        $album = $this->em->getRepository(Album::class)->findOneBy(['slug' => 'family-hidden']);

        $this->client->request('DELETE', '/api/admin/albums/'.$album->getId());

        $this->assertResponseStatusCodeSame(204);
    }
}
