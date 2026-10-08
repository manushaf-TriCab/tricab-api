<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ar;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Request\GuestAuthRequestMetadata;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ar\Domain\ArFailure;
use TriCab\Core\Finance\Ar\Service\ArPublicFormService;

/**
 * POST /api/v1/finance/ar/public/credit-note-form (source route 28): the sales rep's submit. **No sign-in and no
 * permission, by design** (question Q7); single use.
 */
final class SubmitArPublicFormAction
{
    use ArActionSupport;

    public function __construct(
        private readonly ArPublicFormService $service,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response, $args);
        try {
            return $this->ok($this->responder, $this->service->submit(GuestAuthRequestMetadata::clientIp($request), $this->bodyOf($request)));
        } catch (ArFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
