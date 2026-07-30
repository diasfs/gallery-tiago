<?php

namespace App\Command;

use App\Message\DetectFacesMessage;
use App\Message\SuggestTagsMessage;
use App\Repository\PhotoRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Republish Messenger jobs for photos stuck in faces/tags `queued` status.
 *
 * Needed after migrating inherited `detecting` rows to `queued`, or when
 * stream messages were lost while status remained queued. Safe to re-run:
 * workers ACK duplicates once a terminal status is already persisted.
 */
#[AsCommand(
    name: 'app:reconcile-queued-processing',
    description: 'Republish faces/tags jobs for photos with status queued',
)]
final class ReconcileQueuedProcessingCommand extends Command
{
    private const DEFAULT_BATCH = 500;

    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'stage',
                null,
                InputOption::VALUE_REQUIRED,
                'Stage to reconcile: faces|tags|all',
                'all',
            )
            ->addOption(
                'batch',
                null,
                InputOption::VALUE_REQUIRED,
                'Max photos to enqueue per stage per run',
                (string) self::DEFAULT_BATCH,
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'List how many would be enqueued without dispatching',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $stage = (string) $input->getOption('stage');
        $batch = max(1, (int) $input->getOption('batch'));
        $dryRun = (bool) $input->getOption('dry-run');

        $stages = match ($stage) {
            'faces' => ['faces'],
            'tags' => ['tags'],
            'all' => ['faces', 'tags'],
            default => null,
        };
        if (null === $stages) {
            $io->error('Invalid --stage; expected faces|tags|all.');

            return Command::FAILURE;
        }

        $totals = [];
        foreach ($stages as $s) {
            $photos = $this->photos->findQueuedForStage($s, $batch);
            $count = \count($photos);
            $totals[$s] = $count;

            if ($dryRun) {
                $io->writeln(\sprintf('Would enqueue %d %s job(s).', $count, $s));
                continue;
            }

            foreach ($photos as $photo) {
                $photoId = (string) $photo->getId();
                if ('faces' === $s) {
                    $this->bus->dispatch(new DetectFacesMessage($photoId));
                } else {
                    $this->bus->dispatch(new SuggestTagsMessage($photoId));
                }
            }
            $io->writeln(\sprintf('Enqueued %d %s job(s).', $count, $s));
        }

        if ($dryRun) {
            $io->success(\sprintf(
                'Dry run complete (faces=%d, tags=%d).',
                $totals['faces'] ?? 0,
                $totals['tags'] ?? 0,
            ));
        } else {
            $io->success(\sprintf(
                'Reconcile complete (faces=%d, tags=%d). Re-run if more remain beyond --batch.',
                $totals['faces'] ?? 0,
                $totals['tags'] ?? 0,
            ));
        }

        return Command::SUCCESS;
    }
}
