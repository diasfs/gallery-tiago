<?php

namespace App\Command;

use App\Service\V3Import\PdoV3GallerySource;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:backfill-photo-filenames',
    description: 'Populate photo.filename from the v3 MySQL foto table (via import map legacy ids)',
)]
final class BackfillPhotoFilenamesCommand extends Command
{
    private const BATCH_SIZE = 1000;

    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('map-path', null, InputOption::VALUE_REQUIRED, 'Import map path (.sqlite)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report without writing');
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
            $io->error('Set V3_DATABASE_URL to backfill filenames from gallery v3.');

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $source = PdoV3GallerySource::fromDatabaseUrl($databaseUrl);
        $mapPdo = new \PDO('sqlite:'.$mapPath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $rows = $mapPdo->query('SELECT legacy_id, uuid FROM photos')->fetchAll(\PDO::FETCH_ASSOC);

        $updated = 0;
        $skipped = 0;
        $missingPhoto = 0;
        $missingFilename = 0;
        $conflicts = 0;
        $warnings = 0;

        $updateSql = <<<'SQL'
            UPDATE photo AS p
            SET filename = :filename
            WHERE p.id = :id
              AND p.filename IS NULL
              AND NOT EXISTS (
                  SELECT 1
                  FROM photo AS p2
                  WHERE p2.album_id = p.album_id
                    AND p2.filename = :filename
                    AND p2.id <> p.id
              )
            SQL;

        $total = \count($rows);
        $io->writeln(\sprintf('Processing %d mapped photos…', $total));

        foreach (array_chunk($rows, self::BATCH_SIZE) as $chunkIndex => $chunk) {
            $legacyIds = array_map(static fn (array $row): int => (int) $row['legacy_id'], $chunk);
            $filenamesByLegacyId = $source->fetchFilenamesForPhotoIds($legacyIds);
            /** @var array<string, true> */
            $reservedInBatch = [];

            $uuids = array_map(static fn (array $row): string => (string) $row['uuid'], $chunk);
            $placeholders = implode(',', array_fill(0, \count($uuids), '?'));
            /** @var array<string, array{filename: ?string, album_id: string}> $photoStates */
            $photoStates = [];
            $stateRows = $this->connection->fetchAllAssociative(
                \sprintf('SELECT id::text AS id, filename, album_id::text AS album_id FROM photo WHERE id IN (%s)', $placeholders),
                $uuids,
            );
            foreach ($stateRows as $stateRow) {
                $photoStates[(string) $stateRow['id']] = [
                    'filename' => null !== $stateRow['filename'] ? (string) $stateRow['filename'] : null,
                    'album_id' => (string) $stateRow['album_id'],
                ];
            }

            foreach ($chunk as $row) {
                $legacyId = (int) $row['legacy_id'];
                $uuid = (string) $row['uuid'];
                $filename = $filenamesByLegacyId[$legacyId] ?? null;
                if (null === $filename) {
                    ++$missingFilename;
                    continue;
                }

                $current = $photoStates[$uuid] ?? null;
                if (null === $current) {
                    ++$missingPhoto;
                    continue;
                }

                $existingFilename = $current['filename'];
                if ($existingFilename === $filename) {
                    ++$skipped;
                    continue;
                }

                if (null !== $existingFilename && $existingFilename !== $filename) {
                    ++$warnings;
                    continue;
                }

                $reservationKey = $current['album_id'].':'.$filename;
                if (isset($reservedInBatch[$reservationKey])) {
                    ++$conflicts;
                    continue;
                }

                if ($dryRun) {
                    ++$updated;
                    $reservedInBatch[$reservationKey] = true;
                    continue;
                }

                $affected = $this->connection->executeStatement($updateSql, [
                    'id' => $uuid,
                    'filename' => $filename,
                ]);

                if (0 === $affected) {
                    ++$conflicts;
                    continue;
                }

                ++$updated;
                $reservedInBatch[$reservationKey] = true;
            }

            if (0 === ($chunkIndex + 1) % 10) {
                $io->writeln(\sprintf('… %d / %d batches', $chunkIndex + 1, (int) ceil($total / self::BATCH_SIZE)));
            }
        }

        $io->success(\sprintf(
            'Done. updated=%d skipped=%d missing_photo=%d missing_v3_filename=%d conflicts=%d warnings=%d%s',
            $updated,
            $skipped,
            $missingPhoto,
            $missingFilename,
            $conflicts,
            $warnings,
            $dryRun ? ' (dry-run)' : '',
        ));

        if (!$dryRun && ($conflicts > 0 || $missingFilename > 0)) {
            $io->note('Run app:reconcile-photo-filename-duplicates to delete duplicate rows and keep one filename per album.');
        }

        return Command::SUCCESS;
    }
}
