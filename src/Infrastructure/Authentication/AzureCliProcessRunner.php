<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Authentication;

interface AzureCliProcessRunner
{
    /**
     * @param list<string> $command
     * @return array{0: int, 1: string, 2: string}
     */
    public function run(array $command): array;
}
