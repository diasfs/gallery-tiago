<?php

namespace App\Tests\MessageHandler;

use App\Entity\Album;
use App\Entity\Photo;
use App\Message\ConvertMediaMessage;
use App\Message\DetectFacesMessage;
use App\Message\SuggestTagsMessage;
use App\MessageHandler\ConvertMediaHandler;
use App\Service\MediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ConvertMediaHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MediaStorage $storage;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->storage = static::getContainer()->get(MediaStorage::class);
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
        $this->em->flush();
    }

    private function makePhotoWithOriginal(string $fixtureName = 'sample.jpg'): Photo
    {
        $album = new Album('Test Album', 'test-album-'.uniqid());
        $this->em->persist($album);

        $photo = new Photo($album, '');
        $this->em->persist($photo);
        $this->em->flush();

        $photoId = (string) $photo->getId();
        $relative = \sprintf('originals/%s/%s.jpg', substr($photoId, 0, 2), $photoId);
        $absolute = $this->storage->absolutePath($relative);
        @mkdir(\dirname($absolute), 0775, true);
        copy(\dirname(__DIR__).'/fixtures/'.$fixtureName, $absolute);

        $photo->setOriginalPath($relative);
        $this->em->flush();

        return $photo;
    }

    public function testHandlerConvertsToAvifAndDispatchesDetectFacesAndSuggestTags(): void
    {
        $photo = $this->makePhotoWithOriginal();
        $originalRelative = $photo->getOriginalPath();
        $this->assertNotNull($originalRelative);
        $originalAbsolute = $this->storage->absolutePath($originalRelative);
        $this->assertFileExists($originalAbsolute);

        $handler = static::getContainer()->get(ConvertMediaHandler::class);
        $photoId = (string) $photo->getId();
        $handler(new ConvertMediaMessage($photoId));

        $photo = $this->em->find(Photo::class, $photoId);
        $this->assertNotNull($photo);

        $this->assertSame('done', $photo->getMediaStatus()->value);
        $this->assertSame('detecting', $photo->getFacesStatus()->value);
        $this->assertSame('detecting', $photo->getTagsStatus()->value);
        $this->assertNull($photo->getProcessingError());
        $this->assertNotNull($photo->getAvifPath());
        $this->assertNull($photo->getOriginalPath());
        $this->assertFileDoesNotExist($originalAbsolute);
        $this->assertSame(64, $photo->getWidth());
        $this->assertSame(48, $photo->getHeight());

        $thumbs = $photo->getThumbPaths();
        $this->assertArrayHasKey('320', $thumbs);
        $this->assertArrayHasKey('1280', $thumbs);

        $this->assertFileExists($this->storage->absolutePath($photo->getAvifPath()));
        foreach ($thumbs as $path) {
            $this->assertFileExists($this->storage->absolutePath($path));
        }

        /** @var InMemoryTransport $facesTransport */
        $facesTransport = static::getContainer()->get('messenger.transport.faces');
        $facesSent = $facesTransport->getSent();
        $this->assertCount(1, $facesSent);
        $this->assertInstanceOf(DetectFacesMessage::class, $facesSent[0]->getMessage());
        $this->assertSame($photoId, $facesSent[0]->getMessage()->getPhotoId());

        /** @var InMemoryTransport $tagsTransport */
        $tagsTransport = static::getContainer()->get('messenger.transport.tags');
        $tagsSent = $tagsTransport->getSent();
        $this->assertCount(1, $tagsSent);
        $this->assertInstanceOf(SuggestTagsMessage::class, $tagsSent[0]->getMessage());
        $this->assertSame($photoId, $tagsSent[0]->getMessage()->getPhotoId());
    }

    public function testHandlerMarksPhotoFailedWhenOriginalMissing(): void
    {
        $album = new Album('Test Album', 'test-album-'.uniqid());
        $this->em->persist($album);
        $photo = new Photo($album, 'originals/does/not/exist.jpg');
        $this->em->persist($photo);
        $this->em->flush();

        $handler = static::getContainer()->get(ConvertMediaHandler::class);
        $photoId = (string) $photo->getId();
        $handler(new ConvertMediaMessage($photoId));

        $photo = $this->em->find(Photo::class, $photoId);
        $this->assertNotNull($photo);

        $this->assertSame('failed', $photo->getMediaStatus()->value);
        $this->assertSame('pending', $photo->getFacesStatus()->value);
        $this->assertSame('pending', $photo->getTagsStatus()->value);
        $this->assertSame('originals/does/not/exist.jpg', $photo->getOriginalPath());
        $this->assertStringStartsWith('media:', $photo->getProcessingError());

        /** @var InMemoryTransport $facesTransport */
        $facesTransport = static::getContainer()->get('messenger.transport.faces');
        $this->assertCount(0, $facesTransport->getSent());

        /** @var InMemoryTransport $tagsTransport */
        $tagsTransport = static::getContainer()->get('messenger.transport.tags');
        $this->assertCount(0, $tagsTransport->getSent());
    }

    public function testHandlerIgnoresUnknownPhotoId(): void
    {
        $handler = static::getContainer()->get(ConvertMediaHandler::class);

        // Should not throw even though no Photo exists for this id.
        $handler(new ConvertMediaMessage('00000000-0000-0000-0000-000000000000'));

        $this->addToAssertionCount(1);
    }
}
