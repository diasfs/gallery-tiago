<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260731010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add photos_per_page to album (default 48)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE album ADD photos_per_page INT DEFAULT 48 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE album DROP photos_per_page');
    }
}
