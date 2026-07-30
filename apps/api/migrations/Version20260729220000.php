<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Move inherited "detecting" rows to "queued" so the UI no longer treats
 * enqueued/orphan work as actively running. Workers now claim detecting.
 */
final class Version20260729220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert stuck detecting faces/tags statuses to queued';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE photo SET faces_status = 'queued' WHERE faces_status = 'detecting'");
        $this->addSql("UPDATE photo SET tags_status = 'queued' WHERE tags_status = 'detecting'");
    }

    public function down(Schema $schema): void
    {
        // Irreversible: cannot distinguish previously-detecting from newly-queued.
        $this->addSql('SELECT 1');
    }
}
