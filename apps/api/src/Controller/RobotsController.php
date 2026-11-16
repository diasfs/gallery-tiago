<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class RobotsController
{
    #[Route('/robots.txt', name: 'robots_txt', methods: ['GET'], priority: 50)]
    public function __invoke(): Response
    {
        return new Response(
            "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /api/\n",
            Response::HTTP_OK,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }
}
