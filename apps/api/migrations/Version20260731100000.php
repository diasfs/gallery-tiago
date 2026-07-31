<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260731100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add HNSW index on face.embedding for cosine similarity queries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE INDEX face_embedding_hnsw_idx ON face USING hnsw (embedding vector_cosine_ops) WHERE has_embedding = true',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX face_embedding_hnsw_idx');
    }
}
