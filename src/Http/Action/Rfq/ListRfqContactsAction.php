<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Rfq\Application\DTO\RfqContactOption;
use TriCab\Core\Rfq\Application\Service\RfqLookupService;

/**
 * GET /api/v1/rfqs/lookups/contacts?customerId=<4js customer id> — `{items}`: up to 50
 * contacts. By id, as in the source: 4js customer codes are not unique.
 */
final class ListRfqContactsAction
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
            static fn (RfqContactOption $option): array => $option->toArray(),
            $this->lookups->contacts(self::customerId(self::queryParam($request, 'customerId'))),
        )]);
    }

    /** Anything but a positive whole number names no customer, and finds no contacts. */
    private static function customerId(string $value): int
    {
        return preg_match('/^[1-9][0-9]{0,9}$/', trim($value)) === 1 ? (int) trim($value) : 0;
    }
}
