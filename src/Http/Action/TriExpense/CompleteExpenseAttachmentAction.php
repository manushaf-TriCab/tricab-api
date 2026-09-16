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
use TriCab\Api\Support\JsonValue;
use TriCab\Core\Expense\Application\Service\CompleteExpenseAttachmentService;

final class CompleteExpenseAttachmentAction
{
    public function __construct(
        private readonly CompleteExpenseAttachmentService $service,
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
            $body = JsonRequestBody::requireObject($request);

            return $this->responder->success(TriExpenseDtoSerializer::receipt(
                $this->service->execute(
                    ExpenseActorFactory::fromRequest($request),
                    $args['id'] ?? '',
                    JsonValue::string($body, 'blobPath'),
                    JsonValue::string($body, 'fileName'),
                    JsonValue::string($body, 'expectedRowVersion'),
                ),
            ));
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
