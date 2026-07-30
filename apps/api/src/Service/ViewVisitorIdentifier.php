<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class ViewVisitorIdentifier
{
    public const COOKIE_NAME = 'gallery_visitor';

    public function resolve(Request $request): string
    {
        $visitorId = $request->cookies->get(self::COOKIE_NAME);

        return \is_string($visitorId) && Uuid::isValid($visitorId)
            ? $visitorId
            : Uuid::v4()->toRfc4122();
    }

    public function attachCookie(Request $request, Response $response, string $visitorId): void
    {
        if ($request->cookies->get(self::COOKIE_NAME) === $visitorId) {
            return;
        }

        $response->headers->setCookie(
            Cookie::create(self::COOKIE_NAME)
                ->withValue($visitorId)
                ->withExpires(new \DateTimeImmutable('+1 year'))
                ->withPath('/')
                ->withSecure($request->isSecure())
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_LAX),
        );
    }
}
