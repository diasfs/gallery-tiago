<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260731120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add photo.filename for public v3-style URLs (unique per album)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE photo ADD filename VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_photo_album_filename ON photo (album_id, filename)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_photo_album_filename');
        $this->addSql('ALTER TABLE photo DROP filename');
    }
}
