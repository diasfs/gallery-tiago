<?php

namespace App\Service;

use App\Entity\Photo;
use App\Enum\FacesStatus;
use App\Enum\MediaStatus;
use App\Enum\TagsStatus;
use App\Message\ConvertMediaMessage;
use App\Message\DetectFacesMessage;
use App\Message\SuggestTagsMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Re-runs the async pipeline for a photo, optionally narrowed to faces or
 * tags. Shared by the single-photo and whole-album reprocess endpoints.
 *
 * Scope semantics:
 * - all: delete auto-detected faces, re-run face detection + tag suggestion
 * - faces: delete auto-detected faces, re-run face detection only
 * - tags: re-run tag suggestion only (media and faces status untouched)
 *
 * When the photo has no AVIF master yet, conversion is required first and the
 * full pipeline runs regardless of scope (convert enqueues both on success).
 */
final class PhotoReprocessor
{
    public const SCOPE_ALL = 'all';
    public const SCOPE_FACES = 'faces';
    public const SCOPE_TAGS = 'tags';

    public const SCOPES = [self::SCOPE_ALL, self::SCOPE_FACES, self::SCOPE_TAGS];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function reprocess(Photo $photo, string $scope = self::SCOPE_ALL): void
    {
        $photoId = (string) $photo->getId();

        if (null === $photo->getAvifPath()) {
            $this->removeAutoDetectedFaces($photo);
            $photo->setMediaStatus(MediaStatus::Pending);
            $photo->setFacesStatus(FacesStatus::Pending);
            $photo->setTagsStatus(TagsStatus::Pending);
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
            $this->bus->dispatch(new ConvertMediaMessage($photoId));

            return;
        }

        if (self::SCOPE_TAGS !== $scope) {
            $this->removeAutoDetectedFaces($photo);
            $photo->setFacesStatus(FacesStatus::Detecting);
            $photo->setProcessingError(ProcessingErrorBag::clear($photo->getProcessingError(), 'faces'));
        }
        if (self::SCOPE_FACES !== $scope) {
            $photo->setTagsStatus(TagsStatus::Detecting);
            $photo->setProcessingError(ProcessingErrorBag::clear($photo->getProcessingError(), 'tags'));
        }
        $this->em->flush();

        if (self::SCOPE_TAGS !== $scope) {
            $this->bus->dispatch(new DetectFacesMessage($photoId));
        }
        if (self::SCOPE_FACES !== $scope) {
            $this->bus->dispatch(new SuggestTagsMessage($photoId));
        }
    }

    /**
     * Manually-added faces (hasEmbedding = false) are kept, per design spec §9.
     */
    private function removeAutoDetectedFaces(Photo $photo): void
    {
        foreach ($photo->getFaces() as $face) {
            if ($face->hasEmbedding()) {
                $this->em->remove($face);
            }
        }
    }
}
