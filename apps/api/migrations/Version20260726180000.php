<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Split photo.processing_status into media_status, faces_status, tags_status';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE photo ADD media_status VARCHAR(20) DEFAULT 'pending' NOT NULL");
        $this->addSql("ALTER TABLE photo ADD faces_status VARCHAR(20) DEFAULT 'pending' NOT NULL");
        $this->addSql("ALTER TABLE photo ADD tags_status VARCHAR(20) DEFAULT 'pending' NOT NULL");

        $this->addSql("UPDATE photo SET media_status = 'pending', faces_status = 'pending', tags_status = 'pending' WHERE processing_status = 'pending'");
        $this->addSql("UPDATE photo SET media_status = 'converting', faces_status = 'pending', tags_status = 'pending' WHERE processing_status = 'converting'");
        $this->addSql("UPDATE photo SET media_status = 'done', faces_status = 'detecting', tags_status = 'detecting' WHERE processing_status = 'detecting'");
        $this->addSql("UPDATE photo SET media_status = 'done', faces_status = 'done', tags_status = 'done' WHERE processing_status = 'done'");
        $this->addSql("UPDATE photo SET media_status = 'failed', faces_status = 'pending', tags_status = 'pending' WHERE processing_status = 'failed' AND avif_path IS NULL");
        $this->addSql("UPDATE photo SET media_status = 'done', faces_status = 'failed', tags_status = 'pending' WHERE processing_status = 'failed' AND avif_path IS NOT NULL");

        $this->addSql('ALTER TABLE photo ALTER media_status DROP DEFAULT');
        $this->addSql('ALTER TABLE photo ALTER faces_status DROP DEFAULT');
        $this->addSql('ALTER TABLE photo ALTER tags_status DROP DEFAULT');
        $this->addSql('ALTER TABLE photo DROP processing_status');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE photo ADD processing_status VARCHAR(20) DEFAULT 'pending' NOT NULL");
        $this->addSql("UPDATE photo SET processing_status = 'pending' WHERE media_status = 'pending'");
        $this->addSql("UPDATE photo SET processing_status = 'converting' WHERE media_status = 'converting'");
        $this->addSql("UPDATE photo SET processing_status = 'detecting' WHERE faces_status = 'detecting' OR tags_status = 'detecting'");
        $this->addSql("UPDATE photo SET processing_status = 'failed' WHERE media_status = 'failed' OR faces_status = 'failed' OR tags_status = 'failed'");
        $this->addSql("UPDATE photo SET processing_status = 'done' WHERE media_status = 'done' AND faces_status = 'done' AND tags_status = 'done'");
        $this->addSql('ALTER TABLE photo ALTER processing_status DROP DEFAULT');
        $this->addSql('ALTER TABLE photo DROP media_status');
        $this->addSql('ALTER TABLE photo DROP faces_status');
        $this->addSql('ALTER TABLE photo DROP tags_status');
    }
}
