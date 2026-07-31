<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260731140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add album_photo_layout to processing_settings singleton';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE processing_settings ADD album_photo_layout VARCHAR(32) DEFAULT 'grid' NOT NULL",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processing_settings DROP COLUMN album_photo_layout');
    }
}
