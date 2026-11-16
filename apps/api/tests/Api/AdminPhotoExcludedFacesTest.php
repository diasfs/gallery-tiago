<?php

namespace App\Tests\Api;

use App\Entity\Album;
use App\Entity\AdminUser;
use App\Entity\Face;
use App\Entity\Person;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminPhotoExcludedFacesTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'excluded-faces-admin@gallery.test';
    private const ADMIN_PASSWORD = 'correct-horse-battery-staple';

    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private Album $publicAlbum;
    private Photo $publicPhoto;

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
            $person->setAvatarPath(null);
        }
        $this->em->flush();
        foreach ($this->em->getRepository(Person::class)->findAll() as $person) {
            $this->em->remove($person);
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

    private function loadFixtures(): void
    {
        $this->publicAlbum = new Album('Landscapes', 'landscapes-'.uniqid());
        $this->publicAlbum->setVisibility(AlbumVisibility::Public);
        $this->em->persist($this->publicAlbum);

        $this->publicPhoto = new Photo($this->publicAlbum, 'originals/aa/excluded.jpg');
        $this->publicPhoto->setAvifPath('originals/aa/excluded.avif');
        $this->publicPhoto->setWidth(1000);
        $this->publicPhoto->setHeight(800);
        $this->em->persist($this->publicPhoto);

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

    private function faceWithBbox(Photo $photo, Person $person): Face
    {
        $face = new Face($photo);
        $face->setPerson($person);
        $face->setX(10.0);
        $face->setY(20.0);
        $face->setWidth(100.0);
        $face->setHeight(120.0);
        $face->setEmbedding(array_fill(0, 512, 0.01));
        $face->setCropPath('faces/aa/'.$photo->getId().'/1.jpg');
        $this->em->persist($face);

        return $face;
    }

    public function testRemoveFromPhotoSoftDeletesAndListsInExcludedFaces(): void
    {
        $person = new Person();
        $person->setName('Ana');
        $person->setIsNamed(true);
        $this->em->persist($person);
        $face = $this->faceWithBbox($this->publicPhoto, $person);
        $this->em->flush();
        $faceId = (string) $face->getId();
        $personId = (string) $person->getId();
        $photoId = (string) $this->publicPhoto->getId();

        $this->loginAsAdmin();
        $this->client->request('DELETE', '/api/admin/photos/'.$photoId.'/people/'.$personId);
        $this->assertResponseStatusCodeSame(204);

        $this->em->clear();
        $softDeleted = $this->em->getRepository(Face::class)->find($faceId);
        $this->assertNotNull($softDeleted);
        $this->assertNotNull($softDeleted->getDeletedAt());

        $this->client->request('GET', '/api/admin/photos/'.$photoId);
        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertSame([], $payload['data']['faces']);
        $this->assertSame([], $payload['data']['people']);
        $this->assertCount(1, $payload['data']['excludedFaces']);
        $this->assertSame($faceId, $payload['data']['excludedFaces'][0]['id']);
        $this->assertSame('removed', $payload['data']['excludedFaces'][0]['exclusionReason']);
        $this->assertNotNull($payload['data']['excludedFaces'][0]['faceDeletedAt']);
        $this->assertSame('faces/aa/'.$photoId.'/1.jpg', $payload['data']['excludedFaces'][0]['avatarCropPath']);
    }

    public function testRestoreSoftDeletedFace(): void
    {
        $person = new Person();
        $person->setName('Ana');
        $person->setIsNamed(true);
        $this->em->persist($person);
        $face = $this->faceWithBbox($this->publicPhoto, $person);
        $face->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();
        $faceId = (string) $face->getId();
        $photoId = (string) $this->publicPhoto->getId();

        $this->loginAsAdmin();
        $this->client->request('POST', '/api/admin/photos/'.$photoId.'/faces/'.$faceId.'/restore');
        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertCount(1, $payload['data']['faces']);
        $this->assertSame($faceId, $payload['data']['faces'][0]['id']);
        $this->assertSame([], $payload['data']['excludedFaces']);
        $this->assertCount(1, $payload['data']['people']);

        $this->em->clear();
        $restored = $this->em->getRepository(Face::class)->find($faceId);
        $this->assertNotNull($restored);
        $this->assertNull($restored->getDeletedAt());
    }

    public function testTrashedPersonFaceListedAsExcludedAndRestorable(): void
    {
        $person = new Person();
        $person->setName('Ana');
        $person->setIsNamed(true);
        $person->setDeletedAt(new \DateTimeImmutable());
        $this->em->persist($person);
        $face = $this->faceWithBbox($this->publicPhoto, $person);
        $this->em->flush();
        $faceId = (string) $face->getId();
        $personId = (string) $person->getId();
        $photoId = (string) $this->publicPhoto->getId();

        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/photos/'.$photoId);
        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertSame([], $payload['data']['faces']);
        $this->assertSame([], $payload['data']['people']);
        $this->assertCount(1, $payload['data']['excludedFaces']);
        $this->assertSame($faceId, $payload['data']['excludedFaces'][0]['id']);
        $this->assertSame('person_trashed', $payload['data']['excludedFaces'][0]['exclusionReason']);
        $this->assertNotNull($payload['data']['excludedFaces'][0]['personDeletedAt']);

        $this->client->request('POST', '/api/admin/people/'.$personId.'/restore');
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/api/admin/photos/'.$photoId);
        $this->assertResponseIsSuccessful();
        $after = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $after['data']['faces']);
        $this->assertSame([], $after['data']['excludedFaces']);
        $this->assertCount(1, $after['data']['people']);
    }

    public function testPublicPhotoOmitsSoftDeletedAndTrashedPersonFaces(): void
    {
        $active = new Person();
        $active->setName('Active');
        $active->setIsNamed(true);
        $this->em->persist($active);
        $this->faceWithBbox($this->publicPhoto, $active)->setX(200.0);

        $removed = new Person();
        $removed->setName('Removed');
        $removed->setIsNamed(true);
        $this->em->persist($removed);
        $removedFace = $this->faceWithBbox($this->publicPhoto, $removed);
        $removedFace->setX(400.0);
        $removedFace->setDeletedAt(new \DateTimeImmutable());

        $trashed = new Person();
        $trashed->setName('Trashed');
        $trashed->setIsNamed(true);
        $trashed->setDeletedAt(new \DateTimeImmutable());
        $this->em->persist($trashed);
        $this->faceWithBbox($this->publicPhoto, $trashed)->setX(600.0);

        $this->em->flush();
        $photoId = (string) $this->publicPhoto->getId();

        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/photos/'.$photoId);
        $this->assertResponseIsSuccessful();
        $adminPayload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $adminPayload['data']['people'], json_encode($adminPayload['data']));
        $this->assertCount(2, $adminPayload['data']['excludedFaces']);

        $this->client->request('GET', '/api/photos/'.$photoId);
        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $payload['data']['people'], json_encode($payload['data']));
        $this->assertSame('Active', $payload['data']['people'][0]['name']);
    }

    public function testReAddPersonToPhotoRestoresSoftDeletedFace(): void
    {
        $person = new Person();
        $person->setName('Ana');
        $person->setIsNamed(true);
        $this->em->persist($person);
        $face = $this->faceWithBbox($this->publicPhoto, $person);
        $face->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();
        $faceId = (string) $face->getId();
        $personId = (string) $person->getId();
        $photoId = (string) $this->publicPhoto->getId();

        $this->loginAsAdmin();
        $this->client->jsonRequest('POST', '/api/admin/photos/'.$photoId.'/people', [
            'personId' => $personId,
        ]);
        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame($faceId, $payload['data']['id']);

        $this->em->clear();
        $restored = $this->em->getRepository(Face::class)->find($faceId);
        $this->assertNotNull($restored);
        $this->assertNull($restored->getDeletedAt());
    }
}
