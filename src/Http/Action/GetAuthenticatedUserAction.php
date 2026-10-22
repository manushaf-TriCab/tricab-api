<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Http\Auth\IdentityResolutionErrorMapper;
use TriCab\Api\Identity\Authentication\ActingAdminAttribute;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Api\Identity\Authentication\EntraJwtValidationDebugLogger;
use TriCab\Api\Identity\Authentication\VerifiedIdentityAttribute;
use TriCab\Core\Customer\Application\Service\ResolveAuthenticatedCustomerContextService;
use TriCab\Core\Identity\Application\DTO\ActAs\ActingAdmin;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use TriCab\Core\Identity\Application\Exception\IdentityAmbiguous;
use TriCab\Core\Identity\Application\Exception\IdentityConflict;
use TriCab\Core\Identity\Application\Exception\UserNotRegistered;
use TriCab\Core\Identity\Application\Handler\ResolveAuthenticatedUserHandler;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;

final class GetAuthenticatedUserAction
{
    public function __construct(
        private readonly ResolveAuthenticatedUserHandler $handler,
        private readonly ResolveAuthenticatedCustomerContextService $resolveCustomerContext,
        private readonly JsonResponder $responder,
        private readonly EntraJwtValidationDebugLogger $debugLogger,
        private readonly IdentityResolutionErrorMapper $identityErrors,
        private readonly Settings $settings,
        private readonly \TriCab\Api\Identity\Authentication\SuccessfulLoginRecorder $loginRecorder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        $attachedUser = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if ($attachedUser instanceof AuthenticatedUser) {
            $actingAdmin = $request->getAttribute(ActingAdminAttribute::KEY);

            return $this->successfulResponse(
                $attachedUser,
                $actingAdmin instanceof ActingAdmin ? $actingAdmin : null,
            );
        }

        $verifiedIdentity = $request->getAttribute(VerifiedIdentityAttribute::KEY);

        if (!$verifiedIdentity instanceof VerifiedIdentity) {
            $this->debugLogger->log([
                'path' => $request->getUri()->getPath(),
                'authorization_header_present' => trim($request->getHeaderLine('Authorization')) !== '',
                'bearer_token_detected' => preg_match('/^Bearer\s+\S+/i', trim($request->getHeaderLine('Authorization'))) === 1,
                'token_parsed' => false,
                'jwt_validation_stage_reached' => false,
                'validation_failure_reason' => 'no VerifiedIdentity attached before /api/v1/me',
                'http_response_code' => 401,
            ]);

            return $this->responder->error(
                'UNAUTHENTICATED',
                'Authentication is required.',
                401,
            );
        }

        try {
            $user = $this->handler->handle($verifiedIdentity);
        } catch (UserNotRegistered|IdentityConflict|IdentityAmbiguous|AuthenticationNotAllowed $exception) {
            return $this->identityErrors->map($exception);
        }

        return $this->successfulResponse($user);
    }

    private function successfulResponse(AuthenticatedUser $user, ?ActingAdmin $actingAdmin = null): ResponseInterface
    {
        $response = $this->responder->success($this->serialize($user, $actingAdmin));

        // A switched request is the administrator working, not the target signing in.
        if ($actingAdmin === null) {
            $this->loginRecorder->record($user);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(AuthenticatedUser $user, ?ActingAdmin $actingAdmin): array
    {
        $customerContext = $this->resolveCustomerContext->execute(
            $user->publicId,
            $user->userId,
            $user->roles,
        );

        return [
            'userId' => $user->userId,
            'publicId' => $user->publicId,
            'emailAddress' => $user->emailAddress,
            'displayName' => $user->displayName,
            'status' => $user->status,
            'roles' => $user->roles,
            'permissions' => $user->permissions,
            // Modules merged but switched off. A permission says who may use a
            // module; this says whether it exists yet. The web hides a module that
            // is off from everyone, administrators included.
            'modules' => [
                'customerContacts' => $this->settings->customerContactModuleEnabled
                    && in_array(\TriCab\Core\CustomerContactReport\Module::PERMISSION_ACCESS, $user->permissions, true),
                'finance' => $this->settings->financeModuleEnabled
                    && in_array(\TriCab\Core\Finance\Module::PERMISSION_ACCESS, $user->permissions, true),
                'inbox' => $this->settings->mailModuleEnabled
                    && in_array(\TriCab\Core\Mail\Module::PERMISSION_ACCESS, $user->permissions, true),
            ],
            'customerContext' => $customerContext === null ? null : [
                'userPublicId' => $customerContext->userPublicId,
                'customerPublicIds' => $customerContext->customerPublicIds,
                'contactPublicIds' => $customerContext->contactPublicIds,
                'isCustomerAdmin' => $customerContext->isCustomerAdmin,
                'marketCodes' => $customerContext->marketCodes,
            ],
            // Set only while an administrator is acting as this user.
            'actingAs' => $actingAdmin === null ? null : [
                'sessionId' => $actingAdmin->sessionId,
                'target' => [
                    'name' => $user->displayName,
                    'email' => $user->emailAddress,
                ],
                'admin' => [
                    'name' => $actingAdmin->admin->displayName,
                ],
                'expiresAt' => $actingAdmin->expiresAt
                    ->setTimezone(new \DateTimeZone('Australia/Melbourne'))
                    ->format(DATE_ATOM),
            ],
        ];
    }
}
