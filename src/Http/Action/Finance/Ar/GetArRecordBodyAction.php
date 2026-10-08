<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ar;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ar\Domain\ArFailure;
use TriCab\Core\Finance\Ar\Service\ArOriginalMessageService;

/**
 * GET /api/v1/finance/ar/records/{id}/body (source route 8): the original message for the app's inert viewer.
 * Gated on `FINANCE.AR.RECORDS.READ`; the mailbox read is further gated by the app's read grant and the person's scope.
 * The content is untrusted and returned unmodified (decision D47); it is not cached.
 */
final class GetArRecordBodyAction
{
    use ArActionSupport;

    public function __construct(
        private readonly ArOriginalMessageService $service,
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
            $data = $this->service->original($actor, (string) ($args['id'] ?? ''));
        } catch (ArFailure $failure) {
            return $this->failure($this->responder, $failure);
        }

        return $this->ok($this->responder, $data)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
