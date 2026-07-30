<?php

namespace App\MessageHandler;

use App\Enum\FacesStatus;
use App\Enum\MediaStatus;
use App\Enum\TagsStatus;
use App\Entity\Photo;
use App\Message\ConvertMediaMessage;
use App\Message\DetectFacesMessage;
use App\Message\SuggestTagsMessage;
use App\Repository\PhotoRepository;
use App\Service\AvifConverter;
use App\Service\MediaStorage;
use App\Service\ProcessingErrorBag;
use App\Service\ProcessingSettingsReader;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class ConvertMediaHandler
{
    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $storage,
        private readonly AvifConverter $converter,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly ProcessingSettingsReader $settings,
    ) {
    }

    public function __invoke(ConvertMediaMessage $message): void
    {
        try {
            try {
                $uuid = Uuid::fromString($message->getPhotoId());
            } catch (\InvalidArgumentException) {
                $this->logger->warning('ConvertMediaMessage carried an invalid photo id.', ['photoId' => $message->getPhotoId()]);

                return;
            }

            $photo = $this->photos->find($uuid);
            if (null === $photo) {
                $this->logger->warning('ConvertMediaMessage for unknown photo.', ['photoId' => $message->getPhotoId()]);

                return;
            }

            $photo->setMediaStatus(MediaStatus::Converting);
            $this->em->flush();

            $photoId = (string) $photo->getId();

            try {
                $originalRelative = $photo->getOriginalPath();
                if (null === $originalRelative || '' === $originalRelative) {
                    // Duplicate ConvertMediaMessage after a successful convert (original already purged).
                    if ($this->markDoneIfAvifPresent($photo)) {
                        return;
                    }

                    throw new \RuntimeException('Photo has no original path to convert.');
                }

                $sourceAbsolute = $this->storage->absolutePath($originalRelative);

                if (!is_file($sourceAbsolute)) {
                    // Another worker may have finished convert since we loaded the entity.
                    $this->em->refresh($photo);
                    if ($this->markDoneIfAvifPresent($photo)) {
                        return;
                    }

                    throw new \RuntimeException(\sprintf('Source image "%s" does not exist.', $sourceAbsolute));
                }

                $masterRelative = $this->storage->avifMasterPath($photoId);
                $masterAbsolute = $this->storage->absolutePath($masterRelative);

                $thumbRelativeBySize = [];
                $thumbAbsoluteBySize = [];
                foreach (AvifConverter::THUMBNAIL_SIZES as $size) {
                    $relative = $this->storage->thumbPath($photoId, $size);
                    $thumbRelativeBySize[(string) $size] = $relative;
                    $thumbAbsoluteBySize[$size] = $this->storage->absolutePath($relative);
                }

                $result = $this->converter->convert($sourceAbsolute, $masterAbsolute, $thumbAbsoluteBySize);

                $this->storage->deleteRelative($originalRelative);
                $photo->setOriginalPath(null);
                $photo->setAvifPath($masterRelative);
                $photo->setThumbPaths($thumbRelativeBySize);
                $photo->setWidth($result->width);
                $photo->setHeight($result->height);
                $photo->setMediaStatus(MediaStatus::Done);

                $facesEnabled = $this->settings->isFacesEnabled();
                $tagsEnabled = $this->settings->isTagsEnabled();

                $photo->setFacesStatus($facesEnabled ? FacesStatus::Queued : FacesStatus::Disabled);
                $photo->setTagsStatus($tagsEnabled ? TagsStatus::Queued : TagsStatus::Disabled);
                $photo->setProcessingError(
                    ProcessingErrorBag::clear(
                        ProcessingErrorBag::clear(
                            ProcessingErrorBag::clear($photo->getProcessingError(), 'media'),
                            'faces',
                        ),
                        'tags',
                    ),
                );
                $this->em->flush();

                if ($facesEnabled) {
                    $this->bus->dispatch(new DetectFacesMessage($photoId));
                }
                if ($tagsEnabled) {
                    $this->bus->dispatch(new SuggestTagsMessage($photoId));
                }
            } catch (\Throwable $e) {
                $photo->setMediaStatus(MediaStatus::Failed);
                $photo->setProcessingError(ProcessingErrorBag::set($photo->getProcessingError(), 'media', $e->getMessage()));
                $this->em->flush();

                $this->logger->error('convert_media failed for photo {photoId}: {message}', [
                    'photoId' => $photoId,
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        } finally {
            // Long-running messenger:consume must not retain entities across jobs.
            $this->em->clear();
        }
    }

    /**
     * When AVIF already exists on disk, treat convert as done (idempotent re-delivery).
     */
    private function markDoneIfAvifPresent(Photo $photo): bool
    {
        $avifRelative = $photo->getAvifPath();
        if (null === $avifRelative || '' === $avifRelative) {
            return false;
        }
        if (!is_file($this->storage->absolutePath($avifRelative))) {
            return false;
        }

        $photo->setOriginalPath(null);
        $photo->setMediaStatus(MediaStatus::Done);
        $photo->setProcessingError(
            ProcessingErrorBag::clear($photo->getProcessingError(), 'media'),
        );
        $this->em->flush();

        return true;
    }
}
