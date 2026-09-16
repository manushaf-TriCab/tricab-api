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
use TriCab\Core\Expense\Application\Service\ExportFinanceReconciliationCsvService;

final class ExportFinanceReconciliationCsvAction
{
    public function __construct(
        private readonly ExportFinanceReconciliationCsvService $service,
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
            $periodPublicId = JsonValue::stringOrNull($params, 'periodPublicId');

            $csv = $this->service->execute(
                ExpenseActorFactory::fromRequest($request),
                $country !== null && trim($country) !== '' ? $country : null,
                $periodPublicId !== null && trim($periodPublicId) !== '' ? $periodPublicId : null,
            );

            $response->getBody()->write($csv);

            return $response
                ->withHeader('Content-Type', 'text/csv; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="finance-reconciliation.csv"')
                ->withHeader('Cache-Control', 'no-store, private');
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
