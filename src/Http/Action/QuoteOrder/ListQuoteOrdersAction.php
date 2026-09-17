<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\QuoteOrder;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\QuoteOrderErrorMapper;
use TriCab\Api\Http\Auth\ResolveQuoteOrderActorService;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Request\QuoteOrder\QuoteOrderListQuery;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\QuoteOrder\Application\Service\SearchQuoteOrdersService;

final class ListQuoteOrdersAction
{
    public function __construct(
        private readonly SearchQuoteOrdersService $service,
        private readonly ResolveQuoteOrderActorService $actors,
        private readonly JsonResponder $responder,
        private readonly QuoteOrderErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $params = QueryParams::normalize($request->getQueryParams());
            $actor = $this->actors->fromRequest($request);
            $criteria = QuoteOrderListQuery::fromQueryParams($params);
            $result = $this->service->execute($actor, $criteria);

            return $this->responder->success(QuoteOrderDtoSerializer::searchResult($result));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
