<?php

namespace App\Entity;

use App\Repository\FaceGalleryScanMatchRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: FaceGalleryScanMatchRepository::class)]
#[ORM\Table(name: 'face_gallery_scan_match')]
#[ORM\UniqueConstraint(name: 'face_gallery_scan_match_scan_photo_uidx', columns: ['scan_id', 'photo_id'])]
class FaceGalleryScanMatch
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: FaceGalleryScan::class, inversedBy: 'matches')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FaceGalleryScan $scan;

    #[ORM\ManyToOne(targetEntity: Photo::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Photo $photo;

    #[ORM\Column(type: 'float')]
    private float $distance;

    #[ORM\Column(type: 'float')]
    private float $x;

    #[ORM\Column(type: 'float')]
    private float $y;

    #[ORM\Column(type: 'float')]
    private float $width;

    #[ORM\Column(type: 'float')]
    private float $height;

    /** @var float[] */
    #[ORM\Column(type: 'vector', options: ['dimensions' => 512])]
    private array $embedding;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $cropPath = null;

    #[ORM\Column]
    private bool $selected = true;

    /** @param float[] $embedding */
    public function __construct(
        FaceGalleryScan $scan,
        Photo $photo,
        float $distance,
        float $x,
        float $y,
        float $width,
        float $height,
        array $embedding,
        ?string $cropPath = null,
    ) {
        $this->scan = $scan;
        $this->photo = $photo;
        $this->distance = $distance;
        $this->x = $x;
        $this->y = $y;
        $this->width = $width;
        $this->height = $height;
        $this->embedding = $embedding;
        $this->cropPath = $cropPath;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getScan(): FaceGalleryScan
    {
        return $this->scan;
    }

    public function getPhoto(): Photo
    {
        return $this->photo;
    }

    public function getDistance(): float
    {
        return $this->distance;
    }

    public function getX(): float
    {
        return $this->x;
    }

    public function getY(): float
    {
        return $this->y;
    }

    public function getWidth(): float
    {
        return $this->width;
    }

    public function getHeight(): float
    {
        return $this->height;
    }

    /** @return float[] */
    public function getEmbedding(): array
    {
        return $this->embedding;
    }

    public function getCropPath(): ?string
    {
        return $this->cropPath;
    }

    public function isSelected(): bool
    {
        return $this->selected;
    }

    public function setSelected(bool $selected): static
    {
        $this->selected = $selected;

        return $this;
    }
}
