<?php

namespace App\Repository;

use App\Entity\Face;
use App\Entity\Person;
use App\Entity\Photo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Face>
 */
class FaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Face::class);
    }

    public function findOneByPhotoAndPerson(Photo $photo, Person $person): ?Face
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.photo = :photo')
            ->andWhere('f.person = :person')
            ->setParameter('photo', $photo->getId(), 'uuid')
            ->setParameter('person', $person->getId(), 'uuid')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return Face[] */
    public function findByPhotoAndPerson(Photo $photo, Person $person): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.photo = :photo')
            ->andWhere('f.person = :person')
            ->setParameter('photo', $photo->getId(), 'uuid')
            ->setParameter('person', $person->getId(), 'uuid')
            ->getQuery()
            ->getResult();
    }
}
