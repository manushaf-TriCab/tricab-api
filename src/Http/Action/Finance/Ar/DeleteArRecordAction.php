<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ar;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ar\Domain\ArFailure;
use TriCab\Core\Finance\Ar\Service\ArRecordService;

/** DELETE /api/v1/finance/ar/records/{id} (source route 17): into the Bin; `?permanent=1` only from the Bin. Gated on `FINANCE.AR.RECORDS.WRITE`. */
final class DeleteArRecordAction
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
        $actor = $this->actorOf($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            return $this->ok($this->responder, $this->service->delete($actor, (string) ($args['id'] ?? ''), $this->flag($request, 'permanent')));
        } catch (ArFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
