<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\QuoteOrder;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\QuoteOrderErrorMapper;
use TriCab\Api\Http\Auth\ResolveQuoteOrderActorService;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\QuoteOrder\Application\Service\GetQuoteService;

final class GetQuoteAction
{
    public function __construct(
        private readonly GetQuoteService $service,
        private readonly ResolveQuoteOrderActorService $actors,
        private readonly JsonResponder $responder,
        private readonly QuoteOrderErrorMapper $errors,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);

        try {
            $actor = $this->actors->fromRequest($request);
            $details = $this->service->execute($actor, (string) ($args['id'] ?? ''));

            return $this->responder->success(QuoteOrderDtoSerializer::quote($details));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
