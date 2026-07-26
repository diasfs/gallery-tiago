<?php

namespace App\Tests\MessageHandler;

use App\Message\SuggestTagsMessage;
use App\MessageHandler\SuggestTagsHandler;
use App\Tests\Fake\InMemoryTagQueuePublisher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SuggestTagsHandlerTest extends KernelTestCase
{
    public function testHandlerPublishesPhotoIdToTagQueue(): void
    {
        self::bootKernel();

        $handler = static::getContainer()->get(SuggestTagsHandler::class);
        $handler(new SuggestTagsMessage('11111111-1111-1111-1111-111111111111'));

        /** @var InMemoryTagQueuePublisher $publisher */
        $publisher = static::getContainer()->get(InMemoryTagQueuePublisher::class);

        $this->assertSame(['11111111-1111-1111-1111-111111111111'], $publisher->getPublished());
    }

    public function testHandlerPublishesEachPhotoIndependently(): void
    {
        self::bootKernel();

        $handler = static::getContainer()->get(SuggestTagsHandler::class);
        $handler(new SuggestTagsMessage('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'));
        $handler(new SuggestTagsMessage('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'));

        /** @var InMemoryTagQueuePublisher $publisher */
        $publisher = static::getContainer()->get(InMemoryTagQueuePublisher::class);

        $this->assertSame(
            ['aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'],
            $publisher->getPublished(),
        );
    }
}
