<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

final class PublicSiteUrlBuilder
{
    public function __construct(
        private readonly string $siteUrl,
    ) {
    }

    public function page(string $path, ?Request $request = null): string
    {
        return $this->baseUrl($request).'/'.ltrim($path, '/');
    }

    public function media(?string $relativePath, ?Request $request = null): ?string
    {
        if (null === $relativePath || '' === $relativePath) {
            return null;
        }
        if (str_starts_with($relativePath, 'http://') || str_starts_with($relativePath, 'https://')) {
            return $relativePath;
        }

        return $this->page(ltrim($relativePath, '/'), $request);
    }

    private function baseUrl(?Request $request): string
    {
        if (null !== $request) {
            $fromForwarded = $this->baseUrlFromForwardedHeaders($request);
            if (null !== $fromForwarded) {
                return $fromForwarded;
            }

            $host = $request->getHttpHost();
            if ('' !== $host && !$this->isInternalHost($host)) {
                return $request->getSchemeAndHttpHost();
            }
        }

        return rtrim($this->siteUrl, '/');
    }

    private function baseUrlFromForwardedHeaders(Request $request): ?string
    {
        $host = $this->firstForwardedValue($request->headers->get('X-Forwarded-Host'));
        if (null === $host || '' === $host || $this->isInternalHost($host)) {
            return null;
        }

        $proto = $this->firstForwardedValue($request->headers->get('X-Forwarded-Proto')) ?? 'https';

        return $proto.'://'.$host;
    }

    private function firstForwardedValue(?string $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return trim(explode(',', $value)[0]);
    }

    private function isInternalHost(string $host): bool
    {
        $hostname = strtolower(explode(':', $host)[0]);

        return \in_array($hostname, ['api', 'localhost', '127.0.0.1'], true);
    }
}
