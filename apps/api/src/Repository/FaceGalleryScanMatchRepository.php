<?php

namespace App\Repository;

use App\Entity\FaceGalleryScan;
use App\Entity\FaceGalleryScanMatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<FaceGalleryScanMatch>
 */
class FaceGalleryScanMatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FaceGalleryScanMatch::class);
    }

    /** @return FaceGalleryScanMatch[] */
    public function findByScanOrdered(FaceGalleryScan $scan): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.scan = :scan')
            ->setParameter('scan', $scan)
            ->orderBy('m.distance', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneForScan(Uuid $scanId, Uuid $matchId): ?FaceGalleryScanMatch
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.id = :matchId')
            ->andWhere('m.scan = :scanId')
            ->setParameter('matchId', $matchId, 'uuid')
            ->setParameter('scanId', $scanId, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();
    }
}
