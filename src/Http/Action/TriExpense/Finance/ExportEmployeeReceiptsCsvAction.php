<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense\Finance;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Core\Expense\Application\Service\ExportEmployeeReceiptsCsvService;

final class ExportEmployeeReceiptsCsvAction
{
    public function __construct(
        private readonly ExportEmployeeReceiptsCsvService $service,
        private readonly ExpenseErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($args);
        try {
            $params = $request->getQueryParams();
            $csv = $this->service->execute(
                ExpenseActorFactory::fromRequest($request),
                (int) ($params['ownerUserId'] ?? 0),
            );

            $response->getBody()->write($csv);

            return $response
                ->withHeader('Content-Type', 'text/csv; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="employee-receipts.csv"')
                ->withHeader('Cache-Control', 'no-store, private');
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
