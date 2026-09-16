<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense\Finance;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Expense\Application\Service\ResolveExpenseReceiptFinanceService;

final class ResolveExpenseReceiptFinanceAction
{
    public function __construct(
        private readonly ResolveExpenseReceiptFinanceService $service,
        private readonly JsonResponder $responder,
        private readonly ExpenseErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        try {
            $body = JsonRequestBody::requireObject($request);

            return $this->responder->success(
                $this->service->execute(
                    ExpenseActorFactory::fromRequest($request),
                    (string) ($args['id'] ?? ''),
                    (string) ($body['resolution'] ?? ''),
                    isset($body['note']) ? (string) $body['note'] : null,
                    (string) ($body['expectedRowVersion'] ?? ''),
                ),
            );
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
