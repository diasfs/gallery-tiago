<?php

namespace App\Service;

use App\Entity\Photo;
use App\Enum\FacesStatus;
use App\Enum\MediaStatus;
use App\Enum\TagsStatus;
use App\Exception\ProcessingStageDisabledException;
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
 * full pipeline runs regardless of scope (convert enqueues both on success,
 * respecting current enablement flags).
 *
 * Explicit faces/tags scopes are blocked with ProcessingStageDisabledException
 * when that stage is globally disabled. Scope "all" runs only enabled stages.
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
        private readonly ProcessingSettingsReader $settings,
    ) {
    }

    public function reprocess(Photo $photo, string $scope = self::SCOPE_ALL): void
    {
        $photoId = (string) $photo->getId();
        $facesEnabled = $this->settings->isFacesEnabled();
        $tagsEnabled = $this->settings->isTagsEnabled();

        if (self::SCOPE_FACES === $scope && !$facesEnabled) {
            throw new ProcessingStageDisabledException('faces');
        }
        if (self::SCOPE_TAGS === $scope && !$tagsEnabled) {
            throw new ProcessingStageDisabledException('tags');
        }

        if (null === $photo->getAvifPath()) {
            $this->removeAutoDetectedFaces($photo);
            $photo->setMediaStatus(MediaStatus::Pending);
            $photo->setFacesStatus($facesEnabled ? FacesStatus::Pending : FacesStatus::Disabled);
            $photo->setTagsStatus($tagsEnabled ? TagsStatus::Pending : TagsStatus::Disabled);
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

        $runFaces = self::SCOPE_TAGS !== $scope && $facesEnabled;
        $runTags = self::SCOPE_FACES !== $scope && $tagsEnabled;

        if (self::SCOPE_ALL === $scope && !$runFaces && !$runTags) {
            throw new ProcessingStageDisabledException('all', 'Both faces and tags processing are disabled.');
        }

        if ($runFaces) {
            $this->removeAutoDetectedFaces($photo);
            $photo->setFacesStatus(FacesStatus::Queued);
            $photo->setProcessingError(ProcessingErrorBag::clear($photo->getProcessingError(), 'faces'));
        }
        if ($runTags) {
            $photo->setTagsStatus(TagsStatus::Queued);
            $photo->setProcessingError(ProcessingErrorBag::clear($photo->getProcessingError(), 'tags'));
        }
        $this->em->flush();

        if ($runFaces) {
            $this->bus->dispatch(new DetectFacesMessage($photoId));
        }
        if ($runTags) {
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
