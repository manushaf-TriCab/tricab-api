<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Service\ApSigningAuthorityService;

/** GET /api/v1/finance/ap/signing-authority (source route 57): who holds signing authority, a suggestion list for the approval dialog. Gated on `FINANCE.AP.INVOICES.READ`. */
final class ListApSigningAuthorityAction
{
    use ApActionSupport;

    public function __construct(
        private readonly ApSigningAuthorityService $service,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($request, $response, $args);

        return $this->ok($this->responder, $this->service->list());
    }
}
