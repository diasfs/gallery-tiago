<?php

namespace App\Controller\Api\Admin;

use App\Entity\AdminUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/admin')]
class AuthController
{
    /**
     * Reached only once the "admin" firewall's json_login authenticator has
     * already validated the credentials and started the session; invalid
     * credentials never make it here (the authenticator returns 401 first).
     */
    #[Route('/login', name: 'admin_login', methods: ['POST'])]
    public function login(#[CurrentUser] AdminUser $admin): JsonResponse
    {
        return $this->userResponse($admin);
    }

    #[Route('/me', name: 'admin_me', methods: ['GET'])]
    public function me(#[CurrentUser] AdminUser $admin): JsonResponse
    {
        return $this->userResponse($admin);
    }

    private function userResponse(AdminUser $admin): JsonResponse
    {
        return new JsonResponse([
            'id' => (string) $admin->getId(),
            'email' => $admin->getEmail(),
            'roles' => $admin->getRoles(),
        ]);
    }
}
