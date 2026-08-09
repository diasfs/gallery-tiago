<?php

namespace App\Entity;

use App\Repository\FaceGalleryScanRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: FaceGalleryScanRepository::class)]
#[ORM\Table(name: 'face_gallery_scan')]
class FaceGalleryScan
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private Uuid $id;

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_PENDING;

    /** @var float[] */
    #[ORM\Column(type: 'vector', options: ['dimensions' => 512])]
    private array $referenceEmbedding;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $referenceCropPath = null;

    #[ORM\Column(type: 'float')]
    private float $threshold;

    #[ORM\Column]
    private int $totalPhotos = 0;

    #[ORM\Column]
    private int $enqueuedPhotos = 0;

    #[ORM\Column]
    private int $processedPhotos = 0;

    #[ORM\Column]
    private int $matchedPhotos = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, FaceGalleryScanMatch> */
    #[ORM\OneToMany(mappedBy: 'scan', targetEntity: FaceGalleryScanMatch::class, cascade: ['remove'], orphanRemoval: true)]
    private Collection $matches;

    /** @param float[] $referenceEmbedding */
    public function __construct(array $referenceEmbedding, float $threshold)
    {
        $this->referenceEmbedding = $referenceEmbedding;
        $this->threshold = $threshold;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->matches = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        $this->touch();

        return $this;
    }

    /** @return float[] */
    public function getReferenceEmbedding(): array
    {
        return $this->referenceEmbedding;
    }

    public function getReferenceCropPath(): ?string
    {
        return $this->referenceCropPath;
    }

    public function setReferenceCropPath(?string $referenceCropPath): static
    {
        $this->referenceCropPath = $referenceCropPath;

        return $this;
    }

    public function getThreshold(): float
    {
        return $this->threshold;
    }

    public function getTotalPhotos(): int
    {
        return $this->totalPhotos;
    }

    public function setTotalPhotos(int $totalPhotos): static
    {
        $this->totalPhotos = $totalPhotos;

        return $this;
    }

    public function getEnqueuedPhotos(): int
    {
        return $this->enqueuedPhotos;
    }

    public function setEnqueuedPhotos(int $enqueuedPhotos): static
    {
        $this->enqueuedPhotos = $enqueuedPhotos;
        $this->touch();

        return $this;
    }

    public function incrementEnqueuedPhotos(int $count = 1): static
    {
        $this->enqueuedPhotos += $count;
        $this->touch();

        return $this;
    }

    public function getProcessedPhotos(): int
    {
        return $this->processedPhotos;
    }

    public function setProcessedPhotos(int $processedPhotos): static
    {
        $this->processedPhotos = $processedPhotos;
        $this->touch();

        return $this;
    }

    public function incrementProcessedPhotos(): static
    {
        ++$this->processedPhotos;
        $this->touch();

        return $this;
    }

    public function getMatchedPhotos(): int
    {
        return $this->matchedPhotos;
    }

    public function setMatchedPhotos(int $matchedPhotos): static
    {
        $this->matchedPhotos = $matchedPhotos;
        $this->touch();

        return $this;
    }

    public function incrementMatchedPhotos(): static
    {
        ++$this->matchedPhotos;
        $this->touch();

        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): static
    {
        $this->error = $error;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, FaceGalleryScanMatch> */
    public function getMatches(): Collection
    {
        return $this->matches;
    }

    public function isTerminal(): bool
    {
        return \in_array($this->status, [self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_CANCELLED], true);
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
