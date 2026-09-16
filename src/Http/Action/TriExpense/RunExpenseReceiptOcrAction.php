<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Expense\Application\Service\RunExpenseReceiptOcrService;

/**
 * POST /api/v1/tri-expense/receipts/{id}/ocr
 *
 * Returns suggestions for the receipt's own attachment. There is no request body
 * and no URL parameter: the document is located from the receipt the caller has
 * already been authorized for, so no caller can point OCR at an address of their
 * choosing.
 */
final class RunExpenseReceiptOcrAction
{
    public function __construct(
        private readonly RunExpenseReceiptOcrService $service,
        private readonly JsonResponder $responder,
        private readonly ExpenseErrorMapper $errors,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        try {
            return $this->responder->success(
                $this->service->execute(
                    ExpenseActorFactory::fromRequest($request),
                    $args['id'] ?? '',
                ),
            );
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
