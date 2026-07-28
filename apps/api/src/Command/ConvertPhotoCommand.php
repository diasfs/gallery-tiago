<?php

namespace App\Command;

use App\Message\ConvertMediaMessage;
use App\MessageHandler\ConvertMediaHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:convert-photo',
    description: 'Run ConvertMediaHandler synchronously for one photo (bypasses the convert queue)',
)]
final class ConvertPhotoCommand extends Command
{
    public function __construct(
        private readonly ConvertMediaHandler $handler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('photo-id', InputArgument::REQUIRED, 'Photo UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $photoId = (string) $input->getArgument('photo-id');
        ($this->handler)(new ConvertMediaMessage($photoId));
        $io->success(\sprintf('Convert finished for %s.', $photoId));

        return Command::SUCCESS;
    }
}
