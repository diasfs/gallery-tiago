<?php

namespace App\Repository;

use App\Entity\Album;
use App\Enum\AlbumVisibility;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Album>
 */
class AlbumRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Album::class);
    }

    /** @return Album[] */
    public function findPublicRoots(): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.parent IS NULL')
            ->andWhere('a.visibility = :visibility')
            ->setParameter('visibility', AlbumVisibility::Public)
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findVisibleBySlug(string $slug): ?Album
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.slug = :slug')
            ->andWhere('a.visibility IN (:visibilities)')
            ->setParameter('slug', $slug)
            ->setParameter('visibilities', [AlbumVisibility::Public, AlbumVisibility::Unlisted])
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return Album[] */
    public function findVisibleChildren(Album $parent): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.parent = :parent')
            ->andWhere('a.visibility IN (:visibilities)')
            ->setParameter('parent', $parent)
            ->setParameter('visibilities', [AlbumVisibility::Public, AlbumVisibility::Unlisted])
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Album[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.title', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
