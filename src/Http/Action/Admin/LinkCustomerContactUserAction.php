<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\CustomerManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\LinkCustomerContactUserRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Customer\Application\DTO\CustomerContactUserLinkDetails;
use TriCab\Core\Customer\Application\Service\LinkCustomerContactToUserService;
use TriCab\Core\Identity\Application\Exception\UserNotFound;
use TriCab\Core\Identity\Contract\UserManagementRepository;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;

final class LinkCustomerContactUserAction
{
    public function __construct(
        private readonly LinkCustomerContactToUserService $linkCustomerContactToUser,
        private readonly UserManagementRepository $users,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly CustomerManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $contactPublicId = RouteParams::require($request, 'contactPublicId');
            $body = JsonRequestBody::requireObject($request);
            $input = LinkCustomerContactUserRequest::fromArray($body);
            $actor = AdminActorFactory::fromRequest($request);

            $userId = $this->users->findUserIdByPublicId(UserPublicId::fromString($input->userPublicId));
            if ($userId === null) {
                throw UserNotFound::forPublicId($input->userPublicId);
            }

            /** @var CustomerContactUserLinkDetails $link */
            $link = $this->transaction->run(fn (): CustomerContactUserLinkDetails => $this->linkCustomerContactToUser->execute(
                actor: $actor,
                contactPublicId: $contactPublicId,
                userId: $userId->toInt(),
                isPrimary: $input->isPrimary,
            ));

            return $this->responder->success(
                CustomerManagementDtoSerializer::customerContactUserLinkDetails($link),
                201,
            );
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
