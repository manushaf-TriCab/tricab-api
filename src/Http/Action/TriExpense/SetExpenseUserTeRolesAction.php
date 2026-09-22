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
use TriCab\Core\Expense\Application\Service\SetExpenseUserTeRolesService;
use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;

final class SetExpenseUserTeRolesAction
{
    public function __construct(
        private readonly SetExpenseUserTeRolesService $service,
        private readonly JsonResponder $responder,
        private readonly ExpenseErrorMapper $errors,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = [],
    ): ResponseInterface {
        unset($response);
        try {
            $publicId = trim((string) ($args['publicId'] ?? ''));
            if ($publicId === '') {
                throw InvalidExpenseValue::for('publicId', $publicId);
            }
            $body = JsonRequestBody::requireObject($request);
            $desired = [
                'access' => ($body['access'] ?? false) === true,
                'approve' => ($body['approve'] ?? false) === true,
                'finance' => ($body['finance'] ?? false) === true,
                'admin' => ($body['admin'] ?? false) === true,
                'audit' => ($body['audit'] ?? false) === true,
                'fullTeAdministrator' => ($body['fullTeAdministrator'] ?? false) === true,
            ];

            return $this->responder->success(
                $this->service->execute(
                    ExpenseActorFactory::fromRequest($request),
                    $publicId,
                    $desired,
                ),
            );
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
