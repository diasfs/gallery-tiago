<?php

namespace App\Command;

use App\Entity\Photo;
use App\Enum\MediaStatus;
use App\Repository\PhotoRepository;
use App\Service\MediaStorage;
use App\Service\PhotoReprocessor;
use App\Service\ProcessingErrorBag;
use App\Service\V3Import\V3Importer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recopy originals from the v3 _img tree when a photo has no file on disk
 * (import gap / accidental delete) and re-enqueue convert.
 */
#[AsCommand(
    name: 'app:restore-missing-originals',
    description: 'Restore missing photo originals from the v3 _img tree and re-enqueue convert',
)]
final class RestoreMissingOriginalsCommand extends Command
{
    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly MediaStorage $storage,
        private readonly V3Importer $importer,
        private readonly PhotoReprocessor $reprocessor,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('img-root', null, InputOption::VALUE_REQUIRED, 'Path to v3 _img root', '/var/gallery/v3-img')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List targets without copying or enqueueing')
            ->addOption('photo-id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit to specific photo UUID(s)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $imgRoot = (string) $input->getOption('img-root');

        if (!is_dir($imgRoot)) {
            $io->error(\sprintf('img-root "%s" is not a directory.', $imgRoot));

            return Command::FAILURE;
        }

        $targets = $this->findTargets((array) $input->getOption('photo-id'));
        if ([] === $targets) {
            $io->success('No photos need original restoration.');

            return Command::SUCCESS;
        }

        $restored = 0;
        $skipped = 0;
        foreach ($targets as $photo) {
            $photoId = (string) $photo->getId();

            // Convert already produced AVIF, but a later convert attempt failed
            // after originals were purged — just clear the false failure.
            if (null !== $photo->getAvifPath()) {
                if ($dryRun) {
                    $io->writeln(\sprintf('Would mark done (AVIF present) %s', $photoId));
                    ++$restored;
                    continue;
                }
                $photo->setMediaStatus(MediaStatus::Done);
                $photo->setProcessingError(
                    ProcessingErrorBag::clear($photo->getProcessingError(), 'media'),
                );
                $this->em->flush();
                $io->writeln(\sprintf('Marked done (AVIF present) %s', $photoId));
                ++$restored;
                continue;
            }

            $album = $photo->getAlbum();
            $slug = $album->getSlug();
            $title = $photo->getTitle();
            if (null === $slug || '' === $slug || null === $title || '' === $title) {
                $io->warning(\sprintf('Skip %s: missing album slug or title.', $photo->getId()));
                ++$skipped;
                continue;
            }

            $filename = pathinfo($title, \PATHINFO_EXTENSION) ? $title : $title.'.jpg';
            $source = $this->importer->resolveSourceFile($imgRoot, $slug, $filename);
            if (null === $source) {
                $io->warning(\sprintf('Skip %s: no v3 file for %s/%s', $photo->getId(), $slug, $filename));
                ++$skipped;
                continue;
            }

            if ($dryRun) {
                $io->writeln(\sprintf('Would restore %s ← %s', $photoId, $source));
                ++$restored;
                continue;
            }

            $relative = $this->storage->storeOriginalFromPath($source, $photoId);
            $photo->setOriginalPath($relative);
            $this->em->flush();
            $this->reprocessor->reprocess($photo);
            $io->writeln(\sprintf('Restored %s ← %s', $photoId, $source));
            ++$restored;
        }

        $io->success(\sprintf(
            '%s %d photo(s)%s.',
            $dryRun ? 'Would restore' : 'Restored',
            $restored,
            $skipped > 0 ? \sprintf(', skipped %d', $skipped) : '',
        ));

        return $skipped > 0 && 0 === $restored ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param list<string> $photoIds
     *
     * @return list<Photo>
     */
    private function findTargets(array $photoIds): array
    {
        $candidates = [] === $photoIds
            ? $this->photos->findNeedingOriginalRestore()
            : array_values(array_filter(
                array_map(fn (string $id) => $this->photos->find($id), $photoIds),
                static fn ($photo) => $photo instanceof Photo,
            ));

        $out = [];
        foreach ($candidates as $photo) {
            $relative = $photo->getOriginalPath();
            $missingOriginal = null === $relative || '' === $relative
                || !is_file($this->storage->absolutePath($relative));

            if (null !== $photo->getAvifPath()) {
                // False failure after purge-originals + re-enqueue convert.
                if (MediaStatus::Failed === $photo->getMediaStatus()) {
                    $out[] = $photo;
                }
                continue;
            }

            if ($missingOriginal) {
                $out[] = $photo;
            }
        }

        return $out;
    }
}
