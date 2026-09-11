<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Expense\Application\Service\SubmitExpenseReceiptService;

final class SubmitExpenseReceiptAction
{
    public function __construct(
        private readonly SubmitExpenseReceiptService $service,
        private readonly JsonResponder $responder,
        private readonly ExpenseErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        try {
            $body = JsonRequestBody::requireObject($request);

            return $this->responder->success(TriExpenseDtoSerializer::receipt(
                $this->service->execute(
                    ExpenseActorFactory::fromRequest($request),
                    (string) ($args['id'] ?? ''),
                    isset($body['expectedRowVersion']) ? (string) $body['expectedRowVersion'] : '',
                ),
            ));
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
