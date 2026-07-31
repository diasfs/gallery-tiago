<?php

namespace App\Command;

use App\Entity\Photo;
use App\Repository\PhotoRepository;
use App\Service\PhotoDeleter;
use App\Service\V3Import\PdoV3GallerySource;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:reconcile-photo-filename-duplicates',
    description: 'Keep one photo per album/filename (highest view count) and delete duplicate rows',
)]
final class ReconcilePhotoFilenameDuplicatesCommand extends Command
{
    private const BATCH_SIZE = 1000;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        private readonly PhotoRepository $photos,
        private readonly PhotoDeleter $photoDeleter,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('map-path', null, InputOption::VALUE_REQUIRED, 'Import map path (.sqlite)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mapPath = $input->getOption('map-path');
        if (!\is_string($mapPath) || '' === $mapPath) {
            $mapPath = $this->projectDir.'/var/v3_import_map.sqlite';
        }

        if (!is_file($mapPath)) {
            $io->error(\sprintf('Map file not found: %s', $mapPath));

            return Command::FAILURE;
        }

        $databaseUrl = $_ENV['V3_DATABASE_URL'] ?? getenv('V3_DATABASE_URL') ?: null;
        if (!\is_string($databaseUrl) || '' === $databaseUrl) {
            $io->error('Set V3_DATABASE_URL to resolve duplicate filenames from gallery v3.');

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $source = PdoV3GallerySource::fromDatabaseUrl($databaseUrl);
        $mapPdo = new \PDO('sqlite:'.$mapPath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $rows = $mapPdo->query('SELECT legacy_id, uuid FROM photos')->fetchAll(\PDO::FETCH_ASSOC);

        $legacyIds = array_map(static fn (array $row): int => (int) $row['legacy_id'], $rows);
        $filenamesByLegacyId = [];
        foreach (array_chunk($legacyIds, self::BATCH_SIZE) as $chunk) {
            $filenamesByLegacyId += $source->fetchFilenamesForPhotoIds($chunk);
        }

        /** @var array<string, list<array{id: string, view_count: int, sort_order: int, filename: ?string}>> $groups */
        $groups = [];

        foreach (array_chunk($rows, self::BATCH_SIZE) as $chunk) {
            $uuids = array_map(static fn (array $row): string => (string) $row['uuid'], $chunk);
            $placeholders = implode(',', array_fill(0, \count($uuids), '?'));
            $stateRows = $this->connection->fetchAllAssociative(
                \sprintf(
                    'SELECT id::text AS id, album_id::text AS album_id, view_count, sort_order, filename
                     FROM photo WHERE id IN (%s)',
                    $placeholders,
                ),
                $uuids,
            );
            $stateById = [];
            foreach ($stateRows as $stateRow) {
                $stateById[(string) $stateRow['id']] = $stateRow;
            }

            foreach ($chunk as $row) {
                $legacyId = (int) $row['legacy_id'];
                $uuid = (string) $row['uuid'];
                $filename = $filenamesByLegacyId[$legacyId] ?? null;
                if (null === $filename) {
                    continue;
                }

                $state = $stateById[$uuid] ?? null;
                if (null === $state) {
                    continue;
                }

                $key = $state['album_id'].'|'.$filename;
                $groups[$key][] = [
                    'id' => $uuid,
                    'view_count' => (int) $state['view_count'],
                    'sort_order' => (int) $state['sort_order'],
                    'filename' => null !== $state['filename'] ? (string) $state['filename'] : null,
                ];
            }
        }

        $assigned = 0;
        $deleted = 0;
        $unchanged = 0;
        $duplicateGroups = 0;
        $toDelete = [];
        /** @var list<array{id: string, filename: string}> $pendingAssignments */
        $pendingAssignments = [];

        foreach ($groups as $key => $candidates) {
            if (\count($candidates) < 2) {
                $only = $candidates[0];
                if (null === $only['filename']) {
                    [, $filename] = explode('|', $key, 2);
                    $pendingAssignments[] = ['id' => $only['id'], 'filename' => $filename];
                    ++$assigned;
                } else {
                    ++$unchanged;
                }
                continue;
            }

            ++$duplicateGroups;
            [, $filename] = explode('|', $key, 2);
            $winner = $this->pickWinner($candidates);

            foreach ($candidates as $candidate) {
                if ($candidate['id'] !== $winner['id']) {
                    $toDelete[] = $candidate['id'];
                }
            }

            if ($winner['filename'] !== $filename) {
                $pendingAssignments[] = ['id' => $winner['id'], 'filename' => $filename];
                ++$assigned;
            } else {
                ++$unchanged;
            }
        }

        $orphanIds = $this->connection->fetchFirstColumn(
            <<<'SQL'
            SELECT loser.id::text
            FROM photo loser
            WHERE loser.filename IS NULL
              AND loser.title IS NOT NULL
              AND loser.title <> ''
              AND EXISTS (
                  SELECT 1
                  FROM photo winner
                  WHERE winner.album_id = loser.album_id
                    AND winner.id <> loser.id
                    AND winner.filename IS NOT NULL
                    AND winner.title = loser.title
              )
            SQL,
        );

        $toDelete = array_values(array_unique([...$toDelete, ...$orphanIds]));
        $deleted = \count($toDelete);

        if (!$dryRun) {
            $this->deletePhotosById($toDelete);

            foreach ($pendingAssignments as $assignment) {
                $this->connection->executeStatement(
                    'UPDATE photo SET filename = :filename WHERE id = :id',
                    $assignment,
                );
            }
        }

        $remaining = $dryRun
            ? (int) $this->connection->fetchOne('SELECT COUNT(*) FROM photo WHERE filename IS NULL')
            : (int) $this->connection->fetchOne('SELECT COUNT(*) FROM photo WHERE filename IS NULL');

        $io->success(\sprintf(
            'Done. duplicate_groups=%d assigned=%d deleted=%d unchanged=%d remaining_without_filename=%d%s',
            $duplicateGroups,
            $assigned,
            $deleted,
            $unchanged,
            $dryRun ? $remaining - $deleted : $remaining,
            $dryRun ? ' (dry-run)' : '',
        ));

        if (!$dryRun && $remaining > 0) {
            $io->warning(\sprintf('%d photos still have no filename; run app:backfill-photo-filenames.', $remaining));
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $ids
     */
    private function deletePhotosById(array $ids): void
    {
        foreach (array_chunk($ids, 100) as $chunk) {
            $entities = [];
            foreach ($chunk as $id) {
                $photo = $this->photos->find(Uuid::fromString($id));
                if ($photo instanceof Photo) {
                    $entities[] = $photo;
                }
            }
            if ([] === $entities) {
                continue;
            }
            $this->photoDeleter->deleteMany($entities);
            $this->em->clear();
        }
    }

    /**
     * @param list<array{id: string, view_count: int, sort_order: int, filename: ?string}> $candidates
     *
     * @return array{id: string, view_count: int, sort_order: int, filename: ?string}
     */
    private function pickWinner(array $candidates): array
    {
        usort($candidates, static function (array $a, array $b): int {
            $byViews = $b['view_count'] <=> $a['view_count'];
            if (0 !== $byViews) {
                return $byViews;
            }

            $byOrder = $a['sort_order'] <=> $b['sort_order'];
            if (0 !== $byOrder) {
                return $byOrder;
            }

            return $a['id'] <=> $b['id'];
        });

        return $candidates[0];
    }
}
