<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\UserManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\ChangeUserStatusRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserDetails;
use TriCab\Core\Identity\Application\Service\UserManagement\ChangeUserStatusService;

final class ChangeUserStatusAction
{
    public function __construct(
        private readonly ChangeUserStatusService $changeUserStatus,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly UserManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $publicId = RouteParams::require($request, 'publicId');
            $body = JsonRequestBody::requireObject($request);
            $input = ChangeUserStatusRequest::fromArray($body);
            $actor = AdminActorFactory::fromRequest($request);

            /** @var UserDetails $updated */
            $updated = $this->transaction->run(fn (): UserDetails => $this->changeUserStatus->execute(
                actor: $actor,
                publicId: $publicId,
                status: $input->status,
            ));

            return $this->responder->success(UserManagementDtoSerializer::userDetails($updated));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
