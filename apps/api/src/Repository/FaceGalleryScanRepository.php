<?php

namespace App\Repository;

use App\Entity\FaceGalleryScan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<FaceGalleryScan>
 */
class FaceGalleryScanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FaceGalleryScan::class);
    }

    public function findOneById(Uuid $id): ?FaceGalleryScan
    {
        return $this->find($id);
    }

    /**
     * @return array{items: FaceGalleryScan[], total: int}
     */
    public function searchPaginated(int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('s');

        $total = (int) (clone $qb)
            ->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = (clone $qb)
            ->orderBy('s.createdAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    public function hasActive(): bool
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('statuses', [FaceGalleryScan::STATUS_PENDING, FaceGalleryScan::STATUS_RUNNING])
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
