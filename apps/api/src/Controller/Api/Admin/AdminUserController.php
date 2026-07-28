<?php

namespace App\Controller\Api\Admin;

use App\Entity\AdminUser;
use App\Repository\AdminUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/admin/users')]
class AdminUserController
{
    public function __construct(
        private readonly AdminUserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('', name: 'admin_users_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $users = $this->users->findBy([], ['email' => 'ASC']);

        return new JsonResponse(['data' => array_map($this->normalize(...), $users)]);
    }

    #[Route('', name: 'admin_users_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = $this->decode($request);
        $email = $this->requireEmail($payload['email'] ?? null);
        $password = $this->requirePassword($payload['password'] ?? null);

        if (null !== $this->users->findOneBy(['email' => $email])) {
            throw new ConflictHttpException('An admin with this username already exists.');
        }

        $user = new AdminUser($email, 'temp');
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        return new JsonResponse(['data' => $this->normalize($user)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'admin_users_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $user = $this->findOrFail($id);
        $payload = $this->decode($request);

        if (\array_key_exists('email', $payload)) {
            $email = $this->requireEmail($payload['email']);
            $existing = $this->users->findOneBy(['email' => $email]);
            if (null !== $existing && !$existing->getId()->equals($user->getId())) {
                throw new ConflictHttpException('An admin with this username already exists.');
            }
            $user->setEmail($email);
        }

        if (\array_key_exists('password', $payload)) {
            $password = $payload['password'];
            if (null === $password || '' === $password) {
                // Explicit empty means "leave unchanged".
            } elseif (\is_string($password)) {
                $user->setPassword($this->passwordHasher->hashPassword($user, $this->requirePassword($password)));
            } else {
                throw new BadRequestHttpException('password must be a string.');
            }
        }

        $this->em->flush();

        return new JsonResponse(['data' => $this->normalize($user)]);
    }

    #[Route('/{id}', name: 'admin_users_delete', methods: ['DELETE'])]
    public function delete(string $id, #[CurrentUser] AdminUser $current): Response
    {
        $user = $this->findOrFail($id);

        if ($user->getId()->equals($current->getId())) {
            throw new BadRequestHttpException('You cannot delete your own account.');
        }

        if ($this->users->count([]) <= 1) {
            throw new BadRequestHttpException('Cannot delete the last admin user.');
        }

        $this->em->remove($user);
        $this->em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function findOrFail(string $id): AdminUser
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Admin user not found.');
        }

        $user = $this->users->find($uuid);
        if (null === $user) {
            throw new NotFoundHttpException('Admin user not found.');
        }

        return $user;
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

    private function requireEmail(mixed $email): string
    {
        if (!\is_string($email) || '' === trim($email)) {
            throw new BadRequestHttpException('email is required.');
        }
        $email = strtolower(trim($email));
        if (\strlen($email) > 255) {
            throw new BadRequestHttpException('email must be at most 255 characters.');
        }

        return $email;
    }

    private function requirePassword(mixed $password): string
    {
        if (!\is_string($password) || '' === $password) {
            throw new BadRequestHttpException('password is required.');
        }
        if (\strlen($password) < 6) {
            throw new BadRequestHttpException('password must be at least 6 characters.');
        }

        return $password;
    }

    /** @return array{id: string, email: string, roles: list<string>} */
    private function normalize(AdminUser $user): array
    {
        return [
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
        ];
    }
}
