<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ar;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ar\Domain\ArFailure;
use TriCab\Core\Finance\Ar\Service\ArMailService;

/**
 * POST /api/v1/finance/ar/records/{id}/send-response (source route 22): sends the reply from the composer, threaded
 * when the original can be used. The answer is `{success, record_id, threaded, message}`, as the source's was, inside
 * the platform's envelope.
 */
final class SendArResponseAction
{
    use ArActionSupport;

    public function __construct(
        private readonly ArMailService $service,
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
            return $this->ok($this->responder, $this->service->send($actor, (string) ($args['id'] ?? ''), $this->bodyOf($request)));
        } catch (ArFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
