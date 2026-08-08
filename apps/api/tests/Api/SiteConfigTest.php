<?php

namespace App\Tests\Api;

use App\Entity\ProcessingSettings;
use App\Enum\AlbumPhotoLayout;
use App\Repository\ProcessingSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SiteConfigTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->resetSettings();
    }

    protected function tearDown(): void
    {
        $this->resetSettings();
        parent::tearDown();
    }

    public function testReturnsDefaultAlbumPhotoLayout(): void
    {
        $this->client->request('GET', '/api/site-config');
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('grid', $data['albumPhotoLayout']);
        $this->assertTrue($data['mostViewedHomeEnabled']);
        $this->assertFalse($data['mostViewedExcludeRootAlbums']);
    }

    public function testReflectsPersistedAlbumPhotoLayout(): void
    {
        $settings = static::getContainer()->get(ProcessingSettingsRepository::class)->getSingleton();
        $settings->setAlbumPhotoLayout(AlbumPhotoLayout::MasonryHorizontal);
        $this->em->flush();

        $this->client->request('GET', '/api/site-config');
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('masonry_horizontal', $data['albumPhotoLayout']);
    }

    public function testReflectsMostViewedFlags(): void
    {
        $settings = static::getContainer()->get(ProcessingSettingsRepository::class)->getSingleton();
        $settings->setMostViewedHomeEnabled(false);
        $settings->setMostViewedExcludeRootAlbums(true);
        $this->em->flush();

        $this->client->request('GET', '/api/site-config');
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true)['data'];
        $this->assertFalse($data['mostViewedHomeEnabled']);
        $this->assertTrue($data['mostViewedExcludeRootAlbums']);
    }

    private function resetSettings(): void
    {
        $row = $this->em->find(ProcessingSettings::class, ProcessingSettings::SINGLETON_ID);
        if (null === $row) {
            $row = ProcessingSettings::defaults();
            $this->em->persist($row);
        } else {
            $row->setAlbumPhotoLayout(AlbumPhotoLayout::Grid);
            $row->setMostViewedHomeEnabled(true);
            $row->setMostViewedExcludeRootAlbums(false);
        }
        $this->em->flush();
    }
}
