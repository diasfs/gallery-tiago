<?php

namespace App\Entity;

use App\Repository\FaceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: FaceRepository::class)]
#[ORM\Table(name: 'face')]
class Face
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private Uuid $id;

    /**
     * Nullable so face crops survive source-photo deletion (photo_id SET NULL).
     * New faces always start attached to a photo via the constructor.
     */
    #[ORM\ManyToOne(targetEntity: Photo::class, inversedBy: 'faces')]
    #[ORM\JoinColumn(name: 'photo_id', nullable: true, onDelete: 'SET NULL')]
    private ?Photo $photo;

    #[ORM\ManyToOne(targetEntity: Person::class, inversedBy: 'faces')]
    #[ORM\JoinColumn(name: 'person_id', nullable: true, onDelete: 'SET NULL')]
    private ?Person $person = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $x = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $y = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $width = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $height = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $cropPath = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $confidence = null;

    /**
     * pgvector column, dimension fixed by FACE_EMBEDDING_DIM (see .env), e.g. 512.
     * Null for manually-added faces (see $hasEmbedding).
     *
     * @var float[]|null
     */
    #[ORM\Column(type: 'vector', nullable: true, options: ['dimensions' => 512])]
    private ?array $embedding = null;

    /**
     * False for manual admin adds (no detection/embedding yet); true once a
     * detection pass has produced an embedding for this face.
     */
    #[ORM\Column]
    private bool $hasEmbedding = false;

    public function __construct(Photo $photo)
    {
        $this->photo = $photo;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPhoto(): ?Photo
    {
        return $this->photo;
    }

    public function setPhoto(?Photo $photo): static
    {
        $this->photo = $photo;

        return $this;
    }

    public function getPerson(): ?Person
    {
        return $this->person;
    }

    public function setPerson(?Person $person): static
    {
        $this->person = $person;

        return $this;
    }

    public function getX(): ?float
    {
        return $this->x;
    }

    public function setX(?float $x): static
    {
        $this->x = $x;

        return $this;
    }

    public function getY(): ?float
    {
        return $this->y;
    }

    public function setY(?float $y): static
    {
        $this->y = $y;

        return $this;
    }

    public function getWidth(): ?float
    {
        return $this->width;
    }

    public function setWidth(?float $width): static
    {
        $this->width = $width;

        return $this;
    }

    public function getHeight(): ?float
    {
        return $this->height;
    }

    public function setHeight(?float $height): static
    {
        $this->height = $height;

        return $this;
    }

    public function getCropPath(): ?string
    {
        return $this->cropPath;
    }

    public function setCropPath(?string $cropPath): static
    {
        $this->cropPath = $cropPath;

        return $this;
    }

    public function getConfidence(): ?float
    {
        return $this->confidence;
    }

    public function setConfidence(?float $confidence): static
    {
        $this->confidence = $confidence;

        return $this;
    }

    /** @return float[]|null */
    public function getEmbedding(): ?array
    {
        return $this->embedding;
    }

    /** @param float[]|null $embedding */
    public function setEmbedding(?array $embedding): static
    {
        $this->embedding = $embedding;
        $this->hasEmbedding = $embedding !== null;

        return $this;
    }

    public function hasEmbedding(): bool
    {
        return $this->hasEmbedding;
    }
}
