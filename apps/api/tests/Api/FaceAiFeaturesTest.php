<?php

namespace App\Tests\Api;

use App\Entity\Album;
use App\Entity\AdminUser;
use App\Entity\Face;
use App\Entity\Person;
use App\Entity\Photo;
use App\Entity\Tag;
use App\Enum\AlbumVisibility;
use App\Tests\Fake\FakeFaceEmbeddingClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class FaceAiFeaturesTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'face-ai@gallery.test';
    private const ADMIN_PASSWORD = 'secret';

    private EntityManagerInterface $em;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        FakeFaceEmbeddingClient::reset();
        $this->clearFixtures();
        $this->loadAdmin();
    }

    protected function tearDown(): void
    {
        $this->clearFixtures();
        FakeFaceEmbeddingClient::reset();
        parent::tearDown();
    }

    private function clearFixtures(): void
    {
        foreach ($this->em->getRepository(Face::class)->findAll() as $face) {
            $this->em->remove($face);
        }
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
        foreach ($this->em->getRepository(Album::class)->findAll() as $album) {
            $this->em->remove($album);
        }
        foreach ($this->em->getRepository(AdminUser::class)->findAll() as $admin) {
            $this->em->remove($admin);
        }
        $this->em->flush();
    }

    /** @return float[] */
    private function unitEmbedding(int $dimension): array
    {
        $vector = array_fill(0, 512, 0.0);
        $vector[$dimension] = 1.0;

        return $vector;
    }

    private function faceWithEmbedding(Photo $photo, Person $person, int $dimension): Face
    {
        $face = new Face($photo);
        $face->setPerson($person);
        $face->setEmbedding($this->unitEmbedding($dimension));
        $face->setCropPath('faces/aa/'.$photo->getId().'.jpg');
        $this->em->persist($face);

        return $face;
    }

    public function testSimilarPhotosUsesFaceEmbeddings(): void
    {
        $album = new Album('Trip', 'trip-similar');
        $album->setVisibility(AlbumVisibility::Public);
        $this->em->persist($album);

        $source = new Photo($album, 'originals/aa/source.jpg');
        $similar = new Photo($album, 'originals/aa/similar.jpg');
        $other = new Photo($album, 'originals/aa/other.jpg');
        $this->em->persist($source);
        $this->em->persist($similar);
        $this->em->persist($other);

        $person = new Person();
        $this->em->persist($person);
        $this->faceWithEmbedding($source, $person, 0);
        $this->faceWithEmbedding($similar, $person, 0);
        $this->faceWithEmbedding($other, $person, 1);
        $this->em->flush();

        $this->client->request('GET', '/api/photos/'.$source->getId().'/similar');

        $this->assertResponseIsSuccessful();
        $ids = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['data'], 'id');
        $this->assertSame([(string) $similar->getId()], $ids);
    }

    public function testSimilarPhotosFallsBackToSharedTags(): void
    {
        $album = new Album('Tags', 'trip-tags');
        $album->setVisibility(AlbumVisibility::Public);
        $this->em->persist($album);

        $source = new Photo($album, 'originals/aa/source.jpg');
        $match = new Photo($album, 'originals/aa/match.jpg');
        $this->em->persist($source);
        $this->em->persist($match);

        $tag = new Tag('Beach', 'beach');
        $this->em->persist($tag);
        $source->getTags()->add($tag);
        $match->getTags()->add($tag);
        $this->em->flush();

        $this->client->request('GET', '/api/photos/'.$source->getId().'/similar');

        $this->assertResponseIsSuccessful();
        $ids = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['data'], 'id');
        $this->assertSame([(string) $match->getId()], $ids);
    }

    public function testMergeSuggestionsListsCloseUnnamedClusters(): void
    {
        $album = new Album('Faces', 'faces-merge');
        $album->setVisibility(AlbumVisibility::Private);
        $this->em->persist($album);
        $photo = new Photo($album, 'originals/aa/a.jpg');
        $this->em->persist($photo);

        $left = new Person();
        $right = new Person();
        $this->em->persist($left);
        $this->em->persist($right);
        $this->faceWithEmbedding($photo, $left, 2);
        $this->faceWithEmbedding($photo, $right, 2);
        $this->em->flush();

        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/people/merge-suggestions');

        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $data = $payload['data'];
        $this->assertCount(1, $data);
        $this->assertSame(2, $payload['meta']['unnamedClusterCount']);
        $this->assertSame(2, $payload['meta']['analyzedClusterCount']);
        $this->assertFalse($payload['meta']['truncated']);
        $this->assertSame('faces/aa/'.$photo->getId().'.jpg', $data[0]['sourceAvatarCropPath']);
        $this->assertSame('faces/aa/'.$photo->getId().'.jpg', $data[0]['targetAvatarCropPath']);
        $pair = [$data[0]['sourcePersonId'], $data[0]['targetPersonId']];
        sort($pair);
        $expected = [(string) $left->getId(), (string) $right->getId()];
        sort($expected);
        $this->assertSame($expected, $pair);
    }

    public function testMergeSuggestionsUsesRepresentativeFace(): void
    {
        $album = new Album('Faces', 'faces-merge-rep');
        $album->setVisibility(AlbumVisibility::Private);
        $this->em->persist($album);
        $photoA = new Photo($album, 'originals/aa/a.jpg');
        $photoB = new Photo($album, 'originals/bb/b.jpg');
        $this->em->persist($photoA);
        $this->em->persist($photoB);

        $left = new Person();
        $right = new Person();
        $this->em->persist($left);
        $this->em->persist($right);

        $representative = $this->faceWithEmbedding($photoA, $left, 99);
        $representative->setConfidence(0.5);
        $similarSecondary = $this->faceWithEmbedding($photoB, $left, 2);
        $similarSecondary->setConfidence(0.99);
        $left->setAvatarFace($representative);
        $this->faceWithEmbedding($photoA, $right, 2);
        $this->em->flush();

        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/people/merge-suggestions');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertCount(0, $data);
    }

    public function testSearchByFaceReturnsNearestPeople(): void
    {
        $album = new Album('Search', 'faces-search');
        $album->setVisibility(AlbumVisibility::Private);
        $this->em->persist($album);
        $photo = new Photo($album, 'originals/aa/a.jpg');
        $this->em->persist($photo);

        $close = new Person();
        $close->setName('Ana');
        $close->setIsNamed(true);
        $far = new Person();
        $far->setName('Bruno');
        $far->setIsNamed(true);
        $this->em->persist($close);
        $this->em->persist($far);
        $this->faceWithEmbedding($photo, $close, 3);
        $this->faceWithEmbedding($photo, $far, 40);
        $this->em->flush();

        FakeFaceEmbeddingClient::$nextEmbedding = $this->unitEmbedding(3);

        $this->loginAsAdmin();
        $this->client->request('POST', '/api/admin/people/search-by-face', [], [
            'file' => $this->fixtureUpload(),
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame((string) $close->getId(), $data[0]['personId']);
        $this->assertSame('Ana', $data[0]['name']);
    }

    private function loadAdmin(): void
    {
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

    private function fixtureUpload(): UploadedFile
    {
        $source = \dirname(__DIR__).'/fixtures/sample.jpg';
        $copy = tempnam(sys_get_temp_dir(), 'face-search').'.jpg';
        copy($source, $copy);

        return new UploadedFile($copy, 'face.jpg', 'image/jpeg', null, true);
    }
}
