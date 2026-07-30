<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260730220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add view_count to photo and album for public view tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE photo ADD view_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE album ADD view_count INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE photo DROP view_count');
        $this->addSql('ALTER TABLE album DROP view_count');
    }
}
