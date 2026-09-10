<?php

declare(strict_types=1);

namespace TriCab\Core\LogNote\Contract;

interface LogNoteSummariser
{
    public function summarise(string $rawText): string;

    public function providerName(): string;
}
