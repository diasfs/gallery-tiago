<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add face_gallery_scan and face_gallery_scan_match tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE face_gallery_scan (id UUID NOT NULL, status VARCHAR(32) NOT NULL, reference_embedding vector(512) NOT NULL, reference_crop_path VARCHAR(1024) DEFAULT NULL, threshold DOUBLE PRECISION NOT NULL, total_photos INT NOT NULL, enqueued_photos INT NOT NULL, processed_photos INT NOT NULL, matched_photos INT NOT NULL, error TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE face_gallery_scan_match (id UUID NOT NULL, scan_id UUID NOT NULL, photo_id UUID NOT NULL, distance DOUBLE PRECISION NOT NULL, x DOUBLE PRECISION NOT NULL, y DOUBLE PRECISION NOT NULL, width DOUBLE PRECISION NOT NULL, height DOUBLE PRECISION NOT NULL, embedding vector(512) NOT NULL, crop_path VARCHAR(1024) DEFAULT NULL, selected BOOLEAN NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX face_gallery_scan_match_scan_photo_uidx ON face_gallery_scan_match (scan_id, photo_id)');
        $this->addSql('CREATE INDEX face_gallery_scan_match_scan_idx ON face_gallery_scan_match (scan_id)');
        $this->addSql('ALTER TABLE face_gallery_scan_match ADD CONSTRAINT FK_FACE_GALLERY_SCAN_MATCH_SCAN FOREIGN KEY (scan_id) REFERENCES face_gallery_scan (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE face_gallery_scan_match ADD CONSTRAINT FK_FACE_GALLERY_SCAN_MATCH_PHOTO FOREIGN KEY (photo_id) REFERENCES photo (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE face_gallery_scan_match DROP CONSTRAINT FK_FACE_GALLERY_SCAN_MATCH_SCAN');
        $this->addSql('ALTER TABLE face_gallery_scan_match DROP CONSTRAINT FK_FACE_GALLERY_SCAN_MATCH_PHOTO');
        $this->addSql('DROP TABLE face_gallery_scan_match');
        $this->addSql('DROP TABLE face_gallery_scan');
    }
}
