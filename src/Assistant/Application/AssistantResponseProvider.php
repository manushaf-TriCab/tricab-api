<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

interface AssistantResponseProvider
{
    public function generate(AssistantGrounding $grounding): AssistantProviderResult;
}
