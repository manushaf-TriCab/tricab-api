<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ar;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ar\Domain\ArFailure;
use TriCab\Core\Finance\Ar\Service\ArRecordService;

/** GET /api/v1/finance/ar/records/{id} (source route 7): the record, live or binned, with its attachments' metadata. Gated on `FINANCE.AR.RECORDS.READ`. */
final class GetArRecordAction
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
        unset($request);
        try {
            return $this->ok($this->responder, $this->service->get((string) ($args['id'] ?? '')));
        } catch (ArFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
