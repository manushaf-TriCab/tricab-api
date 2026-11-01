<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap2;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Service\Ap2StatementService;

/** POST /statements/{id}/review. `FINANCE.AP2.INVOICES.WRITE`. */
final class ReviewAp2StatementAction
{
    use Ap2ActionSupport;

    public function __construct(
        private readonly Ap2StatementService $service,
        private readonly Ap2ActorResolver $actors,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        $actor = $this->actors->actorOf($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            return $this->ok($this->responder, $this->service->review($actor, (string) ($args['id'] ?? ''), $this->bodyOf($request)), 200);
        } catch (Ap2Failure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
