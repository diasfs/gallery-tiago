<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260906220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add album.reviewed_at for admin album review mode';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE album ADD reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE album DROP reviewed_at');
    }
}
