<?php

namespace App\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Returns a JSON response after logout instead of the default redirect,
 * since the admin API has no server-rendered pages to redirect to.
 */
#[AsEventListener]
class AdminLogoutSuccessHandler
{
    public function __invoke(LogoutEvent $event): void
    {
        $event->setResponse(new JsonResponse(['success' => true]));
    }
}
