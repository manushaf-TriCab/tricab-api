<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\CustomerManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Customer\Application\Service\UnlinkCustomerContactFromUserService;
use TriCab\Core\Identity\Application\Exception\UserNotFound;
use TriCab\Core\Identity\Contract\UserManagementRepository;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;

final class UnlinkCustomerContactUserAction
{
    public function __construct(
        private readonly UnlinkCustomerContactFromUserService $unlinkCustomerContactFromUser,
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
            $userPublicId = RouteParams::require($request, 'userPublicId');
            $actor = AdminActorFactory::fromRequest($request);

            $userId = $this->users->findUserIdByPublicId(UserPublicId::fromString($userPublicId));
            if ($userId === null) {
                throw UserNotFound::forPublicId($userPublicId);
            }

            $this->transaction->run(function () use ($actor, $contactPublicId, $userId): void {
                $this->unlinkCustomerContactFromUser->execute(
                    actor: $actor,
                    contactPublicId: $contactPublicId,
                    userId: $userId->toInt(),
                );
            });

            return $this->responder->success([
                'contactPublicId' => $contactPublicId,
                'userPublicId' => $userPublicId,
                'unlinked' => true,
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
