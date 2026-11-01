<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap2;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Service\Ap2ExportService;

/** GET /exports/{id}/csv: the saved CSV, as made. `FINANCE.AP2.EXPORT`. */
final class DownloadAp2ExportCsvAction
{
    use Ap2ActionSupport;

    public function __construct(
        private readonly Ap2ExportService $service,
        private readonly Ap2ActorResolver $actors,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        if ($this->actors->actorOf($request) === null) {
            return $this->unauthenticated($this->responder);
        }
        $id = (string) ($args['id'] ?? '');
        try {
            $csv = $this->service->csv($id);
        } catch (Ap2Failure $failure) {
            return $this->failure($this->responder, $failure);
        }
        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $id . '.csv"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store, private');
    }
}
