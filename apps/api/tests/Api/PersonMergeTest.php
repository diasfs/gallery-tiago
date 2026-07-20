<?php

namespace App\Tests\Api;

use App\Entity\Album;
use App\Entity\AdminUser;
use App\Entity\Face;
use App\Entity\Person;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use App\Message\ConvertMediaMessage;
use App\Message\DetectFacesMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PersonMergeTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'people-admin@gallery.test';
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
        $this->convertTransport()->reset();
        $this->facesTransport()->reset();
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

        $this->privateAlbum = new Album('Secret', 'secret-'.uniqid());
        $this->privateAlbum->setVisibility(AlbumVisibility::Private);
        $this->em->persist($this->privateAlbum);

        $this->publicPhoto = new Photo($this->publicAlbum, 'originals/aa/aaaa.jpg');
        $this->publicPhoto->setAvifPath('converted/aa/aaaa/master.avif');
        $this->em->persist($this->publicPhoto);

        $this->privatePhoto = new Photo($this->privateAlbum, 'originals/bb/bbbb.jpg');
        $this->privatePhoto->setAvifPath('converted/bb/bbbb/master.avif');
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

    private function convertTransport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.convert');
    }

    private function facesTransport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.faces');
    }

    /** @return float[] */
    private function embedding(): array
    {
        return array_fill(0, 512, 0.01);
    }

    private function detectedFace(Photo $photo, ?Person $person): Face
    {
        $face = new Face($photo);
        $face->setPerson($person);
        $face->setEmbedding($this->embedding());
        $face->setCropPath('faces/aa/'.$photo->getId().'/1.jpg');
        $this->em->persist($face);

        return $face;
    }

    private function manualFace(Photo $photo, ?Person $person): Face
    {
        $face = new Face($photo);
        $face->setPerson($person);
        $this->em->persist($face);

        return $face;
    }

    // --- Merge ---------------------------------------------------------

    public function testMergeReassignsFacesAndDeletesSourcePerson(): void
    {
        $source = new Person();
        $this->em->persist($source);
        $target = new Person();
        $target->setName('Ana');
        $target->setIsNamed(true);
        $this->em->persist($target);

        $sourceFace = $this->detectedFace($this->publicPhoto, $source);
        $targetFace = $this->detectedFace($this->privatePhoto, $target);
        $this->em->flush();

        $sourceId = (string) $source->getId();
        $targetId = (string) $target->getId();
        $sourceFaceId = (string) $sourceFace->getId();

        $this->loginAsAdmin();

        $this->client->jsonRequest('POST', '/api/admin/people/'.$sourceId.'/merge', [
            'targetPersonId' => $targetId,
        ]);

        $this->assertResponseIsSuccessful();

        $this->em->clear();

        $this->assertNull($this->em->getRepository(Person::class)->find($sourceId));

        $reassignedFace = $this->em->getRepository(Face::class)->find($sourceFaceId);
        $this->assertNotNull($reassignedFace);
        $this->assertSame($targetId, (string) $reassignedFace->getPerson()->getId());

        $remainingTarget = $this->em->getRepository(Person::class)->find($targetId);
        $this->assertCount(2, $remainingTarget->getFaces());
    }

    public function testMergeRequiresAuthentication(): void
    {
        $source = new Person();
        $this->em->persist($source);
        $target = new Person();
        $this->em->persist($target);
        $this->em->flush();

        $this->client->jsonRequest('POST', '/api/admin/people/'.$source->getId().'/merge', [
            'targetPersonId' => (string) $target->getId(),
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testMergeIntoSelfReturns400(): void
    {
        $person = new Person();
        $this->em->persist($person);
        $this->em->flush();

        $this->loginAsAdmin();

        $this->client->jsonRequest('POST', '/api/admin/people/'.$person->getId().'/merge', [
            'targetPersonId' => (string) $person->getId(),
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testMergeWithUnknownTargetReturns404(): void
    {
        $person = new Person();
        $this->em->persist($person);
        $this->em->flush();

        $this->loginAsAdmin();

        $this->client->jsonRequest('POST', '/api/admin/people/'.$person->getId().'/merge', [
            'targetPersonId' => '00000000-0000-0000-0000-000000000000',
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    // --- Unnamed listing -------------------------------------------------

    public function testUnnamedListingReturnsOnlyUnnamedClustersWithFaceCrops(): void
    {
        $unnamed = new Person();
        $this->em->persist($unnamed);
        $named = new Person();
        $named->setName('Ana');
        $named->setIsNamed(true);
        $this->em->persist($named);

        $face = $this->detectedFace($this->publicPhoto, $unnamed);
        $this->detectedFace($this->publicPhoto, $named);
        $this->em->flush();

        $this->loginAsAdmin();

        $this->client->request('GET', '/api/admin/people/unnamed');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];

        $this->assertCount(1, $data);
        $this->assertSame((string) $unnamed->getId(), $data[0]['id']);
        $this->assertCount(1, $data[0]['faces']);
        $this->assertSame((string) $face->getCropPath(), $data[0]['faces'][0]['cropPath']);
    }

    public function testUnnamedListingRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/admin/people/unnamed');
        $this->assertResponseStatusCodeSame(401);
    }

    // --- Naming -----------------------------------------------------------

    public function testAdminCanNamePerson(): void
    {
        $person = new Person();
        $this->em->persist($person);
        $this->em->flush();

        $this->loginAsAdmin();

        $this->client->jsonRequest('POST', '/api/admin/people/'.$person->getId().'/name', [
            'name' => 'Ana',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('Ana', $data['name']);
        $this->assertTrue($data['isNamed']);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Person::class)->find($person->getId());
        $this->assertSame('Ana', $reloaded->getName());
        $this->assertTrue($reloaded->isNamed());
    }

    public function testNamingWithBlankNameReturns400(): void
    {
        $person = new Person();
        $this->em->persist($person);
        $this->em->flush();

        $this->loginAsAdmin();

        $this->client->jsonRequest('POST', '/api/admin/people/'.$person->getId().'/name', [
            'name' => '   ',
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testNamingUnknownPersonReturns404(): void
    {
        $this->loginAsAdmin();

        $this->client->jsonRequest('POST', '/api/admin/people/00000000-0000-0000-0000-000000000000/name', [
            'name' => 'Ana',
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    // --- Discard ------------------------------------------------------------

    public function testAdminCanDiscardUnnamedCluster(): void
    {
        $person = new Person();
        $this->em->persist($person);
        $face = $this->detectedFace($this->publicPhoto, $person);
        $this->em->flush();
        $faceId = (string) $face->getId();
        $personId = (string) $person->getId();

        $this->loginAsAdmin();

        $this->client->request('DELETE', '/api/admin/people/'.$personId);

        $this->assertResponseStatusCodeSame(204);

        $this->em->clear();
        $this->assertNull($this->em->getRepository(Person::class)->find($personId));
        $this->assertNull($this->em->getRepository(Face::class)->find($faceId));
    }

    public function testAdminCanDeleteNamedPerson(): void
    {
        $person = new Person();
        $person->setName('Ana');
        $person->setIsNamed(true);
        $this->em->persist($person);
        $face = $this->detectedFace($this->publicPhoto, $person);
        $this->em->flush();
        $personId = (string) $person->getId();
        $faceId = (string) $face->getId();

        $this->loginAsAdmin();

        $this->client->request('DELETE', '/api/admin/people/'.$personId);

        $this->assertResponseStatusCodeSame(204);

        $this->em->clear();
        $this->assertNull($this->em->getRepository(Person::class)->find($personId));
        $this->assertNull($this->em->getRepository(Face::class)->find($faceId));
    }

    // --- List / detail / avatar --------------------------------------------

    public function testAdminCanListAllPeople(): void
    {
        $unnamed = new Person();
        $this->em->persist($unnamed);
        $named = new Person();
        $named->setName('Ana');
        $named->setIsNamed(true);
        $this->em->persist($named);
        $face = $this->detectedFace($this->publicPhoto, $named);
        $named->setAvatarFace($face);
        $this->em->flush();

        $this->loginAsAdmin();

        $this->client->request('GET', '/api/admin/people?scope=all');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $ids = array_column($data, 'id');
        $this->assertContains((string) $unnamed->getId(), $ids);
        $this->assertContains((string) $named->getId(), $ids);

        $namedRow = null;
        foreach ($data as $row) {
            if ($row['id'] === (string) $named->getId()) {
                $namedRow = $row;
                break;
            }
        }
        $this->assertNotNull($namedRow);
        $this->assertSame((string) $face->getId(), $namedRow['avatarFaceId']);
        $this->assertSame($face->getCropPath(), $namedRow['avatarCropPath']);
    }

    public function testAdminCanShowPersonDetailAndSetAvatar(): void
    {
        $person = new Person();
        $person->setName('Ana');
        $person->setIsNamed(true);
        $this->em->persist($person);
        $faceA = $this->detectedFace($this->publicPhoto, $person);
        $faceB = $this->detectedFace($this->privatePhoto, $person);

        $other = new Person();
        $this->em->persist($other);
        $otherFace = $this->detectedFace($this->publicPhoto, $other);
        $this->em->flush();

        // Keep ids — HTTP requests may clear the shared entity manager.
        $personId = (string) $person->getId();
        $faceBId = (string) $faceB->getId();
        $faceBCrop = $faceB->getCropPath();
        $otherFaceId = (string) $otherFace->getId();
        $this->assertNotNull($faceA->getId());

        $this->loginAsAdmin();

        $this->client->request('GET', '/api/admin/people/'.$personId);
        $this->assertResponseIsSuccessful();
        $detail = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('Ana', $detail['name']);
        $this->assertCount(2, $detail['faces']);
        $this->assertNull($detail['avatarFaceId']);

        $this->client->jsonRequest('PATCH', '/api/admin/people/'.$personId, [
            'avatarFaceId' => $faceBId,
            'name' => 'Ana Silva',
        ]);
        $this->assertResponseIsSuccessful();
        $updated = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('Ana Silva', $updated['name']);
        $this->assertSame($faceBId, $updated['avatarFaceId']);
        $this->assertSame($faceBCrop, $updated['avatarCropPath']);

        $this->client->jsonRequest('PATCH', '/api/admin/people/'.$personId, [
            'avatarFaceId' => $otherFaceId,
        ]);
        $this->assertResponseStatusCodeSame(400);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Person::class)->find($personId);
        $this->assertSame($faceBId, (string) $reloaded->getAvatarFace()->getId());
        $this->assertSame('Ana Silva', $reloaded->getName());
    }

    public function testDeletingPhotoKeepsFaceRowsAndCropPaths(): void
    {
        $person = new Person();
        $person->setName('Ana');
        $person->setIsNamed(true);
        $this->em->persist($person);
        $face = $this->detectedFace($this->publicPhoto, $person);
        $person->setAvatarFace($face);
        $this->em->flush();
        $faceId = (string) $face->getId();
        $cropPath = $face->getCropPath();
        $photoId = (string) $this->publicPhoto->getId();

        $this->loginAsAdmin();

        $this->client->request('DELETE', '/api/admin/photos/'.$photoId);
        $this->assertResponseStatusCodeSame(204);

        $this->em->clear();
        $this->assertNull($this->em->getRepository(Photo::class)->find($photoId));

        $surviving = $this->em->getRepository(Face::class)->find($faceId);
        $this->assertNotNull($surviving);
        $this->assertNull($surviving->getPhoto());
        $this->assertSame($cropPath, $surviving->getCropPath());

        $reloadedPerson = $this->em->getRepository(Person::class)->find($person->getId());
        $this->assertNotNull($reloadedPerson->getAvatarFace());
        $this->assertSame($faceId, (string) $reloadedPerson->getAvatarFace()->getId());
    }

    public function testDiscardUnknownPersonReturns404(): void
    {
        $this->loginAsAdmin();

        $this->client->request('DELETE', '/api/admin/people/00000000-0000-0000-0000-000000000000');

        $this->assertResponseStatusCodeSame(404);
    }

    // --- Manual add/remove person on photo ----------------------------------

    public function testAdminCanManuallyAddPersonToPhoto(): void
    {
        $person = new Person();
        $person->setName('Ana');
        $person->setIsNamed(true);
        $this->em->persist($person);
        $this->em->flush();

        $this->loginAsAdmin();

        $this->client->jsonRequest('POST', '/api/admin/photos/'.$this->publicPhoto->getId().'/people', [
            'personId' => (string) $person->getId(),
        ]);

        $this->assertResponseStatusCodeSame(201);

        $this->em->clear();
        $photo = $this->em->getRepository(Photo::class)->find($this->publicPhoto->getId());
        $this->assertCount(1, $photo->getFaces());
        $addedFace = $photo->getFaces()->first();
        $this->assertFalse($addedFace->hasEmbedding());
        $this->assertSame((string) $person->getId(), (string) $addedFace->getPerson()->getId());
    }

    public function testManualAddDoesNotDuplicateExistingDetectedFace(): void
    {
        $person = new Person();
        $person->setName('Ana');
        $person->setIsNamed(true);
        $this->em->persist($person);
        $this->detectedFace($this->publicPhoto, $person);
        $this->em->flush();

        $this->loginAsAdmin();

        $this->client->jsonRequest('POST', '/api/admin/photos/'.$this->publicPhoto->getId().'/people', [
            'personId' => (string) $person->getId(),
        ]);

        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $photo = $this->em->getRepository(Photo::class)->find($this->publicPhoto->getId());
        $this->assertCount(1, $photo->getFaces());
        $this->assertTrue($photo->getFaces()->first()->hasEmbedding());
    }

    public function testAdminCanRemovePersonFromPhoto(): void
    {
        $person = new Person();
        $this->em->persist($person);
        $this->detectedFace($this->publicPhoto, $person);
        $this->manualFace($this->publicPhoto, $person);
        $this->em->flush();

        $this->loginAsAdmin();

        $this->client->request('DELETE', '/api/admin/photos/'.$this->publicPhoto->getId().'/people/'.$person->getId());

        $this->assertResponseStatusCodeSame(204);

        $this->em->clear();
        $photo = $this->em->getRepository(Photo::class)->find($this->publicPhoto->getId());
        $this->assertCount(0, $photo->getFaces());
    }

    public function testManualPhotoPeopleEndpointsRequireAuthentication(): void
    {
        $person = new Person();
        $this->em->persist($person);
        $this->em->flush();

        $this->client->jsonRequest('POST', '/api/admin/photos/'.$this->publicPhoto->getId().'/people', [
            'personId' => (string) $person->getId(),
        ]);
        $this->assertResponseStatusCodeSame(401);

        $this->client->request('DELETE', '/api/admin/photos/'.$this->publicPhoto->getId().'/people/'.$person->getId());
        $this->assertResponseStatusCodeSame(401);
    }

    // --- Public person photos ------------------------------------------------

    public function testPublicPersonPhotosOnlyReturnsPubliclyReachablePhotos(): void
    {
        $person = new Person();
        $person->setName('Ana');
        $person->setIsNamed(true);
        $this->em->persist($person);
        $this->detectedFace($this->publicPhoto, $person);
        $this->detectedFace($this->privatePhoto, $person);
        $this->em->flush();

        $this->client->request('GET', '/api/people/'.$person->getId().'/photos');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];

        $this->assertCount(1, $data);
        $this->assertSame((string) $this->publicPhoto->getId(), $data[0]['id']);
        $this->assertStringNotContainsString('originalPath', (string) $this->client->getResponse()->getContent());
    }

    public function testPublicPersonPhotosReturns404ForUnknownPerson(): void
    {
        $this->client->request('GET', '/api/people/00000000-0000-0000-0000-000000000000/photos');
        $this->assertResponseStatusCodeSame(404);
    }

    // --- Reprocess ------------------------------------------------------------

    public function testReprocessDeletesOnlyAutoDetectedFacesAndReenqueuesDetect(): void
    {
        $person = new Person();
        $this->em->persist($person);
        $autoFace = $this->detectedFace($this->publicPhoto, $person);
        $manualFace = $this->manualFace($this->publicPhoto, $person);
        $this->em->flush();

        $autoFaceId = (string) $autoFace->getId();
        $manualFaceId = (string) $manualFace->getId();

        $this->loginAsAdmin();

        $this->client->request('POST', '/api/admin/photos/'.$this->publicPhoto->getId().'/reprocess');

        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $this->assertNull($this->em->getRepository(Face::class)->find($autoFaceId));
        $this->assertNotNull($this->em->getRepository(Face::class)->find($manualFaceId));

        $sent = $this->facesTransport()->getSent();
        $this->assertCount(1, $sent);
        $this->assertInstanceOf(DetectFacesMessage::class, $sent[0]->getMessage());
        $this->assertCount(0, $this->convertTransport()->getSent());
    }

    public function testReprocessConvertsWhenAvifMissing(): void
    {
        $photo = new Photo($this->publicAlbum, 'originals/cc/cccc.jpg');
        $this->em->persist($photo);
        $this->em->flush();

        $this->loginAsAdmin();

        $this->client->request('POST', '/api/admin/photos/'.$photo->getId().'/reprocess');

        $this->assertResponseIsSuccessful();

        $sent = $this->convertTransport()->getSent();
        $this->assertCount(1, $sent);
        $this->assertInstanceOf(ConvertMediaMessage::class, $sent[0]->getMessage());
        $this->assertCount(0, $this->facesTransport()->getSent());
    }

    public function testReprocessRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/admin/photos/'.$this->publicPhoto->getId().'/reprocess');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testReprocessUnknownPhotoReturns404(): void
    {
        $this->loginAsAdmin();

        $this->client->request('POST', '/api/admin/photos/00000000-0000-0000-0000-000000000000/reprocess');

        $this->assertResponseStatusCodeSame(404);
    }
}
