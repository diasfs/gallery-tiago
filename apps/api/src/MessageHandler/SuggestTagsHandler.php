<?php

namespace App\MessageHandler;

use App\Message\SuggestTagsMessage;
use App\Service\TagQueuePublisherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Bridges Messenger's `tags` transport to the Redis stream
 * `gallery:tags:stream` consumed by the Python tag worker (apps/worker-tags).
 * Same adapter pattern as DetectFacesHandler.
 */
#[AsMessageHandler]
final class SuggestTagsHandler
{
    public function __construct(
        private readonly TagQueuePublisherInterface $publisher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SuggestTagsMessage $message): void
    {
        $this->publisher->publish($message->getPhotoId());

        $this->logger->info('Bridged suggest_tags to gallery:tags:stream for photo {photoId}.', [
            'photoId' => $message->getPhotoId(),
        ]);
    }
}
