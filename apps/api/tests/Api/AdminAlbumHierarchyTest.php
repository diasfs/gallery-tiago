<?php

namespace App\Tests\Api;

use App\Entity\AdminUser;
use App\Entity\Album;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminAlbumHierarchyTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'hierarchy-admin@gallery.test';
    private const ADMIN_PASSWORD = 'correct-horse-battery-staple';

    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private Album $parent;
    private Album $child;
    private Photo $childPhoto;

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
            $album->setCoverPhoto(null);
            $album->setParent(null);
        }
        $this->em->flush();
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
        $this->parent = new Album('Trips', 'trips');
        $this->parent->setVisibility(AlbumVisibility::Public);
        $this->em->persist($this->parent);

        $this->child = new Album('San Diego', 'usa-sandiego');
        $this->child->setVisibility(AlbumVisibility::Public);
        $this->child->setParent($this->parent);
        $this->em->persist($this->child);

        $this->childPhoto = new Photo($this->child, 'originals/aa/cover.jpg');
        $this->childPhoto->setTitle('Harbor');
        $this->childPhoto->setAvifPath('converted/aa/cover/master.avif');
        $this->childPhoto->setThumbPaths(['320' => 'converted/aa/cover/thumb-320.avif']);
        $this->em->persist($this->childPhoto);

        $this->child->setCoverPhoto($this->childPhoto);

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

    public function testShowIncludesParentAndCoverWithoutEmbeddedChildren(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/albums/'.$this->parent->getId());

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];

        $this->assertNull($data['cover']);
        $this->assertNull($data['parent']);
        $this->assertSame(1, $data['childCount']);
        $this->assertArrayNotHasKey('children', $data);
    }

    public function testChildrenEndpointReturnsPaginatedCoverSummary(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/albums/'.$this->parent->getId().'/children');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(1, $body['meta']['total']);
        $this->assertCount(1, $body['data']);
        $this->assertSame((string) $this->child->getId(), $body['data'][0]['id']);
        $this->assertSame('San Diego', $body['data'][0]['title']);
        $this->assertNotNull($body['data'][0]['cover']);
        $this->assertSame((string) $this->childPhoto->getId(), $body['data'][0]['cover']['id']);
        $this->assertSame('converted/aa/cover/master.avif', $body['data'][0]['cover']['avifPath']);
        $this->assertSame(
            ['320' => 'converted/aa/cover/thumb-320.avif'],
            $body['data'][0]['cover']['thumbPaths'],
        );
        $this->assertSame('originals/aa/cover.jpg', $body['data'][0]['cover']['originalPath']);
    }

    public function testChildrenEndpointOrdersByRecencyLikePublic(): void
    {
        $this->child->setSortOrder(1);
        $this->child->setLegacyId(100);

        $newer = new Album('Newer Child', 'newer-child');
        $newer->setVisibility(AlbumVisibility::Public);
        $newer->setParent($this->parent);
        $newer->setSortOrder(99);
        $newer->setLegacyId(500);
        $this->em->persist($newer);

        $native = new Album('Native Child', 'native-child');
        $native->setVisibility(AlbumVisibility::Private);
        $native->setParent($this->parent);
        $native->setSortOrder(0);
        $this->em->persist($native);

        $this->em->flush();

        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/albums/'.$this->parent->getId().'/children');

        $this->assertResponseIsSuccessful();
        $slugs = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['data'], 'slug');
        $this->assertSame(['native-child', 'newer-child', 'usa-sandiego'], $slugs);
    }

    public function testAdminCanReorderRootAlbums(): void
    {
        $second = new Album('Second Root', 'second-root');
        $second->setVisibility(AlbumVisibility::Public);
        $second->setSortOrder(1);
        $this->em->persist($second);
        $this->em->flush();

        $this->loginAsAdmin();
        $this->client->jsonRequest('PUT', '/api/admin/albums/order', [
            'albumIds' => [(string) $second->getId(), (string) $this->parent->getId()],
        ]);

        $this->assertResponseIsSuccessful();
        $ids = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['data'], 'id');
        $this->assertSame([(string) $second->getId(), (string) $this->parent->getId()], $ids);
    }

    public function testShowIncludesParentSummaryForChildAlbum(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/albums/'.$this->child->getId());

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];

        $this->assertSame(
            [
                'id' => (string) $this->parent->getId(),
                'title' => 'Trips',
            ],
            $data['parent'],
        );
    }

    public function testListReturnsRootsOnlyWithMeta(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/albums');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $data = $body['data'];
        $this->assertSame(1, $body['meta']['total']);
        $this->assertSame(1, $body['meta']['page']);
        $byId = [];
        foreach ($data as $row) {
            $byId[$row['id']] = $row;
        }

        $this->assertArrayHasKey((string) $this->parent->getId(), $byId);
        $this->assertArrayNotHasKey((string) $this->child->getId(), $byId);
        $this->assertNull($byId[(string) $this->parent->getId()]['cover']);
        $this->assertSame(1, $byId[(string) $this->parent->getId()]['childCount']);
        $this->assertArrayNotHasKey('children', $byId[(string) $this->parent->getId()]);
    }

    public function testListSearchIncludesSubAlbums(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/albums?q=San+Diego');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(1, $body['meta']['total']);
        $this->assertCount(1, $body['data']);
        $this->assertSame((string) $this->child->getId(), $body['data'][0]['id']);
        $this->assertSame('San Diego', $body['data'][0]['title']);
        $this->assertSame((string) $this->parent->getId(), $body['data'][0]['parentId']);
    }

    public function testListWithoutQueryStillRootsOnly(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/albums?q=');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $ids = array_column($body['data'], 'id');
        $this->assertContains((string) $this->parent->getId(), $ids);
        $this->assertNotContains((string) $this->child->getId(), $ids);
    }

    public function testParentCanUseChildCoverPhoto(): void
    {
        $this->loginAsAdmin();
        $this->client->jsonRequest('PATCH', '/api/admin/albums/'.$this->parent->getId(), [
            'coverPhotoId' => (string) $this->childPhoto->getId(),
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame((string) $this->childPhoto->getId(), $data['coverPhotoId']);
        $this->assertSame((string) $this->childPhoto->getId(), $data['cover']['id']);
        $this->assertSame('converted/aa/cover/master.avif', $data['cover']['avifPath']);
    }

    public function testAlbumPhotosEndpointReturnsMeta(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/albums/'.$this->child->getId().'/photos?page=1&perPage=1');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(1, $body['meta']['page']);
        $this->assertSame(1, $body['meta']['perPage']);
        $this->assertSame(1, $body['meta']['total']);
        $this->assertCount(1, $body['data']);
        $this->assertSame((string) $this->childPhoto->getId(), $body['data'][0]['id']);
    }

    public function testCreateDefaultsPhotosPerPageToFortyEight(): void
    {
        $this->loginAsAdmin();
        $this->client->jsonRequest('POST', '/api/admin/albums', [
            'title' => 'Paged',
            'slug' => 'paged-album',
        ]);

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame(48, $data['photosPerPage']);
    }

    public function testUpdatePhotosPerPage(): void
    {
        $this->loginAsAdmin();
        $this->client->jsonRequest('PATCH', '/api/admin/albums/'.$this->parent->getId(), [
            'photosPerPage' => 30,
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame(30, $data['photosPerPage']);

        $this->em->clear();
        $album = $this->em->getRepository(Album::class)->find($this->parent->getId());
        $this->assertNotNull($album);
        $this->assertSame(30, $album->getPhotosPerPage());
    }

    public function testRejectsInvalidPhotosPerPage(): void
    {
        $this->loginAsAdmin();
        $this->client->jsonRequest('PATCH', '/api/admin/albums/'.$this->parent->getId(), [
            'photosPerPage' => 0,
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testMoveChildAlbumToAnotherParent(): void
    {
        $other = new Album('Europe', 'europe');
        $other->setVisibility(AlbumVisibility::Public);
        $this->em->persist($other);
        $this->em->flush();

        $this->loginAsAdmin();
        $this->client->jsonRequest('PATCH', '/api/admin/albums/'.$this->child->getId(), [
            'parentId' => (string) $other->getId(),
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame((string) $other->getId(), $data['parentId']);

        $this->em->clear();
        $child = $this->em->getRepository(Album::class)->find($this->child->getId());
        $this->assertNotNull($child);
        $this->assertSame((string) $other->getId(), (string) $child->getParent()?->getId());
    }

    public function testRejectsMovingAlbumUnderItsDescendant(): void
    {
        $grandchild = new Album('Downtown', 'downtown');
        $grandchild->setVisibility(AlbumVisibility::Public);
        $grandchild->setParent($this->child);
        $this->em->persist($grandchild);
        $this->em->flush();

        $this->loginAsAdmin();
        $this->client->jsonRequest('PATCH', '/api/admin/albums/'.$this->parent->getId(), [
            'parentId' => (string) $grandchild->getId(),
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testParentOptionsListsMatchingAlbums(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/albums/parent-options?q=San+Diego&exclude='.(string) $this->child->getId());

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('meta', $body);
        $ids = array_column($body['data'], 'id');
        $this->assertContains((string) $this->parent->getId(), $ids);
        $this->assertNotContains((string) $this->child->getId(), $ids);
    }
}
