<?php

namespace App\Command;

use App\Service\V3Import\PdoV3GallerySource;
use App\Service\V3Import\V3Importer;
use App\Service\V3Import\V3ImportOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:import-v3',
    description: 'Import albums/photos from a live gallery v3 MySQL DB + _img tree (set V3_DATABASE_URL). Prefer: php bin/console --no-debug app:import-v3',
)]
final class ImportV3Command extends Command
{
    public function __construct(
        private readonly V3Importer $importer,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('img-root', null, InputOption::VALUE_REQUIRED, 'Path to v3 _img root', '/var/gallery/v3-img')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count/list only; no DB or file writes')
            ->addOption('limit-albums', null, InputOption::VALUE_REQUIRED, 'Max albums to process (after parent ordering)')
            ->addOption('limit-photos', null, InputOption::VALUE_REQUIRED, 'Max photos to process across all albums')
            ->addOption('album', null, InputOption::VALUE_REQUIRED, 'Import only this v3 album url (plus ancestors/descendants)')
            ->addOption('skip-convert', null, InputOption::VALUE_NONE, 'Copy + persist Photo without ConvertMediaMessage')
            ->addOption('map-path', null, InputOption::VALUE_REQUIRED, 'Idempotency map path (.sqlite; .json migrates once)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '512M');

        $io = new SymfonyStyle($input, $output);
        if ($output->isVerbose()) {
            $io->note('Tip: use --no-debug to avoid Symfony debug collectors during large imports.');
        }

        $databaseUrl = $_ENV['V3_DATABASE_URL'] ?? getenv('V3_DATABASE_URL') ?: null;
        if (!\is_string($databaseUrl) || '' === $databaseUrl) {
            $io->error('Set V3_DATABASE_URL (e.g. mysql://user:pass@host:3306/gallery).');

            return Command::FAILURE;
        }

        $imgRoot = (string) $input->getOption('img-root');
        if (!is_dir($imgRoot)) {
            $io->error(\sprintf('img-root "%s" is not a directory.', $imgRoot));

            return Command::FAILURE;
        }

        $mapPath = $input->getOption('map-path');
        if (!\is_string($mapPath) || '' === $mapPath) {
            $mapPath = $this->projectDir.'/var/v3_import_map.json';
        }

        $limitAlbums = $input->getOption('limit-albums');
        $limitPhotos = $input->getOption('limit-photos');

        $options = new V3ImportOptions(
            imgRoot: $imgRoot,
            mapPath: $mapPath,
            dryRun: (bool) $input->getOption('dry-run'),
            limitAlbums: null !== $limitAlbums && '' !== $limitAlbums ? (int) $limitAlbums : null,
            limitPhotos: null !== $limitPhotos && '' !== $limitPhotos ? (int) $limitPhotos : null,
            albumUrl: $input->getOption('album') ? (string) $input->getOption('album') : null,
            skipConvert: (bool) $input->getOption('skip-convert'),
        );

        try {
            $source = PdoV3GallerySource::fromDatabaseUrl($databaseUrl);
        } catch (\Throwable $e) {
            $io->error('Failed to connect to v3 MySQL: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->writeln($options->dryRun ? 'Dry-run import from v3…' : 'Importing from v3…');

        try {
            $stats = $this->importer->import($source, $options);
        } catch (\Throwable $e) {
            $io->error('Import failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->table(
            ['Metric', 'Count'],
            [
                ['Albums created', (string) $stats->albumsCreated],
                ['Albums updated', (string) $stats->albumsUpdated],
                ['Albums skipped', (string) $stats->albumsSkipped],
                ['Photos created', (string) $stats->photosCreated],
                ['Photos skipped (mapped)', (string) $stats->photosSkipped],
                ['Photos missing file', (string) $stats->photosMissingFile],
                ['Convert dispatched', (string) $stats->convertDispatched],
                ['Covers set', (string) $stats->coversSet],
            ]
        );

        if ($stats->missingFiles !== [] && $io->isVerbose()) {
            $io->section('Missing files (first 50)');
            foreach (\array_slice($stats->missingFiles, 0, 50) as $missing) {
                $io->writeln('  '.$missing);
            }
        }

        $io->success($options->dryRun ? 'Dry-run complete.' : 'Import complete.');

        return Command::SUCCESS;
    }
}
