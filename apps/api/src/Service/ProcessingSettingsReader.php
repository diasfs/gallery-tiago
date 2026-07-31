<?php

namespace App\Service;

use App\Entity\ProcessingSettings;
use App\Enum\AlbumPhotoLayout;
use App\Enum\TagDetector;
use App\Repository\ProcessingSettingsRepository;

/**
 * Thin facade so message handlers and reprocessors share one read path.
 */
final class ProcessingSettingsReader
{
    public function __construct(
        private readonly ProcessingSettingsRepository $settings,
    ) {
    }

    public function get(): ProcessingSettings
    {
        return $this->settings->getSingleton();
    }

    public function isFacesEnabled(): bool
    {
        return $this->get()->isFacesEnabled();
    }

    public function isTagsEnabled(): bool
    {
        return $this->get()->isTagsEnabled();
    }

    public function getTagDetector(): TagDetector
    {
        return $this->get()->getTagDetector();
    }

    public function getAlbumPhotoLayout(): AlbumPhotoLayout
    {
        return $this->get()->getAlbumPhotoLayout();
    }
}
