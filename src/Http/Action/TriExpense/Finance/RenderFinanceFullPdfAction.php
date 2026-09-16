<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense\Finance;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Support\JsonValue;
use TriCab\Core\Expense\Application\Service\RenderFinanceFullPdfService;

final class RenderFinanceFullPdfAction
{
    public function __construct(
        private readonly RenderFinanceFullPdfService $service,
        private readonly ExpenseErrorMapper $errors,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($args);
        try {
            $params = QueryParams::normalize($request->getQueryParams());
            $country = JsonValue::stringOrNull($params, 'country');

            $pdf = $this->service->execute(
                ExpenseActorFactory::fromRequest($request),
                $country !== null && trim($country) !== '' ? $country : null,
            );

            $response->getBody()->write($pdf);

            return $response
                ->withHeader('Content-Type', 'application/pdf')
                ->withHeader('Content-Disposition', 'attachment; filename="finance-full.pdf"')
                ->withHeader('Content-Length', (string) strlen($pdf))
                ->withHeader('Cache-Control', 'no-store, private');
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
