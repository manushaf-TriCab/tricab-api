<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApEmailService;

/** POST /api/v1/finance/ap/emails/bulk (source route 39): `classify`, `bin` or `restore` over up to 1,000 email ids; answers `{updated, skipped, emails}`. Gated on `FINANCE.AP.INVOICES.WRITE`. */
final class BulkApEmailsAction
{
    use ApActionSupport;

    public function __construct(
        private readonly ApEmailService $service,
        private readonly ApActorResolver $actors,
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
            return $this->ok($this->responder, $this->service->bulk($actor, $this->bodyOf($request)));
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
