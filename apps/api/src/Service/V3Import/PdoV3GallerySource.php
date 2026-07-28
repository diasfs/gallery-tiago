<?php

namespace App\Service\V3Import;

/**
 * Connects to a live v3 MySQL database via PDO.
 *
 * Long imports against a remote host often hit "MySQL server has gone away"
 * (wait_timeout / network blip). This source reconnects and retries once.
 */
final class PdoV3GallerySource implements V3GallerySourceInterface
{
    private \PDO $pdo;

    private readonly bool $hasAlbumDataColumn;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $pass,
    ) {
        $this->pdo = $this->connect();
        $this->hasAlbumDataColumn = $this->columnExists('album', 'data');
    }

    public static function fromDatabaseUrl(string $databaseUrl): self
    {
        $parts = parse_url($databaseUrl);
        if (false === $parts || !isset($parts['host'], $parts['path'])) {
            throw new \InvalidArgumentException('V3_DATABASE_URL is not a valid URL.');
        }

        $scheme = $parts['scheme'] ?? '';
        if (!\in_array($scheme, ['mysql', 'mysqli'], true)) {
            throw new \InvalidArgumentException(\sprintf('V3_DATABASE_URL scheme must be mysql, got "%s".', $scheme));
        }

        $dbName = ltrim($parts['path'], '/');
        if ('' === $dbName) {
            throw new \InvalidArgumentException('V3_DATABASE_URL is missing a database name.');
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? 3306;
        $user = isset($parts['user']) ? rawurldecode($parts['user']) : '';
        $pass = isset($parts['pass']) ? rawurldecode($parts['pass']) : '';

        $dsn = \sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbName);

        return new self($dsn, $user, $pass);
    }

    public function fetchAlbums(): array
    {
        $dataSelect = $this->hasAlbumDataColumn ? 'a.`data`' : 'NULL AS `data`';
        $sql = <<<SQL
            SELECT a.id_album, a.id_pai, a.titulo, a.descricao, a.url, a.ativo, a.ordem, {$dataSelect}
            FROM album a
            ORDER BY a.id_album ASC
            SQL;

        $rows = $this->run(fn (): array => $this->pdo->query($sql)->fetchAll());

        return array_map(static function (array $row): array {
            return [
                'id_album' => (int) $row['id_album'],
                'id_pai' => (int) $row['id_pai'],
                'titulo' => (string) $row['titulo'],
                'descricao' => null !== $row['descricao'] && '' !== $row['descricao'] ? (string) $row['descricao'] : null,
                'url' => (string) $row['url'],
                'ativo' => (string) $row['ativo'],
                'ordem' => (int) $row['ordem'],
                'data' => null !== $row['data'] && '' !== $row['data'] ? (string) $row['data'] : null,
            ];
        }, $rows);
    }

    public function fetchPhotosForAlbum(int $albumId): array
    {
        $rows = $this->run(function () use ($albumId): array {
            $stmt = $this->pdo->prepare(
                'SELECT id_foto, id_album, titulo, foto, ordem FROM foto WHERE id_album = :id ORDER BY ordem ASC, id_foto ASC'
            );
            $stmt->execute(['id' => $albumId]);

            return $stmt->fetchAll();
        });

        return array_map(static function (array $row): array {
            return [
                'id_foto' => (int) $row['id_foto'],
                'id_album' => (int) $row['id_album'],
                'titulo' => null !== $row['titulo'] && '' !== $row['titulo'] ? (string) $row['titulo'] : null,
                'foto' => (string) $row['foto'],
                'ordem' => (int) $row['ordem'],
            ];
        }, $rows);
    }

    public function fetchDestaques(): array
    {
        $rows = $this->run(fn (): array => $this->pdo->query('SELECT id_album, foto, url FROM destaque')->fetchAll());

        return array_map(static function (array $row): array {
            return [
                'id_album' => (int) $row['id_album'],
                'foto' => (string) $row['foto'],
                'url' => (string) $row['url'],
            ];
        }, $rows);
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function run(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (\PDOException $e) {
            if (!$this->isGoneAway($e)) {
                throw $e;
            }
            $this->pdo = $this->connect();

            return $operation();
        }
    }

    private function connect(): \PDO
    {
        $pdo = new \PDO($this->dsn, $this->user, $this->pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT => 60,
        ]);

        try {
            $pdo->exec('SET SESSION wait_timeout=28800, interactive_timeout=28800, net_read_timeout=600, net_write_timeout=600');
        } catch (\PDOException) {
            // Some hosts disallow SESSION timeout tweaks; reconnect-on-gone-away still covers us.
        }

        $pdo->query('SELECT 1');

        return $pdo;
    }

    private function isGoneAway(\PDOException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'server has gone away')
            || str_contains($message, 'Lost connection')
            || str_contains($message, 'Error while sending')
            || (isset($e->errorInfo[1]) && \in_array((int) $e->errorInfo[1], [2006, 2013], true));
    }

    private function columnExists(string $table, string $column): bool
    {
        return $this->run(function () use ($table, $column): bool {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column
                 LIMIT 1'
            );
            $stmt->execute(['table' => $table, 'column' => $column]);

            return false !== $stmt->fetchColumn();
        });
    }
}
