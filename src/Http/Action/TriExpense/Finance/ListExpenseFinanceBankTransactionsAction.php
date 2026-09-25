<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense\Finance;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Support\JsonValue;
use TriCab\Core\Expense\Application\Service\ListExpenseFinanceBankTransactionsService;

final class ListExpenseFinanceBankTransactionsAction
{
    public function __construct(
        private readonly ListExpenseFinanceBankTransactionsService $service,
        private readonly JsonResponder $responder,
        private readonly ExpenseErrorMapper $errors,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response, $args);
        try {
            $params = QueryParams::normalize($request->getQueryParams());
            $needsReceiptOnly = JsonValue::stringOrNull($params, 'needsReceiptOnly');

            return $this->responder->success(
                $this->service->execute(
                    ExpenseActorFactory::fromRequest($request),
                    JsonValue::stringOrNull($params, 'periodPublicId'),
                    JsonValue::stringOrNull($params, 'country'),
                    JsonValue::stringOrNull($params, 'dateFrom'),
                    JsonValue::stringOrNull($params, 'dateTo'),
                    JsonValue::stringOrNull($params, 'currency'),
                    JsonValue::stringOrNull($params, 'matchStatus'),
                    JsonValue::stringOrNull($params, 'resolution'),
                    JsonValue::stringOrNull($params, 'cardholderEmail'),
                    JsonValue::stringOrNull($params, 'search'),
                    $needsReceiptOnly === null ? null : in_array(strtolower($needsReceiptOnly), ['1', 'true', 'yes'], true),
                    JsonValue::intOrNull($params, 'page') ?? 1,
                    JsonValue::intOrNull($params, 'pageSize') ?? 50,
                ),
            );
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
