<?php

namespace App\Repository;

use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Photo>
 */
class PhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Photo::class);
    }

    public function findVisibleById(Uuid $id): ?Photo
    {
        return $this->createQueryBuilder('p')
            ->join('p.album', 'a')
            ->andWhere('p.id = :id')
            ->andWhere('a.visibility IN (:visibilities)')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('visibilities', [AlbumVisibility::Public, AlbumVisibility::Unlisted])
            ->getQuery()
            ->getOneOrNullResult();
    }
}
