<?php

namespace App\Entity;

use App\Repository\PersonRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PersonRepository::class)]
#[ORM\Table(name: 'person')]
class Person
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private Uuid $id;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column]
    private bool $isNamed = false;

    #[ORM\OneToOne(targetEntity: Face::class)]
    #[ORM\JoinColumn(name: 'avatar_face_id', nullable: true, onDelete: 'SET NULL')]
    private ?Face $avatarFace = null;

    /** Media-relative path for an uploaded custom avatar (independent of faces). */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $avatarPath = null;

    /** @var Collection<int, Face> */
    #[ORM\OneToMany(mappedBy: 'person', targetEntity: Face::class)]
    private Collection $faces;

    public function __construct()
    {
        $this->faces = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function isNamed(): bool
    {
        return $this->isNamed;
    }

    public function setIsNamed(bool $isNamed): static
    {
        $this->isNamed = $isNamed;

        return $this;
    }

    public function getAvatarFace(): ?Face
    {
        return $this->avatarFace;
    }

    /**
     * Face used for display thumbnails: explicit primary, else the first linked face.
     */
    public function getEffectiveAvatarFace(): ?Face
    {
        if (null !== $this->avatarFace) {
            return $this->avatarFace;
        }

        $first = $this->faces->first();

        return false === $first ? null : $first;
    }

    public function setAvatarFace(?Face $avatarFace): static
    {
        $this->avatarFace = $avatarFace;

        return $this;
    }

    public function getAvatarPath(): ?string
    {
        return $this->avatarPath;
    }

    public function setAvatarPath(?string $avatarPath): static
    {
        $this->avatarPath = $avatarPath;

        return $this;
    }

    /**
     * Display path: custom upload wins, else the effective face crop.
     */
    public function getEffectiveAvatarPath(): ?string
    {
        if (null !== $this->avatarPath && '' !== $this->avatarPath) {
            return $this->avatarPath;
        }

        return $this->getEffectiveAvatarFace()?->getCropPath();
    }

    /** @return Collection<int, Face> */
    public function getFaces(): Collection
    {
        return $this->faces;
    }
}
