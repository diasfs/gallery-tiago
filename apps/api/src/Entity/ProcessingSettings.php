<?php

namespace App\Entity;

use App\Enum\TagDetector;
use App\Repository\ProcessingSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Singleton row (id = 1) holding global AI processing preferences.
 */
#[ORM\Entity(repositoryClass: ProcessingSettingsRepository::class)]
#[ORM\Table(name: 'processing_settings')]
class ProcessingSettings
{
    public const SINGLETON_ID = 1;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = self::SINGLETON_ID;

    #[ORM\Column(options: ['default' => true])]
    private bool $facesEnabled = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $tagsEnabled = true;

    #[ORM\Column(length: 32, enumType: TagDetector::class, options: ['default' => 'ram_plus'])]
    private TagDetector $tagDetector = TagDetector::RamPlus;

    public static function defaults(): self
    {
        return new self();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function isFacesEnabled(): bool
    {
        return $this->facesEnabled;
    }

    public function setFacesEnabled(bool $facesEnabled): static
    {
        $this->facesEnabled = $facesEnabled;

        return $this;
    }

    public function isTagsEnabled(): bool
    {
        return $this->tagsEnabled;
    }

    public function setTagsEnabled(bool $tagsEnabled): static
    {
        $this->tagsEnabled = $tagsEnabled;

        return $this;
    }

    public function getTagDetector(): TagDetector
    {
        return $this->tagDetector;
    }

    public function setTagDetector(TagDetector $tagDetector): static
    {
        $this->tagDetector = $tagDetector;

        return $this;
    }
}
