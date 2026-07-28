<?php

namespace App\Service\V3Import;

/**
 * Resumable legacy-id → UUID map backed by SQLite (append/upsert, no giant JSON rewrite).
 *
 * Accepts a `.json` path for backwards compatibility and uses a sibling `.sqlite` file,
 * migrating an existing JSON map once if present.
 */
final class V3ImportMap
{
    private readonly \PDO $pdo;

    private readonly string $sqlitePath;

    private readonly \PDOStatement $albumUpsert;

    private readonly \PDOStatement $albumSelect;

    private readonly \PDOStatement $photoUpsert;

    private readonly \PDOStatement $photoSelect;

    private readonly \PDOStatement $fileUpsert;

    private readonly \PDOStatement $fileSelect;

    private int $pendingWrites = 0;

    private bool $inTx = false;

    public function __construct(
        string $path,
    ) {
        $this->sqlitePath = str_ends_with($path, '.json')
            ? substr($path, 0, -5).'.sqlite'
            : $path;

        $dir = \dirname($this->sqlitePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Unable to create import map directory "%s".', $dir));
        }

        $this->pdo = new \PDO('sqlite:'.$this->sqlitePath, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA synchronous=NORMAL');
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS albums (
                legacy_id INTEGER PRIMARY KEY,
                uuid TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS photos (
                legacy_id INTEGER PRIMARY KEY,
                uuid TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS files (
                file_key TEXT PRIMARY KEY,
                uuid TEXT NOT NULL
            )'
        );

        if (str_ends_with($path, '.json') && is_file($path) && $this->isEmpty()) {
            $this->migrateFromJson($path);
        }

        $this->albumUpsert = $this->pdo->prepare(
            'INSERT INTO albums (legacy_id, uuid) VALUES (:id, :uuid)
             ON CONFLICT(legacy_id) DO UPDATE SET uuid = excluded.uuid'
        );
        $this->albumSelect = $this->pdo->prepare('SELECT uuid FROM albums WHERE legacy_id = :id');
        $this->photoUpsert = $this->pdo->prepare(
            'INSERT INTO photos (legacy_id, uuid) VALUES (:id, :uuid)
             ON CONFLICT(legacy_id) DO UPDATE SET uuid = excluded.uuid'
        );
        $this->photoSelect = $this->pdo->prepare('SELECT uuid FROM photos WHERE legacy_id = :id');
        $this->fileUpsert = $this->pdo->prepare(
            'INSERT INTO files (file_key, uuid) VALUES (:key, :uuid)
             ON CONFLICT(file_key) DO UPDATE SET uuid = excluded.uuid'
        );
        $this->fileSelect = $this->pdo->prepare('SELECT uuid FROM files WHERE file_key = :key');
    }

    public function getAlbumUuid(int $legacyId): ?string
    {
        $this->albumSelect->execute(['id' => $legacyId]);
        $uuid = $this->albumSelect->fetchColumn();

        return false === $uuid ? null : (string) $uuid;
    }

    public function setAlbumUuid(int $legacyId, string $uuid): void
    {
        $this->beginTx();
        $this->albumUpsert->execute(['id' => $legacyId, 'uuid' => $uuid]);
        ++$this->pendingWrites;
        $this->maybeCommit();
    }

    public function getPhotoUuid(int $legacyId): ?string
    {
        $this->photoSelect->execute(['id' => $legacyId]);
        $uuid = $this->photoSelect->fetchColumn();

        return false === $uuid ? null : (string) $uuid;
    }

    public function setPhotoUuid(int $legacyId, string $uuid): void
    {
        $this->beginTx();
        $this->photoUpsert->execute(['id' => $legacyId, 'uuid' => $uuid]);
        ++$this->pendingWrites;
        $this->maybeCommit();
    }

    public function getFileUuid(int $legacyAlbumId, string $filename): ?string
    {
        $this->fileSelect->execute(['key' => $this->fileKey($legacyAlbumId, $filename)]);
        $uuid = $this->fileSelect->fetchColumn();

        return false === $uuid ? null : (string) $uuid;
    }

    public function setFileUuid(int $legacyAlbumId, string $filename, string $uuid): void
    {
        $this->beginTx();
        $this->fileUpsert->execute(['key' => $this->fileKey($legacyAlbumId, $filename), 'uuid' => $uuid]);
        ++$this->pendingWrites;
        $this->maybeCommit();
    }

    /**
     * Flush any open transaction (call at end of import / album batch).
     */
    public function save(bool $force = false): void
    {
        if ($this->inTx && ($force || $this->pendingWrites > 0)) {
            $this->pdo->commit();
            $this->inTx = false;
            $this->pendingWrites = 0;
        }
    }

    private function beginTx(): void
    {
        if (!$this->inTx) {
            $this->pdo->beginTransaction();
            $this->inTx = true;
        }
    }

    private function maybeCommit(): void
    {
        if ($this->inTx && $this->pendingWrites >= 50) {
            $this->pdo->commit();
            $this->inTx = false;
            $this->pendingWrites = 0;
        }
    }

    private function isEmpty(): bool
    {
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM albums')->fetchColumn();

        return 0 === $count;
    }

    private function migrateFromJson(string $jsonPath): void
    {
        $raw = file_get_contents($jsonPath);
        if (false === $raw || '' === trim($raw)) {
            return;
        }

        /** @var array{albums?: array<string, string>, photos?: array<string, string>, files?: array<string, string>} $decoded */
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        $this->pdo->beginTransaction();
        $albumStmt = $this->pdo->prepare('INSERT OR REPLACE INTO albums (legacy_id, uuid) VALUES (:id, :uuid)');
        foreach ($decoded['albums'] ?? [] as $id => $uuid) {
            $albumStmt->execute(['id' => (int) $id, 'uuid' => $uuid]);
        }
        $photoStmt = $this->pdo->prepare('INSERT OR REPLACE INTO photos (legacy_id, uuid) VALUES (:id, :uuid)');
        foreach ($decoded['photos'] ?? [] as $id => $uuid) {
            $photoStmt->execute(['id' => (int) $id, 'uuid' => $uuid]);
        }
        $fileStmt = $this->pdo->prepare('INSERT OR REPLACE INTO files (file_key, uuid) VALUES (:key, :uuid)');
        foreach ($decoded['files'] ?? [] as $key => $uuid) {
            $fileStmt->execute(['key' => $key, 'uuid' => $uuid]);
        }
        $this->pdo->commit();
        unset($decoded, $raw);
    }

    private function fileKey(int $legacyAlbumId, string $filename): string
    {
        return $legacyAlbumId.':'.$filename;
    }
}
