<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Rfq\Application\DTO\RfqPayTerm;
use TriCab\Core\Rfq\Application\Service\RfqLookupService;

/**
 * GET /api/v1/rfqs/lookups/pay-terms — `{terms, defaultCode}`: every 4js pay
 * term, and the one the dropdown starts on (null if 4js does not list it).
 */
final class ListRfqPayTermsAction
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

        return $this->answer($this->responder, $request, function (): array {
            $answer = $this->lookups->payTerms();

            return [
                'terms' => array_map(static fn (RfqPayTerm $term): array => $term->toArray(), $answer['terms']),
                'defaultCode' => $answer['defaultCode'],
            ];
        });
    }
}
