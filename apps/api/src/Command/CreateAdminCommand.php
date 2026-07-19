<?php

namespace App\Command;

use App\Entity\AdminUser;
use App\Repository\AdminUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'gallery:admin:create',
    description: 'Create an admin user for the Gallery admin API',
)]
class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AdminUserRepository $adminUsers,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Admin email address')
            ->addArgument('password', InputArgument::REQUIRED, 'Admin password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = (string) $input->getArgument('email');
        $password = (string) $input->getArgument('password');

        if ($this->adminUsers->findOneBy(['email' => $email])) {
            $io->error(\sprintf('An admin with email "%s" already exists.', $email));

            return Command::FAILURE;
        }

        $admin = new AdminUser($email, 'temp');
        $admin->setPassword($this->passwordHasher->hashPassword($admin, $password));

        $this->em->persist($admin);
        $this->em->flush();

        $io->success(\sprintf('Admin user "%s" created.', $email));

        return Command::SUCCESS;
    }
}
