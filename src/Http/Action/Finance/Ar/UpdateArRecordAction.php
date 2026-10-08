<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ar;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ar\Domain\ArFailure;
use TriCab\Core\Finance\Ar\Service\ArRecordService;

/** PATCH /api/v1/finance/ar/records/{id} (source route 10): only the fields a person edits (decision D48). Gated on `FINANCE.AR.RECORDS.WRITE`. */
final class UpdateArRecordAction
{
    use ArActionSupport;

    public function __construct(
        private readonly ArRecordService $service,
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
            return $this->ok($this->responder, $this->service->update((string) ($args['id'] ?? ''), $this->bodyOf($request)));
        } catch (ArFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
