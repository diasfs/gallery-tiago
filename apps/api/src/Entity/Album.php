<?php

namespace App\Entity;

use App\Enum\AlbumVisibility;
use App\Repository\AlbumRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AlbumRepository::class)]
#[ORM\Table(name: 'album')]
class Album
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true, onDelete: 'SET NULL')]
    private ?Album $parent = null;

    /** @var Collection<int, Album> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    private Collection $children;

    /** @var Collection<int, Photo> */
    #[ORM\OneToMany(mappedBy: 'album', targetEntity: Photo::class)]
    private Collection $photos;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $slug;

    #[ORM\Column(length: 20, enumType: AlbumVisibility::class)]
    private AlbumVisibility $visibility = AlbumVisibility::Private;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\ManyToOne(targetEntity: Photo::class)]
    #[ORM\JoinColumn(name: 'cover_photo_id', nullable: true, onDelete: 'SET NULL')]
    private ?Photo $coverPhoto = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $takenAt = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'location_id', nullable: true, onDelete: 'SET NULL')]
    private ?Location $location = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $title, string $slug)
    {
        $this->title = $title;
        $this->slug = $slug;
        $this->children = new ArrayCollection();
        $this->photos = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    /** @return Collection<int, Album> */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    /** @return Collection<int, Photo> */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getVisibility(): AlbumVisibility
    {
        return $this->visibility;
    }

    public function setVisibility(AlbumVisibility $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getCoverPhoto(): ?Photo
    {
        return $this->coverPhoto;
    }

    public function setCoverPhoto(?Photo $coverPhoto): static
    {
        $this->coverPhoto = $coverPhoto;

        return $this;
    }

    public function getTakenAt(): ?\DateTimeImmutable
    {
        return $this->takenAt;
    }

    public function setTakenAt(?\DateTimeImmutable $takenAt): static
    {
        $this->takenAt = $takenAt;

        return $this;
    }

    public function getLocation(): ?Location
    {
        return $this->location;
    }

    public function setLocation(?Location $location): static
    {
        $this->location = $location;

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

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
