<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Domain;

/** What a run used: the model and its token and search counts. */
final readonly class ResearchCost
{
    public function __construct(
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $searchRequests,
    ) {
    }

    public function plus(self $other): self
    {
        return new self(
            $other->model !== '' ? $other->model : $this->model,
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->searchRequests + $other->searchRequests,
        );
    }
}
