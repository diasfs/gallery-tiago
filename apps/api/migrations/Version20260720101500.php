<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260720101500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move taken_at and location_id from photo to album';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE album ADD taken_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE album ADD location_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_39986E4364D218E ON album (location_id)');
        $this->addSql('ALTER TABLE album ADD CONSTRAINT FK_39986E4364D218E FOREIGN KEY (location_id) REFERENCES location (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql(<<<'SQL'
            UPDATE album a SET taken_at = sub.taken_at
            FROM (
                SELECT album_id, MIN(taken_at) AS taken_at
                FROM photo
                WHERE taken_at IS NOT NULL
                GROUP BY album_id
            ) sub
            WHERE a.id = sub.album_id
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE album a SET location_id = sub.location_id
            FROM (
                SELECT DISTINCT ON (album_id) album_id, location_id
                FROM photo
                WHERE location_id IS NOT NULL
                ORDER BY album_id, created_at
            ) sub
            WHERE a.id = sub.album_id
            SQL);

        $this->addSql('ALTER TABLE photo DROP CONSTRAINT FK_14B7841864D218E');
        $this->addSql('DROP INDEX IDX_14B7841864D218E');
        $this->addSql('ALTER TABLE photo DROP location_id');
        $this->addSql('ALTER TABLE photo DROP taken_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE photo ADD taken_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE photo ADD location_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_14B7841864D218E ON photo (location_id)');
        $this->addSql('ALTER TABLE photo ADD CONSTRAINT FK_14B7841864D218E FOREIGN KEY (location_id) REFERENCES location (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql(<<<'SQL'
            UPDATE photo p SET taken_at = a.taken_at, location_id = a.location_id
            FROM album a
            WHERE p.album_id = a.id
            SQL);

        $this->addSql('ALTER TABLE album DROP CONSTRAINT FK_39986E4364D218E');
        $this->addSql('DROP INDEX IDX_39986E4364D218E');
        $this->addSql('ALTER TABLE album DROP location_id');
        $this->addSql('ALTER TABLE album DROP taken_at');
    }
}
