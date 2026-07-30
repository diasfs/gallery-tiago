<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add processing_settings singleton for faces/tags enablement and tag detector';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE processing_settings (
                id INT NOT NULL,
                faces_enabled BOOLEAN DEFAULT true NOT NULL,
                tags_enabled BOOLEAN DEFAULT true NOT NULL,
                tag_detector VARCHAR(32) DEFAULT 'ram_plus' NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql(
            "INSERT INTO processing_settings (id, faces_enabled, tags_enabled, tag_detector) VALUES (1, true, true, 'ram_plus')",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE processing_settings');
    }
}
