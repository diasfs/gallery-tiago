<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Originals are staging-only: after AVIF conversion the file is deleted and
 * original_path is cleared, so the column must be nullable.
 */
final class Version20260726190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make photo.original_path nullable (originals not retained after convert)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE photo ALTER original_path DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE photo SET original_path = '' WHERE original_path IS NULL");
        $this->addSql('ALTER TABLE photo ALTER original_path SET NOT NULL');
    }
}
