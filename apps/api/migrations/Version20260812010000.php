<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260812010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add face_gallery_scan.target_person_id for scans started from an existing person';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE face_gallery_scan ADD target_person_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE face_gallery_scan ADD CONSTRAINT FK_FACE_SCAN_TARGET_PERSON FOREIGN KEY (target_person_id) REFERENCES person (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_FACE_SCAN_TARGET_PERSON ON face_gallery_scan (target_person_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE face_gallery_scan DROP CONSTRAINT FK_FACE_SCAN_TARGET_PERSON');
        $this->addSql('DROP INDEX IDX_FACE_SCAN_TARGET_PERSON');
        $this->addSql('ALTER TABLE face_gallery_scan DROP target_person_id');
    }
}
