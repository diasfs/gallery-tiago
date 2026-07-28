<?php

namespace App\Command;

use App\Entity\Album;
use App\Repository\AlbumRepository;
use App\Service\AlbumDateRangeParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:backfill-album-date-ranges',
    description: 'Extract dd/mm/yyyy periods or single dates from album descriptions into takenAt/takenAtEnd',
)]
class BackfillAlbumDateRangesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AlbumRepository $albums,
        private readonly AlbumDateRangeParser $parser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report matches without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        /** @var list<Album> $albums */
        $albums = $this->albums->createQueryBuilder('a')
            ->andWhere('a.description IS NOT NULL')
            ->getQuery()
            ->getResult();

        $updated = 0;
        foreach ($albums as $album) {
            $parsed = $this->parser->parse($album->getDescription());
            if (null === $parsed) {
                continue;
            }

            $isRange = null !== $parsed['end'];
            if ($isRange) {
                if (null !== $album->getTakenAtEnd()) {
                    continue;
                }
            } elseif (null !== $album->getTakenAt()) {
                // Single date only fills empty takenAt.
                continue;
            }

            ++$updated;
            if ($isRange) {
                $io->writeln(\sprintf(
                    '%s: %s → %s – %s',
                    $album->getSlug(),
                    $parsed['matchedSubstring'],
                    $parsed['start']->format('Y-m-d'),
                    $parsed['end']->format('Y-m-d'),
                ));
            } else {
                $io->writeln(\sprintf(
                    '%s: %s → %s',
                    $album->getSlug(),
                    $parsed['matchedSubstring'],
                    $parsed['start']->format('Y-m-d'),
                ));
            }

            if ($dryRun) {
                continue;
            }

            $album->setTakenAt($parsed['start']);
            $album->setTakenAtEnd($parsed['end']);
            $album->setDescription($parsed['descriptionWithoutRange']);
            $album->touch();
        }

        if (!$dryRun && $updated > 0) {
            $this->em->flush();
        }

        $io->success(\sprintf('%s %d album(s).', $dryRun ? 'Would update' : 'Updated', $updated));

        return Command::SUCCESS;
    }
}
