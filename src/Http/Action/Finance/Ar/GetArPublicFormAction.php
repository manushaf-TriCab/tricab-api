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
 * GET /api/v1/finance/ar/public/credit-note-form (source route 27): what the sales rep's form shows. **No sign-in and no
 * permission, by design** (question Q7): the link's token, its expiry, single use and a rate limit stand in for them.
 */
final class GetArPublicFormAction
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
            return $this->ok($this->responder, $this->service->show(GuestAuthRequestMetadata::clientIp($request), $this->queryOf($request)));
        } catch (ArFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
