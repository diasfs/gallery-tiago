<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add person.deleted_at for soft-delete trash';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE person ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX person_deleted_at_idx ON person (deleted_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX person_deleted_at_idx');
        $this->addSql('ALTER TABLE person DROP deleted_at');
    }
}
