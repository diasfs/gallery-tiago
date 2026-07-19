<?php

namespace App\Tests\MessageHandler;

use App\Message\DetectFacesMessage;
use App\MessageHandler\DetectFacesHandler;
use App\Tests\Fake\InMemoryFaceQueuePublisher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DetectFacesHandlerTest extends KernelTestCase
{
    public function testHandlerPublishesPhotoIdToFaceQueue(): void
    {
        self::bootKernel();

        $handler = static::getContainer()->get(DetectFacesHandler::class);
        $handler(new DetectFacesMessage('11111111-1111-1111-1111-111111111111'));

        /** @var InMemoryFaceQueuePublisher $publisher */
        $publisher = static::getContainer()->get(InMemoryFaceQueuePublisher::class);

        $this->assertSame(['11111111-1111-1111-1111-111111111111'], $publisher->getPublished());
    }

    public function testHandlerPublishesEachPhotoIndependently(): void
    {
        self::bootKernel();

        $handler = static::getContainer()->get(DetectFacesHandler::class);
        $handler(new DetectFacesMessage('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'));
        $handler(new DetectFacesMessage('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'));

        /** @var InMemoryFaceQueuePublisher $publisher */
        $publisher = static::getContainer()->get(InMemoryFaceQueuePublisher::class);

        $this->assertSame(
            ['aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'],
            $publisher->getPublished(),
        );
    }
}
