<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;

/**
 * GET /api/v1/admin/onboarding/overview — the HR dashboard's backing call.
 *
 * Phase 1 skeleton. Returns empty collections rather than zeroed counts: a
 * count of 0 reads as "nothing to review today", which would be a lie while
 * the module stores nothing at all.
 */
final class GetOnboardingAdminOverviewAction
{
    public function __construct(
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($request, $response);

        return $this->responder->success([
            'queue' => [],
            'people' => [],
        ]);
    }
}
