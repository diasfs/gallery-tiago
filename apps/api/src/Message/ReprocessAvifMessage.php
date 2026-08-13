<?php

namespace App\Message;

final class ReprocessAvifMessage
{
    public function __construct(
        private readonly string $scope,
    ) {
    }

    public function getScope(): string
    {
        return $this->scope;
    }
}
