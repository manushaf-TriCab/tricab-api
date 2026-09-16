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
use TriCab\Core\Expense\Application\Service\ListExpensePeriodsService;

final class ListExpensePeriodsAction
{
    public function __construct(
        private readonly ListExpensePeriodsService $service,
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
            $country = JsonValue::stringOrNull($params, 'country');

            return $this->responder->success([
                'items' => $this->service->execute(
                    ExpenseActorFactory::fromRequest($request),
                    $country !== null && trim($country) !== '' ? $country : null,
                ),
            ]);
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
