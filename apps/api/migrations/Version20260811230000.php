<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260811230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add person.created_at and processing_settings.ga_measurement_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE person ADD created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT NOW()');
        $this->addSql('ALTER TABLE processing_settings ADD ga_measurement_id VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE person DROP created_at');
        $this->addSql('ALTER TABLE processing_settings DROP ga_measurement_id');
    }
}
