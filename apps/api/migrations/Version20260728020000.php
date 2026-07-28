<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add album.taken_at_end for multi-day album date ranges';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE album ADD taken_at_end TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE album DROP taken_at_end');
    }
}
