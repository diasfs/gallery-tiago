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

final class AdminPhotoFaceReassignTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'face-reassign-admin@gallery.test';
    private const ADMIN_PASSWORD = 'correct-horse-battery-staple';

    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private Album $album;
    private Photo $photo;

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

    public function testReassignFaceToExistingPersonKeepsLeftover(): void
    {
        $wrong = $this->namedPerson('Wrong');
        $right = $this->namedPerson('Right');
        $face = $this->faceWithBbox($this->photo, $wrong);
        $this->em->flush();

        $wrongId = (string) $wrong->getId();
        $rightId = (string) $right->getId();
        $faceId = (string) $face->getId();
        $photoId = (string) $this->photo->getId();

        $this->loginAsAdmin();
        $this->client->jsonRequest('PATCH', '/api/admin/photos/'.$photoId.'/faces/'.$faceId, [
            'personId' => $rightId,
        ]);
        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame($faceId, $payload['data']['id']);
        $this->assertSame($rightId, $payload['data']['personId']);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Face::class)->find($faceId);
        $this->assertNotNull($reloaded);
        $this->assertSame($rightId, (string) $reloaded->getPerson()?->getId());

        $leftover = $this->em->getRepository(Person::class)->find($wrongId);
        $this->assertNotNull($leftover);
        $this->assertNull($leftover->getDeletedAt());
        $this->assertSame('Wrong', $leftover->getName());
    }

    public function testReassignFaceByNameCreatesNamedPerson(): void
    {
        $wrong = $this->namedPerson('Wrong');
        $face = $this->faceWithBbox($this->photo, $wrong);
        $this->em->flush();
        $faceId = (string) $face->getId();
        $photoId = (string) $this->photo->getId();

        $this->loginAsAdmin();
        $this->client->jsonRequest('PATCH', '/api/admin/photos/'.$photoId.'/faces/'.$faceId, [
            'name' => 'Grace Hopper',
        ]);
        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertNotNull($payload['data']['personId']);

        $this->em->clear();
        $created = $this->em->getRepository(Person::class)->find($payload['data']['personId']);
        $this->assertNotNull($created);
        $this->assertSame('Grace Hopper', $created->getName());
        $this->assertTrue($created->isNamed());
    }

    public function testReassignSamePersonIsNoOp(): void
    {
        $person = $this->namedPerson('Ana');
        $face = $this->faceWithBbox($this->photo, $person);
        $this->em->flush();
        $personId = (string) $person->getId();
        $faceId = (string) $face->getId();
        $photoId = (string) $this->photo->getId();

        $this->loginAsAdmin();
        $this->client->jsonRequest('PATCH', '/api/admin/photos/'.$photoId.'/faces/'.$faceId, [
            'personId' => $personId,
        ]);
        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame($personId, $payload['data']['personId']);
    }

    public function testReassignAllowsSecondFaceOfSamePersonOnPhoto(): void
    {
        $alice = $this->namedPerson('Alice');
        $bob = $this->namedPerson('Bob');
        $faceAlice = $this->faceWithBbox($this->photo, $alice);
        $faceBob = $this->faceWithBbox($this->photo, $bob);
        $faceBob->setX(200.0);
        $this->em->flush();

        $aliceId = (string) $alice->getId();
        $faceBobId = (string) $faceBob->getId();
        $photoId = (string) $this->photo->getId();

        $this->loginAsAdmin();
        $this->client->jsonRequest('PATCH', '/api/admin/photos/'.$photoId.'/faces/'.$faceBobId, [
            'personId' => $aliceId,
        ]);
        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $this->assertSame($aliceId, (string) $this->em->getRepository(Face::class)->find($faceAlice->getId())?->getPerson()?->getId());
        $this->assertSame($aliceId, (string) $this->em->getRepository(Face::class)->find($faceBobId)?->getPerson()?->getId());
    }

    public function testReassignClearsSourceAvatarFace(): void
    {
        $wrong = $this->namedPerson('Wrong');
        $right = $this->namedPerson('Right');
        $face = $this->faceWithBbox($this->photo, $wrong);
        $this->em->flush();
        $wrong->setAvatarFace($face);
        $this->em->flush();

        $wrongId = (string) $wrong->getId();
        $rightId = (string) $right->getId();
        $faceId = (string) $face->getId();
        $photoId = (string) $this->photo->getId();

        $this->loginAsAdmin();
        $this->client->jsonRequest('PATCH', '/api/admin/photos/'.$photoId.'/faces/'.$faceId, [
            'personId' => $rightId,
        ]);
        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $leftover = $this->em->getRepository(Person::class)->find($wrongId);
        $this->assertNotNull($leftover);
        $this->assertNull($leftover->getAvatarFace());
    }

    public function testReassignUnknownFaceIs404(): void
    {
        $this->loginAsAdmin();
        $this->client->jsonRequest(
            'PATCH',
            '/api/admin/photos/'.(string) $this->photo->getId().'/faces/00000000-0000-4000-8000-000000000001',
            ['personId' => '00000000-0000-4000-8000-000000000002'],
        );
        $this->assertResponseStatusCodeSame(404);
    }

    public function testReassignFaceOnOtherPhotoIs404(): void
    {
        $otherPhoto = new Photo($this->album, 'originals/aa/other.jpg');
        $this->em->persist($otherPhoto);
        $wrong = $this->namedPerson('Wrong');
        $right = $this->namedPerson('Right');
        $face = $this->faceWithBbox($otherPhoto, $wrong);
        $this->em->flush();

        $this->loginAsAdmin();
        $this->client->jsonRequest(
            'PATCH',
            '/api/admin/photos/'.(string) $this->photo->getId().'/faces/'.(string) $face->getId(),
            ['personId' => (string) $right->getId()],
        );
        $this->assertResponseStatusCodeSame(404);
    }

    public function testReassignSoftDeletedFaceIs404(): void
    {
        $wrong = $this->namedPerson('Wrong');
        $right = $this->namedPerson('Right');
        $face = $this->faceWithBbox($this->photo, $wrong);
        $face->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->loginAsAdmin();
        $this->client->jsonRequest(
            'PATCH',
            '/api/admin/photos/'.(string) $this->photo->getId().'/faces/'.(string) $face->getId(),
            ['personId' => (string) $right->getId()],
        );
        $this->assertResponseStatusCodeSame(404);
    }

    public function testGetPhotoFacesIncludeCropPathAndZeroBbox(): void
    {
        $person = $this->namedPerson('Ana');
        $boxed = $this->faceWithBbox($this->photo, $person);
        $manual = new Face($this->photo);
        $manual->setPerson($person);
        $this->em->persist($manual);
        $this->em->flush();

        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/photos/'.(string) $this->photo->getId());
        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        $byId = [];
        foreach ($payload['data']['faces'] as $row) {
            $byId[$row['id']] = $row;
        }
        $this->assertArrayHasKey((string) $boxed->getId(), $byId);
        $this->assertSame('faces/aa/'.$this->photo->getId().'/1.jpg', $byId[(string) $boxed->getId()]['cropPath']);
        $this->assertArrayHasKey((string) $manual->getId(), $byId);
        $this->assertEqualsWithDelta(0.0, $byId[(string) $manual->getId()]['width'], 0.001);
        $this->assertEqualsWithDelta(0.0, $byId[(string) $manual->getId()]['height'], 0.001);
        $this->assertNull($byId[(string) $manual->getId()]['cropPath']);
    }

    public function testReassignViaPersonRouteMovesFaceAndKeepsLeftover(): void
    {
        $wrong = $this->namedPerson('Wrong');
        $right = $this->namedPerson('Right');
        $face = $this->faceWithBbox($this->photo, $wrong);
        $this->em->flush();

        $wrongId = (string) $wrong->getId();
        $rightId = (string) $right->getId();
        $faceId = (string) $face->getId();

        $this->loginAsAdmin();
        $this->client->jsonRequest('PATCH', '/api/admin/people/'.$wrongId.'/faces/'.$faceId, [
            'personId' => $rightId,
        ]);
        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame($faceId, $payload['data']['id']);
        $this->assertSame($rightId, $payload['data']['personId']);

        $this->em->clear();
        $leftover = $this->em->getRepository(Person::class)->find($wrongId);
        $this->assertNotNull($leftover);
        $this->assertNull($leftover->getDeletedAt());
    }

    public function testReassignViaPersonRouteWorksForReferenceFaceWithoutPhoto(): void
    {
        $wrong = $this->namedPerson('Wrong');
        $right = $this->namedPerson('Right');
        $face = new Face(null);
        $face->setPerson($wrong);
        $face->setCropPath('faces/aa/ref.jpg');
        $this->em->persist($face);
        $this->em->flush();

        $this->loginAsAdmin();
        $this->client->jsonRequest(
            'PATCH',
            '/api/admin/people/'.(string) $wrong->getId().'/faces/'.(string) $face->getId(),
            ['personId' => (string) $right->getId()],
        );
        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame((string) $right->getId(), $payload['data']['personId']);
        $this->assertNull($payload['data']['photoId']);
    }

    public function testReassignViaPersonRouteForOtherPersonsFaceIs404(): void
    {
        $owner = $this->namedPerson('Owner');
        $other = $this->namedPerson('Other');
        $target = $this->namedPerson('Target');
        $face = $this->faceWithBbox($this->photo, $owner);
        $this->em->flush();

        $this->loginAsAdmin();
        $this->client->jsonRequest(
            'PATCH',
            '/api/admin/people/'.(string) $other->getId().'/faces/'.(string) $face->getId(),
            ['personId' => (string) $target->getId()],
        );
        $this->assertResponseStatusCodeSame(404);
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
        $this->album = new Album('Landscapes', 'landscapes-'.uniqid());
        $this->album->setVisibility(AlbumVisibility::Public);
        $this->em->persist($this->album);

        $this->photo = new Photo($this->album, 'originals/aa/reassign.jpg');
        $this->photo->setAvifPath('originals/aa/reassign.avif');
        $this->photo->setWidth(1000);
        $this->photo->setHeight(800);
        $this->em->persist($this->photo);

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

    private function namedPerson(string $name): Person
    {
        $person = new Person();
        $person->setName($name);
        $person->setIsNamed(true);
        $this->em->persist($person);

        return $person;
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
}
