<?php

namespace App\MessageHandler;

use App\Message\DetectFacesMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Thin placeholder so `messenger:consume faces` never fails with "no
 * handler for message" if it's ever run against the PHP app. The real
 * face-detection pipeline is a separate Python/InsightFace consumer
 * (Task 7); this handler intentionally no-ops.
 */
#[AsMessageHandler]
final class DetectFacesHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(DetectFacesMessage $message): void
    {
        $this->logger->info('detect_faces received; face detection is not implemented yet (Task 7).', [
            'photoId' => $message->getPhotoId(),
        ]);
    }
}
