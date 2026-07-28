<?php

namespace App\Tests\Command;

use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateAdminCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        foreach ($this->em->getRepository(AdminUser::class)->findAll() as $existing) {
            $this->em->remove($existing);
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->em->getRepository(AdminUser::class)->findAll() as $existing) {
            $this->em->remove($existing);
        }
        $this->em->flush();
        parent::tearDown();
    }

    public function testCreatesAdminUser(): void
    {
        $application = new Application(static::$kernel);
        $tester = new CommandTester($application->find('gallery:admin:create'));

        $exitCode = $tester->execute([
            'email' => 'cli-admin@gallery.test',
            'password' => 'super-secret',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('created', $tester->getDisplay());

        $admin = $this->em->getRepository(AdminUser::class)->findOneBy(['email' => 'cli-admin@gallery.test']);
        $this->assertNotNull($admin);
        $this->assertNotSame('super-secret', $admin->getPassword());
    }

    public function testRejectsDuplicateEmail(): void
    {
        $application = new Application(static::$kernel);

        (new CommandTester($application->find('gallery:admin:create')))->execute([
            'email' => 'dup-cli@gallery.test',
            'password' => 'first-pass',
        ]);

        $second = new CommandTester($application->find('gallery:admin:create'));
        $exitCode = $second->execute([
            'email' => 'dup-cli@gallery.test',
            'password' => 'second-pass',
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function testCreatesAdminWithNonEmailUsername(): void
    {
        $application = new Application(static::$kernel);
        $tester = new CommandTester($application->find('gallery:admin:create'));

        $exitCode = $tester->execute([
            'email' => 'Fabio',
            'password' => 'super-secret',
        ]);

        $this->assertSame(0, $exitCode);
        $admin = $this->em->getRepository(AdminUser::class)->findOneBy(['email' => 'fabio']);
        $this->assertNotNull($admin);
    }
}
