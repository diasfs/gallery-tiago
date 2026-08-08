<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260807220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add most-viewed home visibility and exclude-root flags to processing_settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE processing_settings ADD most_viewed_home_enabled BOOLEAN DEFAULT true NOT NULL',
        );
        $this->addSql(
            'ALTER TABLE processing_settings ADD most_viewed_exclude_root_albums BOOLEAN DEFAULT false NOT NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processing_settings DROP COLUMN most_viewed_home_enabled');
        $this->addSql('ALTER TABLE processing_settings DROP COLUMN most_viewed_exclude_root_albums');
    }
}
