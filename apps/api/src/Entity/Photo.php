<?php

namespace App\Entity;

use App\Enum\ProcessingStatus;
use App\Repository\PhotoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PhotoRepository::class)]
#[ORM\Table(name: 'photo')]
class Photo
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Album::class, inversedBy: 'photos')]
    #[ORM\JoinColumn(name: 'album_id', nullable: false, onDelete: 'CASCADE')]
    private Album $album;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(nullable: true)]
    private ?int $width = null;

    #[ORM\Column(nullable: true)]
    private ?int $height = null;

    #[ORM\Column(length: 1024)]
    private string $originalPath;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $avifPath = null;

    #[ORM\Column(type: Types::JSON)]
    private array $thumbPaths = [];

    #[ORM\Column(length: 20, enumType: ProcessingStatus::class)]
    private ProcessingStatus $processingStatus = ProcessingStatus::Pending;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $processingError = null;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'photos')]
    #[ORM\JoinTable(name: 'photo_tag')]
    private Collection $tags;

    /** @var Collection<int, Face> */
    #[ORM\OneToMany(mappedBy: 'photo', targetEntity: Face::class)]
    private Collection $faces;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Album $album, string $originalPath)
    {
        $this->album = $album;
        $this->originalPath = $originalPath;
        $this->tags = new ArrayCollection();
        $this->faces = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAlbum(): Album
    {
        return $this->album;
    }

    public function setAlbum(Album $album): static
    {
        $this->album = $album;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function setWidth(?int $width): static
    {
        $this->width = $width;

        return $this;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function setHeight(?int $height): static
    {
        $this->height = $height;

        return $this;
    }

    public function getOriginalPath(): string
    {
        return $this->originalPath;
    }

    public function setOriginalPath(string $originalPath): static
    {
        $this->originalPath = $originalPath;

        return $this;
    }

    public function getAvifPath(): ?string
    {
        return $this->avifPath;
    }

    public function setAvifPath(?string $avifPath): static
    {
        $this->avifPath = $avifPath;

        return $this;
    }

    public function getThumbPaths(): array
    {
        return $this->thumbPaths;
    }

    public function setThumbPaths(array $thumbPaths): static
    {
        $this->thumbPaths = $thumbPaths;

        return $this;
    }

    public function getProcessingStatus(): ProcessingStatus
    {
        return $this->processingStatus;
    }

    public function setProcessingStatus(ProcessingStatus $processingStatus): static
    {
        $this->processingStatus = $processingStatus;

        return $this;
    }

    public function getProcessingError(): ?string
    {
        return $this->processingError;
    }

    public function setProcessingError(?string $processingError): static
    {
        $this->processingError = $processingError;

        return $this;
    }

    /** @return Collection<int, Tag> */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function removeTag(Tag $tag): static
    {
        $this->tags->removeElement($tag);

        return $this;
    }

    /** @return Collection<int, Face> */
    public function getFaces(): Collection
    {
        return $this->faces;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
