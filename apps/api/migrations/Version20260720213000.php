<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Face crops outlive their source photos: photo_id becomes nullable with SET NULL
 * instead of CASCADE, so deleting a photo keeps Face rows and crop files.
 */
final class Version20260720213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make face.photo_id nullable (ON DELETE SET NULL) so crops survive photo deletion';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE face DROP CONSTRAINT FK_5147B677E9E4C8C');
        $this->addSql('ALTER TABLE face ALTER photo_id DROP NOT NULL');
        $this->addSql('ALTER TABLE face ADD CONSTRAINT FK_5147B677E9E4C8C FOREIGN KEY (photo_id) REFERENCES photo (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM face WHERE photo_id IS NULL');
        $this->addSql('ALTER TABLE face DROP CONSTRAINT FK_5147B677E9E4C8C');
        $this->addSql('ALTER TABLE face ALTER photo_id SET NOT NULL');
        $this->addSql('ALTER TABLE face ADD CONSTRAINT FK_5147B677E9E4C8C FOREIGN KEY (photo_id) REFERENCES photo (id) ON DELETE CASCADE NOT DEFERRABLE');
    }
}
