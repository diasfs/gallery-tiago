<?php

namespace App\Tests\Service\V3Import;

use App\Entity\Album;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use App\Message\ConvertMediaMessage;
use App\Repository\AlbumRepository;
use App\Repository\PhotoRepository;
use App\Service\V3Import\ArrayV3GallerySource;
use App\Service\V3Import\V3Importer;
use App\Service\V3Import\V3ImportOptions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class V3ImporterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private V3Importer $importer;
    private string $imgRoot;
    private string $mapPath;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->importer = static::getContainer()->get(V3Importer::class);
        $this->clearGallery();

        $this->imgRoot = sys_get_temp_dir().'/v3-import-img-'.uniqid('', true);
        $this->mapPath = sys_get_temp_dir().'/v3-import-map-'.uniqid('', true).'.json';
        mkdir($this->imgRoot.'/root-album', 0775, true);
        mkdir($this->imgRoot.'/child-album', 0775, true);

        $fixture = \dirname(__DIR__, 2).'/fixtures/sample.jpg';
        copy($fixture, $this->imgRoot.'/root-album/gr_cover.jpg');
        copy($fixture, $this->imgRoot.'/child-album/photo-a.jpg');
        // photo-b intentionally missing → skipped as missing-file

        $this->convertTransport()->reset();
    }

    protected function tearDown(): void
    {
        $this->clearGallery();
        $this->removeTree($this->imgRoot);
        foreach ([$this->mapPath, preg_replace('/\.json$/', '.sqlite', $this->mapPath)] as $path) {
            if (\is_string($path) && is_file($path)) {
                @unlink($path);
            }
            if (\is_string($path) && is_file($path.'-wal')) {
                @unlink($path.'-wal');
            }
            if (\is_string($path) && is_file($path.'-shm')) {
                @unlink($path.'-shm');
            }
        }
        parent::tearDown();
    }

    public function testImportsAlbumTreePhotosAndDispatchesConvert(): void
    {
        $source = $this->fixtureSource();
        $stats = $this->importer->import($source, $this->options());

        $this->assertSame(4, $stats->albumsCreated);
        $this->assertSame(2, $stats->photosCreated);
        $this->assertSame(1, $stats->photosMissingFile);
        $this->assertSame(2, $stats->convertDispatched);
        $this->assertSame(1, $stats->coversSet);

        /** @var AlbumRepository $albums */
        $albums = $this->em->getRepository(Album::class);
        $root = $albums->findOneBySlug('root-album');
        $child = $albums->findOneBySlug('child-album');
        $trip = $albums->findOneBySlug('trip-album');
        $party = $albums->findOneBySlug('party-album');
        $this->assertNotNull($root);
        $this->assertNotNull($trip);
        $this->assertNotNull($party);
        $this->assertSame('Férias', $trip->getDescription());
        $this->assertSame('2024-06-01', $trip->getTakenAt()?->format('Y-m-d'));
        $this->assertSame('2024-06-05', $trip->getTakenAtEnd()?->format('Y-m-d'));
        $this->assertNull($party->getDescription());
        $this->assertSame('2019-08-15', $party->getTakenAt()?->format('Y-m-d'));
        $this->assertNull($party->getTakenAtEnd());
        $this->assertSame('2012-01-15', $root->getTakenAt()?->format('Y-m-d'));
        $this->assertNull($root->getTakenAtEnd());
        $this->assertNotNull($child);
        $this->assertSame(AlbumVisibility::Public, $root->getVisibility());
        $this->assertSame(AlbumVisibility::Private, $child->getVisibility());
        $this->assertSame($root->getId()->toRfc4122(), $child->getParent()?->getId()->toRfc4122());
        $this->assertNotNull($root->getCoverPhoto());
        $this->assertSame(42, $root->getViewCount());
        $this->assertSame(0, $child->getViewCount());
        $this->assertSame(30, $root->getPhotosPerPage());
        $this->assertSame(48, $child->getPhotosPerPage());

        /** @var PhotoRepository $photos */
        $photos = $this->em->getRepository(Photo::class);
        $this->assertCount(2, $photos->findAll());
        $cover = $photos->findOneBy(['title' => 'Cover']);
        $this->assertNotNull($cover);
        $this->assertSame(0, $cover->getSortOrder());
        $this->assertSame(99, $cover->getViewCount());
        $childPhoto = $photos->findOneBy(['album' => $child]);
        $this->assertNotNull($childPhoto);
        $this->assertSame(0, $childPhoto->getSortOrder());
        $this->assertSame(5, $childPhoto->getViewCount());

        $envelopes = $this->convertTransport()->get();
        $this->assertCount(2, $envelopes);
        foreach ($envelopes as $envelope) {
            $this->assertInstanceOf(ConvertMediaMessage::class, $envelope->getMessage());
        }
    }

    public function testSecondRunIsIdempotentViaMap(): void
    {
        $source = $this->fixtureSource();
        $this->importer->import($source, $this->options());
        $this->convertTransport()->reset();

        /** @var PhotoRepository $photos */
        $photos = $this->em->getRepository(Photo::class);
        $cover = $photos->findOneBy(['title' => 'Cover']);
        $this->assertNotNull($cover);
        $cover->setSortOrder(99);
        $this->em->flush();

        $stats = $this->importer->import($source, $this->options());

        $this->assertSame(0, $stats->albumsCreated);
        $this->assertSame(4, $stats->albumsUpdated);
        $this->assertSame(0, $stats->photosCreated);
        $this->assertSame(2, $stats->photosSkipped);
        $this->assertSame(2, $stats->photosSortUpdated);
        $this->assertSame(1, $stats->photosMissingFile);
        $this->assertSame(0, $stats->convertDispatched);
        $this->assertCount(0, $this->convertTransport()->get());
        $this->assertCount(2, $this->em->getRepository(Photo::class)->findAll());

        $this->em->clear();
        $cover = $this->em->getRepository(Photo::class)->findOneBy(['title' => 'Cover']);
        $this->assertNotNull($cover);
        $this->assertSame(0, $cover->getSortOrder());
        $this->assertSame(99, $cover->getViewCount());

        $root = $this->em->getRepository(Album::class)->findOneBySlug('root-album');
        $this->assertNotNull($root);
        $this->assertSame(42, $root->getViewCount());
    }

    public function testReimportRefreshesLegacyViewCounts(): void
    {
        $source = $this->fixtureSource();
        $this->importer->import($source, $this->options());

        $cover = $this->em->getRepository(Photo::class)->findOneBy(['title' => 'Cover']);
        $root = $this->em->getRepository(Album::class)->findOneBySlug('root-album');
        $this->assertNotNull($cover);
        $this->assertNotNull($root);
        $cover->setViewCount(1);
        $root->setViewCount(1);
        $root->setPhotosPerPage(12);
        $this->em->flush();

        $this->importer->import($source, $this->options());
        $this->em->clear();

        $cover = $this->em->getRepository(Photo::class)->findOneBy(['title' => 'Cover']);
        $root = $this->em->getRepository(Album::class)->findOneBySlug('root-album');
        $this->assertNotNull($cover);
        $this->assertNotNull($root);
        $this->assertSame(99, $cover->getViewCount());
        $this->assertSame(42, $root->getViewCount());
        $this->assertSame(30, $root->getPhotosPerPage());
    }

    public function testSortOrderFollowsSourceDisplaySequenceNotRawOrdem(): void
    {
        mkdir($this->imgRoot.'/ordered-album', 0775, true);
        $fixture = \dirname(__DIR__, 2).'/fixtures/sample.jpg';
        copy($fixture, $this->imgRoot.'/ordered-album/later.jpg');
        copy($fixture, $this->imgRoot.'/ordered-album/earlier.jpg');

        // Source order is classic display order: later.jpg first even though ordem is higher.
        $source = new ArrayV3GallerySource(
            albums: [
                [
                    'id_album' => 50,
                    'id_pai' => 0,
                    'titulo' => 'Ordered',
                    'descricao' => null,
                    'url' => 'ordered-album',
                    'ativo' => 'S',
                    'ordem' => 1,
                    'data' => null,
                ],
            ],
            photos: [
                [
                    'id_foto' => 501,
                    'id_album' => 50,
                    'titulo' => 'Later',
                    'foto' => 'later.jpg',
                    'ordem' => 90,
                ],
                [
                    'id_foto' => 502,
                    'id_album' => 50,
                    'titulo' => 'Earlier',
                    'foto' => 'earlier.jpg',
                    'ordem' => 10,
                ],
            ],
        );

        $this->importer->import($source, $this->options());

        /** @var AlbumRepository $albums */
        $albums = $this->em->getRepository(Album::class);
        $album = $albums->findOneBySlug('ordered-album');
        $this->assertNotNull($album);

        /** @var PhotoRepository $photos */
        $photos = $this->em->getRepository(Photo::class);
        $ordered = $photos->findByAlbum($album);
        $this->assertSame(['Later', 'Earlier'], array_map(
            static fn (Photo $p): ?string => $p->getTitle(),
            $ordered
        ));
        $this->assertSame([0, 1], array_map(
            static fn (Photo $p): int => $p->getSortOrder(),
            $ordered
        ));

        $later = $photos->findOneBy(['title' => 'Later']);
        $this->assertNotNull($later);
        $later->setSortOrder(99);
        $this->em->flush();

        $this->importer->import($source, $this->options());
        $this->em->clear();

        $ordered = $this->em->getRepository(Photo::class)->findByAlbum(
            $this->em->getRepository(Album::class)->findOneBySlug('ordered-album')
        );
        $this->assertSame(['Later', 'Earlier'], array_map(
            static fn (Photo $p): ?string => $p->getTitle(),
            $ordered
        ));
        $this->assertSame([0, 1], array_map(
            static fn (Photo $p): int => $p->getSortOrder(),
            $ordered
        ));
    }

    public function testDryRunDoesNotPersist(): void
    {
        $stats = $this->importer->import($this->fixtureSource(), $this->options(dryRun: true));

        $this->assertSame(4, $stats->albumsCreated);
        $this->assertSame(2, $stats->photosCreated);
        $this->assertSame(1, $stats->photosMissingFile);
        $this->assertSame([], $this->em->getRepository(Album::class)->findAll());
        $this->assertSame([], $this->em->getRepository(Photo::class)->findAll());
        $sqlite = preg_replace('/\.json$/', '.sqlite', $this->mapPath);
        // Dry-run may create an empty sqlite schema file; it must not record mappings.
        if (\is_string($sqlite) && is_file($sqlite)) {
            $pdo = new \PDO('sqlite:'.$sqlite);
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM photos')->fetchColumn());
        }
    }

    public function testResolvesGrPrefixedFileFirst(): void
    {
        $resolved = $this->importer->resolveSourceFile($this->imgRoot, 'root-album', 'cover.jpg');
        $this->assertSame($this->imgRoot.'/root-album/gr_cover.jpg', $resolved);

        $missing = $this->importer->resolveSourceFile($this->imgRoot, 'child-album', 'photo-b.jpg');
        $this->assertNull($missing);
    }

    private function fixtureSource(): ArrayV3GallerySource
    {
        return new ArrayV3GallerySource(
            albums: [
                [
                    'id_album' => 1,
                    'id_pai' => 0,
                    'titulo' => 'Root',
                    'descricao' => 'Root album',
                    'url' => 'root-album',
                    'ativo' => 'S',
                    'ordem' => 1,
                    'data' => '2012-01-15',
                    'visit' => 42,
                    'regs' => 30,
                ],
                [
                    'id_album' => 2,
                    'id_pai' => 1,
                    'titulo' => 'Child',
                    'descricao' => null,
                    'url' => 'child-album',
                    'ativo' => 'N',
                    'ordem' => 2,
                    'data' => null,
                    'visit' => 0,
                    'regs' => 0,
                ],
                [
                    'id_album' => 3,
                    'id_pai' => 0,
                    'titulo' => 'Trip',
                    'descricao' => "Férias\n01/06/2024 - 05/06/2024",
                    'url' => 'trip-album',
                    'ativo' => 'S',
                    'ordem' => 3,
                    'data' => '2010-01-01',
                    'visit' => 1,
                    'regs' => 15,
                ],
                [
                    'id_album' => 4,
                    'id_pai' => 0,
                    'titulo' => 'Party',
                    'descricao' => '15/08/2019',
                    'url' => 'party-album',
                    'ativo' => 'S',
                    'ordem' => 4,
                    'data' => null,
                    'visit' => 2,
                ],
            ],
            photos: [
                [
                    'id_foto' => 10,
                    'id_album' => 1,
                    'titulo' => 'Cover',
                    'foto' => 'cover.jpg',
                    'ordem' => 1,
                    'visit' => 99,
                ],
                [
                    'id_foto' => 11,
                    'id_album' => 2,
                    'titulo' => null,
                    'foto' => 'photo-a.jpg',
                    'ordem' => 1,
                    'visit' => 5,
                ],
                [
                    'id_foto' => 12,
                    'id_album' => 2,
                    'titulo' => 'Missing',
                    'foto' => 'photo-b.jpg',
                    'ordem' => 2,
                    'visit' => 8,
                ],
            ],
            destaques: [
                ['id_album' => 1, 'foto' => 'cover.jpg', 'url' => 'root-album'],
            ],
        );
    }

    private function options(bool $dryRun = false): V3ImportOptions
    {
        return new V3ImportOptions(
            imgRoot: $this->imgRoot,
            mapPath: $this->mapPath,
            dryRun: $dryRun,
        );
    }

    private function convertTransport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.convert');
    }

    private function clearGallery(): void
    {
        foreach ($this->em->getRepository(Photo::class)->findAll() as $photo) {
            $this->em->remove($photo);
        }
        foreach ($this->em->getRepository(Album::class)->findAll() as $album) {
            $album->setCoverPhoto(null);
            $this->em->remove($album);
        }
        $this->em->flush();
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $file->isDir() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
