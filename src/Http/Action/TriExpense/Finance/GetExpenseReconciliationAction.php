<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense\Finance;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Expense\Application\Service\GetExpenseReconciliationService;

final class GetExpenseReconciliationAction
{
    public function __construct(
        private readonly GetExpenseReconciliationService $service,
        private readonly JsonResponder $responder,
        private readonly ExpenseErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response, $args);
        try {
            $params = $request->getQueryParams();
            $country = isset($params['country']) ? (string) $params['country'] : null;
            $periodPublicId = isset($params['periodPublicId']) ? (string) $params['periodPublicId'] : null;

            return $this->responder->success(
                $this->service->execute(
                    ExpenseActorFactory::fromRequest($request),
                    $country !== null && trim($country) !== '' ? $country : null,
                    $periodPublicId !== null && trim($periodPublicId) !== '' ? $periodPublicId : null,
                ),
            );
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
