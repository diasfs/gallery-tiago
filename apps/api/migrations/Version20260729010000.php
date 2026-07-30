<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add photo.sort_order (legacy foto.ordem) and backfill by created_at per album';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE photo ADD sort_order INT DEFAULT 0 NOT NULL');
        $this->addSql(<<<'SQL'
            WITH ranked AS (
                SELECT id, (ROW_NUMBER() OVER (PARTITION BY album_id ORDER BY created_at ASC) - 1) AS rn
                FROM photo
            )
            UPDATE photo SET sort_order = ranked.rn FROM ranked WHERE photo.id = ranked.id
        SQL);
        $this->addSql('ALTER TABLE photo ALTER sort_order DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE photo DROP sort_order');
    }
}
