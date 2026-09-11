<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Expense\Application\Service\InitiateExpenseAttachmentService;

final class InitiateExpenseAttachmentAction
{
    public function __construct(
        private readonly InitiateExpenseAttachmentService $service,
        private readonly Settings $settings,
        private readonly JsonResponder $responder,
        private readonly ExpenseErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        try {
            $body = JsonRequestBody::requireObject($request);
            $ticket = $this->service->execute(
                ExpenseActorFactory::fromRequest($request),
                (string) ($args['id'] ?? ''),
                isset($body['fileName']) ? (string) $body['fileName'] : '',
                isset($body['contentType']) ? (string) $body['contentType'] : '',
                isset($body['byteSize']) ? (int) $body['byteSize'] : (isset($body['declaredBytes']) ? (int) $body['declaredBytes'] : 0),
                $this->settings->blobUploadSasTtlSeconds,
            );

            return $this->responder->success($ticket, 201);
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
