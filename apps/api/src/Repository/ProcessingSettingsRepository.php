<?php

namespace App\Repository;

use App\Entity\ProcessingSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProcessingSettings>
 */
class ProcessingSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcessingSettings::class);
    }

    /**
     * Return the singleton settings row, creating it with defaults if missing.
     */
    public function getSingleton(): ProcessingSettings
    {
        $existing = $this->find(ProcessingSettings::SINGLETON_ID);
        if ($existing instanceof ProcessingSettings) {
            return $existing;
        }

        $settings = ProcessingSettings::defaults();
        $em = $this->getEntityManager();
        $em->persist($settings);
        $em->flush();

        return $settings;
    }
}
