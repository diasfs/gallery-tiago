<?php

namespace App\Service;

interface ViewDeduplicatorInterface
{
    public function claim(string $resourceType, string $resourceId, string $visitorId): bool;
}
