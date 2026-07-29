<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TriCab\Api\Config\Settings;
use TriCab\Api\Identity\Authentication\VerifiedIdentityAttribute;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;

/**
 * Development-only identity injection from X-Dev-Entra-* headers.
 * Never activates outside APP_ENV=development with DEV_AUTH_ENABLED=true.
 */
final class DevelopmentAuthenticationMiddleware implements MiddlewareInterface
{
    public const HEADER_TENANT = 'X-Dev-Entra-Tenant';
    public const HEADER_SUBJECT = 'X-Dev-Entra-Subject';
    public const HEADER_EMAIL = 'X-Dev-Entra-Email';

    public function __construct(
        private readonly Settings $settings,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->settings->isDevelopmentAuthActive()) {
            return $handler->handle($request);
        }

        if ($request->getAttribute(VerifiedIdentityAttribute::KEY) instanceof VerifiedIdentity) {
            return $handler->handle($request);
        }

        $tenant = $this->headerOrDefault($request, self::HEADER_TENANT, $this->settings->devAuthDefaultTenant);
        $subject = $this->headerOrDefault($request, self::HEADER_SUBJECT, $this->settings->devAuthDefaultSubject);
        // Email is accepted for local diagnostics only; Core identity key is provider+tenant+subject.
        $this->headerOrDefault($request, self::HEADER_EMAIL, $this->settings->devAuthDefaultEmail);

        $verifiedIdentity = new VerifiedIdentity(
            provider: ExternalProvider::MICROSOFT_ENTRA,
            subjectId: $subject,
            tenantId: $tenant,
        );

        return $handler->handle(
            $request->withAttribute(VerifiedIdentityAttribute::KEY, $verifiedIdentity),
        );
    }

    private function headerOrDefault(ServerRequestInterface $request, string $header, string $default): string
    {
        $values = $request->getHeader($header);
        $value = trim($values[0] ?? '');

        return $value !== '' ? $value : $default;
    }
}
