<?php

namespace App\MessageHandler;

use App\Message\ScanFaceMessage;
use App\Service\FaceScanQueuePublisherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ScanFaceHandler
{
    public function __construct(
        private readonly FaceScanQueuePublisherInterface $publisher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ScanFaceMessage $message): void
    {
        $this->publisher->publish($message->getScanId(), $message->getPhotoId());

        $this->logger->info('Bridged scan_face to gallery:face-scan:stream for scan {scanId} photo {photoId}.', [
            'scanId' => $message->getScanId(),
            'photoId' => $message->getPhotoId(),
        ]);
    }
}
