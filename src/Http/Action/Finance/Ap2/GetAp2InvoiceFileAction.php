<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap2;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Action\Finance\Ap\ApActionSupport;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap2\Service\Ap2InvoiceService;

/** GET /invoices/{id}/file: the source document, 404 when there is none. `FINANCE.AP2.INVOICES.READ`. */
final class GetAp2InvoiceFileAction
{
    use ApActionSupport;
    use Ap2FileResponse;

    public function __construct(
        private readonly Ap2InvoiceService $service,
        private readonly Ap2ActorResolver $actors,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $actor = $this->actors->actorOf($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            $file = $this->service->file((string) ($args['id'] ?? ''), $actor->userId);
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }

        return $this->fileResponse($response, $file);
    }
}
