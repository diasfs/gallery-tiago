<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Preserve legacy id_album for public “recent albums” ordering (old: id_album DESC).
 */
final class Version20260727220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add album.legacy_id for recent-list ordering matching old gallery';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE album ADD legacy_id INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_album_legacy_id ON album (legacy_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_album_legacy_id');
        $this->addSql('ALTER TABLE album DROP legacy_id');
    }
}
