<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PublicProduct;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Action\Admin\ProductManagementDtoSerializer;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\GuestAuthRequestMetadata;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Api\Identity\Authentication\VerifiedIdentityAttribute;
use TriCab\Api\Product\Region\RegionPreferenceResolver;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use TriCab\Core\Identity\Application\Handler\ResolveAuthenticatedUserHandler;
use TriCab\Core\Product\Application\Command\GetRestrictedDocumentDownloadCommand;
use TriCab\Core\Product\Application\Service\GetPublicProductService;
use TriCab\Core\Product\Application\Service\GetRestrictedDocumentDownloadService;

final class DownloadProductDocumentAction
{
    public function __construct(
        private readonly GetPublicProductService $getPublicProduct,
        private readonly GetRestrictedDocumentDownloadService $download,
        private readonly ResolveAuthenticatedUserHandler $resolveUser,
        private readonly Settings $settings,
        private readonly RegionPreferenceResolver $regions,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $slug = RouteParams::require($request, 'slug');
            $documentPublicId = RouteParams::require($request, 'documentPublicId');
            $detail = $this->getPublicProduct->bySlug($slug, $this->regions->resolveCode($request));

            $actor = new ActorContext(userId: 0, publicId: '', emailAddress: '');
            $hasManage = false;
            $verified = $request->getAttribute(VerifiedIdentityAttribute::KEY);
            $attached = $request->getAttribute(AuthenticatedUserAttribute::KEY);
            if ($attached instanceof AuthenticatedUser || $verified instanceof VerifiedIdentity) {
                try {
                    // A switched request carries the target user; otherwise resolve as before.
                    $user = $attached instanceof AuthenticatedUser
                        ? $attached
                        : $this->resolveUser->handle($verified);
                    $actor = new ActorContext(
                        userId: $user->userId,
                        publicId: $user->publicId,
                        emailAddress: $user->emailAddress,
                        correlationId: null,
                        ipAddress: GuestAuthRequestMetadata::clientIp($request),
                        userAgent: GuestAuthRequestMetadata::userAgent($request),
                    );
                    $hasManage = in_array('PRODUCT.DOCUMENTS.MANAGE', $user->permissions, true);
                } catch (Throwable) {
                    // anonymous download path for public documents
                }
            }

            if (!$hasManage) {
                $available = false;
                foreach ($detail->documents as $document) {
                    if ($document->publicId === $documentPublicId) {
                        $available = true;
                        break;
                    }
                }
                if (!$available) {
                    throw \TriCab\Core\Product\Application\Exception\ProductDocumentNotFound::forPublicId($documentPublicId);
                }
            }

            $result = $this->download->execute(
                $actor,
                new GetRestrictedDocumentDownloadCommand(
                    productPublicId: $detail->publicId,
                    documentPublicId: $documentPublicId,
                    actorHasDocumentsManagePermission: $hasManage,
                    downloadExpiresInSeconds: $this->settings->blobDownloadSasTtlSeconds,
                ),
            );

            return $this->responder->success(ProductManagementDtoSerializer::documentDownloadResult($result));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
