<?php

namespace App\MessageHandler;

use App\Enum\FacesStatus;
use App\Enum\MediaStatus;
use App\Enum\TagsStatus;
use App\Message\ConvertMediaMessage;
use App\Message\DetectFacesMessage;
use App\Message\SuggestTagsMessage;
use App\Repository\PhotoRepository;
use App\Service\AvifConverter;
use App\Service\MediaStorage;
use App\Service\ProcessingErrorBag;
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
    ) {
    }

    public function __invoke(ConvertMediaMessage $message): void
    {
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
            $sourceAbsolute = $this->storage->absolutePath($photo->getOriginalPath());

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

            $photo->setAvifPath($masterRelative);
            $photo->setThumbPaths($thumbRelativeBySize);
            $photo->setWidth($result->width);
            $photo->setHeight($result->height);
            $photo->setMediaStatus(MediaStatus::Done);
            $photo->setFacesStatus(FacesStatus::Detecting);
            $photo->setTagsStatus(TagsStatus::Detecting);
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

            $this->bus->dispatch(new DetectFacesMessage($photoId));
            $this->bus->dispatch(new SuggestTagsMessage($photoId));
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
    }
}
