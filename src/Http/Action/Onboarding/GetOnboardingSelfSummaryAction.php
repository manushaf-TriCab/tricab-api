<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;

/**
 * GET /api/v1/onboarding/me — the signed-in person's own onboarding work.
 *
 * Phase 1 skeleton: the route, its permission gate and its response shape
 * exist; no domain data is stored yet, so `assignments` is empty rather than
 * fabricated. `statuses` is the vocabulary ported from the source
 * `api/_lib/status.ts`, published so the SPA can type against it before the
 * domain lands.
 *
 * There is deliberately no trainee identifier in the path. Whose record this
 * is follows from the authenticated session and nothing else (work order
 * §4.3); adding an id parameter here would be the bug that invariant exists to
 * prevent.
 */
final class GetOnboardingSelfSummaryAction
{
    /** Ported verbatim from the source status machine. Order is meaningful. */
    private const STATUSES = [
        'not_started',
        'in_progress',
        'need_help',
        'submitted',
        'fix_and_resubmit',
        'completed',
    ];

    public function __construct(
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($request, $response);

        return $this->responder->success([
            'assignments' => [],
            'statuses' => self::STATUSES,
        ]);
    }
}
