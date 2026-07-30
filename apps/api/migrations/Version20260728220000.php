<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add person.avatar_path for custom uploaded avatars';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE person ADD avatar_path VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE person DROP avatar_path');
    }
}
