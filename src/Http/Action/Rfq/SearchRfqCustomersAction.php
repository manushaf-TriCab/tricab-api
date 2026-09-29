<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Rfq\Application\DTO\RfqCustomerOption;
use TriCab\Core\Rfq\Application\Service\RfqLookupService;

/** GET /api/v1/rfqs/lookups/customers?q= — `{items}`: up to 10 customers for the header. */
final class SearchRfqCustomersAction
{
    use RfqLookupAnswers;

    public function __construct(
        private readonly RfqLookupService $lookups,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        return $this->answer($this->responder, $request, fn (): array => ['items' => array_map(
            static fn (RfqCustomerOption $option): array => $option->toArray(),
            $this->lookups->searchCustomers(self::queryParam($request, 'q')),
        )]);
    }
}
