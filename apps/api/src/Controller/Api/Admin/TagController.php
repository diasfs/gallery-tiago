<?php

namespace App\Controller\Api\Admin;

use App\Entity\Tag;
use App\Enum\TagListSort;
use App\Http\Pagination;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/admin/tags')]
class TagController
{
    private readonly AsciiSlugger $slugger;

    public function __construct(
        private readonly TagRepository $tags,
        private readonly EntityManagerInterface $em,
    ) {
        $this->slugger = new AsciiSlugger();
    }

    #[Route('', name: 'admin_tags_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $q = $request->query->get('q');
        $sort = $this->parseSort($request);
        $page = Pagination::page($request);
        $perPage = Pagination::perPage($request, 50, 100);
        $result = $this->tags->searchPaginatedWithPhotoCount(
            \is_string($q) ? $q : null,
            $page,
            $perPage,
            $sort,
        );
        $data = array_map(
            fn (array $row): array => $this->normalize($row['tag'], $row['photoCount']),
            $result['items'],
        );

        return new JsonResponse([
            'data' => $data,
            'meta' => Pagination::meta($page, $perPage, $result['total']),
        ]);
    }

    #[Route('', name: 'admin_tags_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = $this->decode($request);

        $name = $payload['name'] ?? null;
        if (!\is_string($name) || '' === $name) {
            throw new BadRequestHttpException('name is required.');
        }

        $slug = $this->slugger->slug($name)->lower()->toString();
        if (null !== $this->tags->findOneBy(['slug' => $slug])) {
            throw new ConflictHttpException('A tag with this name already exists.');
        }

        $tag = new Tag($name, $slug);
        $this->em->persist($tag);
        $this->em->flush();

        return new JsonResponse(['data' => $this->normalize($tag, 0)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'admin_tags_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $tag = $this->findOrFail($id);
        $payload = $this->decode($request);

        if (!\array_key_exists('name', $payload)) {
            throw new BadRequestHttpException('name is required.');
        }

        $name = $payload['name'];
        if (!\is_string($name) || '' === trim($name)) {
            throw new BadRequestHttpException('name must be a non-empty string.');
        }

        // Translate / rename display name only — slug stays stable for the
        // auto-tag worker get-or-create and public URLs.
        $tag->setName(trim($name));
        $this->em->flush();

        return new JsonResponse(['data' => $this->normalize($tag, $tag->getPhotos()->count())]);
    }

    #[Route('/{id}', name: 'admin_tags_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $tag = $this->findOrFail($id);

        foreach ($tag->getPhotos()->toArray() as $photo) {
            $photo->removeTag($tag);
        }

        $this->em->remove($tag);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function findOrFail(string $id): Tag
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Tag not found.');
        }

        $tag = $this->tags->find($uuid);
        if (null === $tag) {
            throw new NotFoundHttpException('Tag not found.');
        }

        return $tag;
    }

    /** @return array<string, mixed> */
    private function decode(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\JsonException) {
            throw new BadRequestHttpException('Invalid JSON body.');
        }
    }

    private function parseSort(Request $request): TagListSort
    {
        $value = $request->query->getString('sort', TagListSort::Recent->value);
        $sort = TagListSort::tryFrom($value);
        if (null === $sort) {
            throw new BadRequestHttpException('sort must be name, slug, or recent.');
        }

        return $sort;
    }

    /** @return array<string, mixed> */
    private function normalize(Tag $tag, int $photoCount): array
    {
        return [
            'id' => (string) $tag->getId(),
            'name' => $tag->getName(),
            'slug' => $tag->getSlug(),
            'photoCount' => $photoCount,
        ];
    }
}
