<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Application\Service;

use TriCab\Core\Npr\Domain\NprRequest;

/** A request as one person sees it: the record plus what they may do with it. */
final readonly class NprRequestView
{
    /** @param list<string> $allowedActions */
    public function __construct(
        public NprRequest $request,
        public array $allowedActions,
    ) {
    }
}
