<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\UserManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\CreateUserRequest;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserDetails;
use TriCab\Core\Identity\Application\Service\UserManagement\CreateUserService;

final class CreateUserAction
{
    public function __construct(
        private readonly CreateUserService $createUser,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly UserManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $body = JsonRequestBody::requireObject($request);
            $input = CreateUserRequest::fromArray($body);
            $actor = AdminActorFactory::fromRequest($request);

            /** @var UserDetails $created */
            $created = $this->transaction->run(fn (): UserDetails => $this->createUser->execute(
                actor: $actor,
                emailAddress: $input->emailAddress,
                displayName: $input->displayName,
                status: $input->status,
                roleCodes: $input->roles,
            ));

            return $this->responder->success(UserManagementDtoSerializer::userDetails($created), 201);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
