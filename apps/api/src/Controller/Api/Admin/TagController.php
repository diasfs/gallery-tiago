<?php

namespace App\Controller\Api\Admin;

use App\Entity\Tag;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

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

        return new JsonResponse(['data' => $this->normalize($tag)], Response::HTTP_CREATED);
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

    /** @return array<string, mixed> */
    private function normalize(Tag $tag): array
    {
        return [
            'id' => (string) $tag->getId(),
            'name' => $tag->getName(),
            'slug' => $tag->getSlug(),
        ];
    }
}
