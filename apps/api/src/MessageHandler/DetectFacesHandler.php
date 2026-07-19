<?php

namespace App\MessageHandler;

use App\Message\DetectFacesMessage;
use App\Service\FaceQueuePublisherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Bridges Messenger's `faces` transport to the plain Redis list
 * `gallery:faces` consumed by the Python InsightFace worker
 * (apps/worker-faces; see Task 7). Messenger keeps the "enqueue on convert
 * success" call site decoupled from delivery and gives us retries; this
 * handler is the thin adapter that republishes the minimal JSON contract
 * the Python side expects instead of Messenger's own Redis envelope format.
 */
#[AsMessageHandler]
final class DetectFacesHandler
{
    public function __construct(
        private readonly FaceQueuePublisherInterface $publisher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(DetectFacesMessage $message): void
    {
        $this->publisher->publish($message->getPhotoId());

        $this->logger->info('Bridged detect_faces to gallery:faces for photo {photoId}.', [
            'photoId' => $message->getPhotoId(),
        ]);
    }
}
