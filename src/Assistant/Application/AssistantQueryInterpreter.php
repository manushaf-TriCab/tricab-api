<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

interface AssistantQueryInterpreter
{
    public function interpret(
        string $message,
        ?string $explicitSearchQuery = null,
        ?string $regionCode = null,
    ): AssistantSearchPlan;
}
