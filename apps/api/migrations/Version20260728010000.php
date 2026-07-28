<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enable PostgreSQL unaccent for accent-insensitive public/admin search.
 */
final class Version20260728010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create unaccent extension for accent-insensitive text search';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP EXTENSION IF EXISTS unaccent');
    }
}
