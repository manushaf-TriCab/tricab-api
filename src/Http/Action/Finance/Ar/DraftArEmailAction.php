<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ar;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ar\Domain\ArFailure;
use TriCab\Core\Finance\Ar\Service\ArDraftReplyService;

/**
 * POST /api/v1/finance/ar/records/{id}/email-draft (source route 19): an AI-drafted reply from the person's
 * instruction. Gated on `FINANCE.AR.RECORDS.WRITE`. The browser sends the instruction and the draft so far, never a prompt.
 */
final class DraftArEmailAction
{
    use ArActionSupport;

    public function __construct(
        private readonly ArDraftReplyService $service,
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
            return $this->ok($this->responder, $this->service->draft((string) ($args['id'] ?? ''), $this->bodyOf($request)));
        } catch (ArFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
