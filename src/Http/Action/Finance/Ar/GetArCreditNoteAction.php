<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ar;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ar\Domain\ArFailure;
use TriCab\Core\Finance\Ar\Service\ArCreditNoteService;

/** GET /api/v1/finance/ar/credit-notes/{id} (source route 25). */
final class GetArCreditNoteAction
{
    use ArActionSupport;

    public function __construct(
        private readonly ArCreditNoteService $service,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);

        try {
            return $this->ok($this->responder, $this->service->get((string) ($args['id'] ?? '')));
        } catch (ArFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
