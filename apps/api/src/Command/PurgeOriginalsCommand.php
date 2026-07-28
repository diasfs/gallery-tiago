<?php

namespace App\Command;

use App\Repository\PhotoRepository;
use App\Service\MediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:purge-originals',
    description: 'Delete retained original files for photos that already have an AVIF master',
)]
final class PurgeOriginalsCommand extends Command
{
    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly MediaStorage $storage,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List targets without deleting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $targets = $this->photos->findWithOriginalAndAvif();
        if ([] === $targets) {
            $io->success('No originals to purge.');

            return Command::SUCCESS;
        }

        $purged = 0;
        foreach ($targets as $photo) {
            $relative = $photo->getOriginalPath();
            if (null === $relative || '' === $relative) {
                continue;
            }

            if ($dryRun) {
                $io->writeln(\sprintf('Would purge %s (photo %s)', $relative, $photo->getId()));
                ++$purged;
                continue;
            }

            $this->storage->deleteRelative($relative);
            $photo->setOriginalPath(null);
            ++$purged;
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(\sprintf('%s %d original(s).', $dryRun ? 'Would purge' : 'Purged', $purged));

        return Command::SUCCESS;
    }
}
