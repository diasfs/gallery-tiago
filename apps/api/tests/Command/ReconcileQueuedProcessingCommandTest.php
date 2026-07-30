<?php

namespace App\Tests\Command;

use App\Entity\Album;
use App\Entity\Photo;
use App\Enum\FacesStatus;
use App\Enum\TagsStatus;
use App\Message\DetectFacesMessage;
use App\Message\SuggestTagsMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ReconcileQueuedProcessingCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearFixtures();
        $this->resetTransports();
    }

    protected function tearDown(): void
    {
        $this->clearFixtures();
        parent::tearDown();
    }

    public function testReconcileEnqueuesQueuedFacesAndTags(): void
    {
        $album = new Album('Reconcile Album', 'reconcile-'.uniqid());
        $this->em->persist($album);

        $facesQueued = new Photo($album);
        $facesQueued->setTitle('faces-queued');
        $facesQueued->setAvifPath('avif/aa/a.avif');
        $facesQueued->setFacesStatus(FacesStatus::Queued);
        $facesQueued->setTagsStatus(TagsStatus::Done);
        $this->em->persist($facesQueued);

        $tagsQueued = new Photo($album);
        $tagsQueued->setTitle('tags-queued');
        $tagsQueued->setAvifPath('avif/bb/b.avif');
        $tagsQueued->setFacesStatus(FacesStatus::Done);
        $tagsQueued->setTagsStatus(TagsStatus::Queued);
        $this->em->persist($tagsQueued);

        $done = new Photo($album);
        $done->setTitle('done');
        $done->setAvifPath('avif/cc/c.avif');
        $done->setFacesStatus(FacesStatus::Done);
        $done->setTagsStatus(TagsStatus::Done);
        $this->em->persist($done);

        $this->em->flush();

        $application = new Application(self::$kernel);
        $command = $application->find('app:reconcile-queued-processing');
        $tester = new CommandTester($command);
        $tester->execute(['--stage' => 'all']);

        $this->assertSame(0, $tester->getStatusCode());

        /** @var InMemoryTransport $faces */
        $faces = static::getContainer()->get('messenger.transport.faces');
        $facesSent = $faces->getSent();
        $this->assertCount(1, $facesSent);
        $this->assertInstanceOf(DetectFacesMessage::class, $facesSent[0]->getMessage());
        $this->assertSame((string) $facesQueued->getId(), $facesSent[0]->getMessage()->getPhotoId());

        /** @var InMemoryTransport $tags */
        $tags = static::getContainer()->get('messenger.transport.tags');
        $tagsSent = $tags->getSent();
        $this->assertCount(1, $tagsSent);
        $this->assertInstanceOf(SuggestTagsMessage::class, $tagsSent[0]->getMessage());
        $this->assertSame((string) $tagsQueued->getId(), $tagsSent[0]->getMessage()->getPhotoId());
    }

    public function testDryRunDoesNotDispatch(): void
    {
        $album = new Album('Dry Album', 'dry-'.uniqid());
        $this->em->persist($album);
        $photo = new Photo($album);
        $photo->setFacesStatus(FacesStatus::Queued);
        $photo->setTagsStatus(TagsStatus::Queued);
        $this->em->persist($photo);
        $this->em->flush();

        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:reconcile-queued-processing'));
        $tester->execute(['--stage' => 'all', '--dry-run' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertCount(0, static::getContainer()->get('messenger.transport.faces')->getSent());
        $this->assertCount(0, static::getContainer()->get('messenger.transport.tags')->getSent());
    }

    private function resetTransports(): void
    {
        static::getContainer()->get('messenger.transport.faces')->reset();
        static::getContainer()->get('messenger.transport.tags')->reset();
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
}
