<?php

namespace App\Service\V3Import;

use App\Entity\Album;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use App\Message\ConvertMediaMessage;
use App\Repository\AlbumRepository;
use App\Repository\PhotoRepository;
use App\Service\AlbumDateRangeParser;
use App\Service\MediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class V3Importer
{
    private const EM_CLEAR_EVERY = 50;
    private const MAX_MISSING_LOG = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AlbumRepository $albums,
        private readonly PhotoRepository $photos,
        private readonly MediaStorage $storage,
        private readonly MessageBusInterface $bus,
        private readonly V3ImportRuntimeTuner $runtimeTuner,
        private readonly AlbumDateRangeParser $dateRangeParser = new AlbumDateRangeParser(),
    ) {
    }

    public function import(V3GallerySourceInterface $source, V3ImportOptions $options): V3ImportStats
    {
        $this->runtimeTuner->harden();
        $this->disableDevSqlProfiling();

        $stats = new V3ImportStats();
        $map = new V3ImportMap($options->mapPath);

        $allAlbums = $source->fetchAlbums();
        $allAlbums = $this->filterAlbums($allAlbums, $options->albumUrl);
        $ordered = $this->orderParentsFirst($allAlbums);

        if (null !== $options->limitAlbums && $options->limitAlbums > 0) {
            $ordered = \array_slice($ordered, 0, $options->limitAlbums, true);
        }

        /** @var array<int, string> $albumUuids legacy id → uuid */
        $albumUuids = [];
        /** @var array<string, int> $legacyIdBySlug */
        $legacyIdBySlug = [];
        /** @var array<int, Album> $albumEntities kept small; refreshed after EM clear */
        $albumEntities = [];

        foreach ($ordered as $legacyId => $row) {
            $legacyIdBySlug[$row['url']] = $legacyId;
            $result = $this->upsertAlbum($row, $map, $albumEntities, $options->dryRun);
            if ('created' === $result['action']) {
                ++$stats->albumsCreated;
            } elseif ('updated' === $result['action']) {
                ++$stats->albumsUpdated;
            } else {
                ++$stats->albumsSkipped;
            }
            if (null !== $result['album']) {
                $albumEntities[$legacyId] = $result['album'];
                $albumUuids[$legacyId] = (string) $result['album']->getId();
            }
        }

        if (!$options->dryRun) {
            $this->em->flush();
            $map->save(true);
        }

        // Free album list copies we no longer need.
        unset($allAlbums);

        $destaques = array_values(array_filter(
            $source->fetchDestaques(),
            static fn (array $d): bool => isset($ordered[$d['id_album']])
        ));

        /** @var array<string, true> $neededFileKeys cover lookups only */
        $neededFileKeys = [];
        foreach ($destaques as $destaque) {
            $coverAlbumLegacyId = $legacyIdBySlug[$destaque['url']] ?? null;
            if (null === $coverAlbumLegacyId) {
                continue;
            }
            $neededFileKeys[$coverAlbumLegacyId.':'.$destaque['foto']] = true;
        }

        $photosImported = 0;
        $sinceClear = 0;

        foreach ($ordered as $legacyId => $row) {
            if (!$options->dryRun) {
                $album = $this->albumRef($legacyId, $albumUuids, $albumEntities);
                if (null === $album) {
                    continue;
                }
            } else {
                $album = null;
            }

            // Fetch one album at a time — never hold all ~80k v3 photo rows in RAM.
            $fotos = $source->fetchPhotosForAlbum($legacyId);

            foreach ($fotos as $displayIndex => $foto) {
                if (null !== $options->limitPhotos && $photosImported >= $options->limitPhotos) {
                    break 2;
                }

                $mapped = $map->getPhotoUuid($foto['id_foto']);
                if (null !== $mapped) {
                    ++$stats->photosSkipped;
                    if (!$options->dryRun) {
                        try {
                            $existing = $this->em->find(Photo::class, Uuid::fromString($mapped));
                        } catch (\InvalidArgumentException) {
                            $existing = null;
                        }
                        if ($existing instanceof Photo) {
                            $existing->setSortOrder((int) $displayIndex);
                            $existing->setViewCount((int) ($foto['visit'] ?? 0));
                            ++$stats->photosSortUpdated;
                            ++$sinceClear;
                            if ($sinceClear >= self::EM_CLEAR_EVERY) {
                                $this->em->flush();
                                $map->save();
                                $this->em->clear();
                                $albumEntities = [];
                                $album = $this->albumRef($legacyId, $albumUuids, $albumEntities);
                                $sinceClear = 0;
                                gc_collect_cycles();
                            }
                        }
                    }
                    ++$photosImported;

                    continue;
                }

                $sourcePath = $this->resolveSourceFile($options->imgRoot, $row['url'], $foto['foto']);
                if (null === $sourcePath) {
                    ++$stats->photosMissingFile;
                    if (\count($stats->missingFiles) < self::MAX_MISSING_LOG) {
                        $stats->missingFiles[] = \sprintf('%s/%s', $row['url'], $foto['foto']);
                    }
                    ++$photosImported;

                    continue;
                }

                if ($options->dryRun) {
                    ++$stats->photosCreated;
                    if (!$options->skipConvert) {
                        ++$stats->convertDispatched;
                    }
                    ++$photosImported;

                    continue;
                }

                \assert($album instanceof Album);
                $photo = new Photo($album);
                $title = $foto['titulo'] ?? pathinfo($foto['foto'], \PATHINFO_FILENAME);
                if (\is_string($title) && '' !== $title) {
                    $photo->setTitle($title);
                }
                $photo->setSortOrder((int) $displayIndex);
                $photo->setViewCount((int) ($foto['visit'] ?? 0));
                $this->em->persist($photo);
                $this->em->flush();

                $photoId = (string) $photo->getId();
                $relative = $this->storage->storeOriginalFromPath($sourcePath, $photoId);
                $photo->setOriginalPath($relative);
                $this->em->flush();

                // Dispatch before durable map write so a crash still leaves work in the queue.
                if (!$options->skipConvert) {
                    $this->bus->dispatch(new ConvertMediaMessage($photoId));
                    ++$stats->convertDispatched;
                }

                $map->setPhotoUuid($foto['id_foto'], $photoId);
                if (isset($neededFileKeys[$legacyId.':'.$foto['foto']])) {
                    $map->setFileUuid($legacyId, $foto['foto'], $photoId);
                }

                ++$stats->photosCreated;
                ++$photosImported;
                ++$sinceClear;

                if ($sinceClear >= self::EM_CLEAR_EVERY) {
                    $map->save();
                    $this->em->clear();
                    $albumEntities = [];
                    $album = $this->albumRef($legacyId, $albumUuids, $albumEntities);
                    $sinceClear = 0;
                    gc_collect_cycles();
                }
            }

            unset($fotos);
        }

        if (!$options->dryRun) {
            $map->save(true);
            // Refresh album refs after possible clear.
            $albumEntities = [];
            foreach ($albumUuids as $legacyId => $uuid) {
                $ref = $this->albums->find($uuid);
                if ($ref instanceof Album) {
                    $albumEntities[$legacyId] = $ref;
                }
            }
            $stats->coversSet = $this->applyCovers($destaques, $map, $legacyIdBySlug, $albumEntities);
            $this->em->flush();
            $map->save(true);
        } else {
            $stats->coversSet = \count($destaques);
        }

        return $stats;
    }

    /**
     * @param array<int, string> $albumUuids
     * @param array<int, Album> $albumEntities
     */
    private function albumRef(int $legacyId, array $albumUuids, array &$albumEntities): ?Album
    {
        if (isset($albumEntities[$legacyId])) {
            return $albumEntities[$legacyId];
        }
        $uuid = $albumUuids[$legacyId] ?? null;
        if (null === $uuid) {
            return null;
        }
        $album = $this->albums->find($uuid);
        if ($album instanceof Album) {
            $albumEntities[$legacyId] = $album;
        }

        return $album instanceof Album ? $album : null;
    }

    /**
     * @param list<array{
     *     id_album: int,
     *     id_pai: int,
     *     titulo: string,
     *     descricao: ?string,
     *     url: string,
     *     ativo: string,
     *     ordem: int,
     *     data: ?string
     * }> $albums
     *
     * @return list<array{
     *     id_album: int,
     *     id_pai: int,
     *     titulo: string,
     *     descricao: ?string,
     *     url: string,
     *     ativo: string,
     *     ordem: int,
     *     data: ?string
     * }>
     */
    private function filterAlbums(array $albums, ?string $albumUrl): array
    {
        if (null === $albumUrl || '' === $albumUrl) {
            return $albums;
        }

        /** @var array<int, array{id_album: int, id_pai: int, url: string}> $byId */
        $byId = [];
        $targetId = null;
        foreach ($albums as $row) {
            $byId[$row['id_album']] = $row;
            if ($row['url'] === $albumUrl) {
                $targetId = $row['id_album'];
            }
        }
        if (null === $targetId) {
            return [];
        }

        $allowed = [];
        $cursor = $targetId;
        while (isset($byId[$cursor])) {
            $allowed[$cursor] = true;
            $pai = $byId[$cursor]['id_pai'];
            if (0 === $pai || !isset($byId[$pai])) {
                break;
            }
            $cursor = $pai;
        }

        $queue = [$targetId];
        while ($queue) {
            $id = array_shift($queue);
            foreach ($byId as $candidateId => $row) {
                if ($row['id_pai'] === $id && !isset($allowed[$candidateId])) {
                    $allowed[$candidateId] = true;
                    $queue[] = $candidateId;
                }
            }
        }

        return array_values(array_filter(
            $albums,
            static fn (array $row): bool => isset($allowed[$row['id_album']])
        ));
    }

    /**
     * @param list<array{
     *     id_album: int,
     *     id_pai: int,
     *     titulo: string,
     *     descricao: ?string,
     *     url: string,
     *     ativo: string,
     *     ordem: int,
     *     data: ?string
     * }> $albums
     *
     * @return array<int, array{
     *     id_album: int,
     *     id_pai: int,
     *     titulo: string,
     *     descricao: ?string,
     *     url: string,
     *     ativo: string,
     *     ordem: int,
     *     data: ?string
     * }>
     */
    private function orderParentsFirst(array $albums): array
    {
        /** @var array<int, array{
         *     id_album: int,
         *     id_pai: int,
         *     titulo: string,
         *     descricao: ?string,
         *     url: string,
         *     ativo: string,
         *     ordem: int,
         *     data: ?string
         * }> $byId
         */
        $byId = [];
        foreach ($albums as $row) {
            $byId[$row['id_album']] = $row;
        }

        $ordered = [];
        $remaining = $byId;
        while ($remaining) {
            $progress = false;
            foreach ($remaining as $id => $row) {
                $pai = $row['id_pai'];
                if (0 === $pai || isset($ordered[$pai]) || !isset($byId[$pai])) {
                    $ordered[$id] = $row;
                    unset($remaining[$id]);
                    $progress = true;
                }
            }
            if (!$progress) {
                foreach ($remaining as $id => $row) {
                    $ordered[$id] = $row;
                }
                break;
            }
        }

        return $ordered;
    }

    /**
     * @param array{
     *     id_album: int,
     *     id_pai: int,
     *     titulo: string,
     *     descricao: ?string,
     *     url: string,
     *     ativo: string,
     *     ordem: int,
     *     data: ?string
     * } $row
     * @param array<int, Album> $albumEntities
     *
     * @return array{action: string, album: ?Album}
     */
    private function upsertAlbum(array $row, V3ImportMap $map, array $albumEntities, bool $dryRun): array
    {
        $legacyId = $row['id_album'];
        $visibility = 'S' === strtoupper($row['ativo']) ? AlbumVisibility::Public : AlbumVisibility::Private;
        $parent = null;
        if ($row['id_pai'] > 0) {
            $parent = $albumEntities[$row['id_pai']] ?? null;
        }
        [$description, $takenAt, $takenAtEnd] = $this->resolveAlbumDates($row['descricao'], $row['data']);

        $existingUuid = $map->getAlbumUuid($legacyId);
        $album = null;
        if (null !== $existingUuid && Uuid::isValid($existingUuid)) {
            $album = $this->albums->find($existingUuid);
        }
        if (null === $album) {
            $album = $this->albums->findOneBySlug($row['url']);
            if (null !== $album) {
                $map->setAlbumUuid($legacyId, (string) $album->getId());
            }
        }

        if (null === $album) {
            if ($dryRun) {
                return ['action' => 'created', 'album' => null];
            }
            $album = new Album($row['titulo'], $row['url']);
            $album->setDescription($description);
            $album->setVisibility($visibility);
            $album->setSortOrder($row['ordem']);
            $album->setViewCount((int) ($row['visit'] ?? 0));
            $album->setPhotosPerPage($this->photosPerPageFromRow($row));
            $album->setLegacyId($legacyId);
            $album->setParent($parent);
            $album->setTakenAt($takenAt);
            $album->setTakenAtEnd($takenAtEnd);
            $this->applyAlbumCreatedAt($album, $row);
            $this->em->persist($album);
            $this->em->flush();
            $map->setAlbumUuid($legacyId, (string) $album->getId());

            return ['action' => 'created', 'album' => $album];
        }

        if ($dryRun) {
            return ['action' => 'updated', 'album' => $album];
        }

        $album->setTitle($row['titulo']);
        $album->setDescription($description);
        $album->setSlug($row['url']);
        $album->setVisibility($visibility);
        $album->setSortOrder($row['ordem']);
        $album->setViewCount((int) ($row['visit'] ?? 0));
        $album->setPhotosPerPage($this->photosPerPageFromRow($row));
        $album->setLegacyId($legacyId);
        $album->setParent($parent);
        $album->setTakenAt($takenAt);
        $album->setTakenAtEnd($takenAtEnd);
        $this->applyAlbumCreatedAt($album, $row);
        $album->touch();
        $map->setAlbumUuid($legacyId, (string) $album->getId());

        return ['action' => 'updated', 'album' => $album];
    }

    /**
     * @param array{regs?: int} $row
     */
    private function photosPerPageFromRow(array $row): int
    {
        $regs = (int) ($row['regs'] ?? 0);

        return $regs >= 1 ? $regs : 48;
    }

    /**
     * @return array{0: ?string, 1: ?\DateTimeImmutable, 2: ?\DateTimeImmutable}
     */
    private function resolveAlbumDates(?string $description, ?string $data): array
    {
        $parsed = $this->dateRangeParser->parse($description);
        if (null !== $parsed) {
            return [$parsed['descriptionWithoutRange'], $parsed['start'], $parsed['end']];
        }

        return [$description, $this->parseTakenAt($data), null];
    }

    /**
     * @param list<array{id_album: int, foto: string, url: string}> $destaques
     * @param array<string, int> $legacyIdBySlug
     * @param array<int, Album> $albumEntities
     */
    private function applyCovers(array $destaques, V3ImportMap $map, array $legacyIdBySlug, array $albumEntities): int
    {
        $set = 0;
        foreach ($destaques as $destaque) {
            $targetAlbum = $albumEntities[$destaque['id_album']] ?? null;
            if (null === $targetAlbum) {
                $mappedAlbumUuid = $map->getAlbumUuid($destaque['id_album']);
                if (null !== $mappedAlbumUuid && Uuid::isValid($mappedAlbumUuid)) {
                    $targetAlbum = $this->albums->find($mappedAlbumUuid);
                }
            }
            if (null === $targetAlbum) {
                continue;
            }

            $coverAlbumLegacyId = $legacyIdBySlug[$destaque['url']] ?? null;
            if (null === $coverAlbumLegacyId) {
                continue;
            }

            $photoUuid = $map->getFileUuid($coverAlbumLegacyId, $destaque['foto']);
            if (null === $photoUuid || !Uuid::isValid($photoUuid)) {
                continue;
            }

            $photo = $this->photos->find($photoUuid);
            if (!$photo instanceof Photo) {
                continue;
            }

            $targetAlbum->setCoverPhoto($photo);
            $targetAlbum->touch();
            ++$set;
        }

        return $set;
    }

    public function resolveSourceFile(string $imgRoot, string $albumUrl, string $filename): ?string
    {
        $root = rtrim($imgRoot, '/');
        $candidates = [
            $root.'/'.$albumUrl.'/gr_'.$filename,
            $root.'/'.$albumUrl.'/'.$filename,
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param array{data_cadastro?: ?string} $row
     */
    private function applyAlbumCreatedAt(Album $album, array $row): void
    {
        $createdAt = $this->parseTakenAt($row['data_cadastro'] ?? null);
        if (null !== $createdAt) {
            $album->setCreatedAt($createdAt);
        }
    }

    private function parseTakenAt(?string $data): ?\DateTimeImmutable
    {
        if (null === $data || '' === $data || '0000-00-00' === $data || str_starts_with($data, '0000-00-00')) {
            return null;
        }

        try {
            return new \DateTimeImmutable($data);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * In APP_ENV=dev, Doctrine keeps a backtrace per SQL query. A full v3 import
     * runs tens of thousands of inserts and will OOM unless profiling is off.
     */
    private function disableDevSqlProfiling(): void
    {
        $config = $this->em->getConnection()->getConfiguration();
        if (!method_exists($config, 'getMiddlewares') || !method_exists($config, 'setMiddlewares')) {
            return;
        }

        $kept = [];
        foreach ($config->getMiddlewares() as $middleware) {
            $class = $middleware::class;
            if (str_contains($class, 'DebugMiddleware') || str_contains($class, 'Logging\\Middleware')) {
                continue;
            }
            $kept[] = $middleware;
        }
        $config->setMiddlewares($kept);
    }
}
