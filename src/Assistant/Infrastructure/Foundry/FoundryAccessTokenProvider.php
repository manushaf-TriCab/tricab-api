<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure\Foundry;

interface FoundryAccessTokenProvider
{
    public function accessToken(): string;
}
