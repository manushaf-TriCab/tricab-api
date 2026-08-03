<?php

declare(strict_types=1);

namespace TriCab\Api\Bootstrap;

use DI\Container;
use DI\ContainerBuilder;
use InvalidArgumentException;
use PDO;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Action\DatabaseHealthAction;
use TriCab\Api\Http\Action\GetAuthenticatedUserAction;
use TriCab\Api\Http\Action\HealthAction;
use TriCab\Api\Http\Action\LogoutGuestAction;
use TriCab\Api\Http\Action\RequestGuestOtpAction;
use TriCab\Api\Http\Action\VerifyGuestOtpAction;
use TriCab\Api\Http\Auth\GuestAuthErrorMapper;
use TriCab\Api\Http\Auth\GuestAuthEventLogger;
use TriCab\Api\Http\Auth\GuestAuthRateLimiter;
use TriCab\Api\Http\Auth\NoOpGuestAuthRateLimiter;
use TriCab\Api\Http\Middleware\BearerTokenAuthenticationMiddleware;
use TriCab\Api\Http\Middleware\CorsMiddleware;
use TriCab\Api\Http\Middleware\DevelopmentAuthenticationMiddleware;
use TriCab\Api\Http\Middleware\JsonExceptionMiddleware;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\CompositeBearerTokenValidator;
use TriCab\Api\Identity\Authentication\EntraJwksClient;
use TriCab\Api\Identity\Authentication\EntraJwksProvider;
use TriCab\Api\Identity\Authentication\EntraJwtValidationDebugLogger;
use TriCab\Api\Identity\Authentication\EntraTokenValidator;
use TriCab\Api\Identity\Authentication\GuestJwtIssuer;
use TriCab\Api\Identity\Authentication\GuestJwtValidator;
use TriCab\Api\Identity\Authentication\MicrosoftEntraJwtValidator;
use TriCab\Api\Identity\Email\DevelopmentGuestOtpEmailSender;
use TriCab\Api\Identity\Email\MicrosoftGraphGuestOtpEmailSender;
use TriCab\Api\Identity\Email\PostmarkGuestOtpEmailSender;
use TriCab\Api\Identity\Persistence\AzureSqlEmailOtpChallengeRepository;
use TriCab\Api\Identity\Persistence\AzureSqlGuestSessionRepository;
use TriCab\Api\Identity\Persistence\AzureSqlUserProvisioningRepository;
use TriCab\Api\Identity\Persistence\AzureSqlUserRepository;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Identity\Persistence\InMemoryEmailOtpChallengeRepository;
use TriCab\Api\Identity\Persistence\InMemoryGuestSessionRepository;
use TriCab\Api\Infrastructure\Authentication\AzureCliAccessTokenProvider;
use TriCab\Api\Infrastructure\Database\AzureSqlConnectionFactory;
use TriCab\Api\Infrastructure\Database\AzureSqlPdoFactory;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Api\Infrastructure\Http\StreamJsonHttpClient;
use TriCab\Core\Identity\Application\Handler\ResolveAuthenticatedUserHandler;
use TriCab\Core\Identity\Application\Service\CreateGuestSessionService;
use TriCab\Core\Identity\Application\Service\GuestUserProvisioningService;
use TriCab\Core\Identity\Application\Service\IssueGuestSessionService;
use TriCab\Core\Identity\Application\Service\RequestAndSendGuestOtpService;
use TriCab\Core\Identity\Application\Service\RequestGuestOtpService;
use TriCab\Core\Identity\Application\Service\RevokeGuestSessionService;
use TriCab\Core\Identity\Application\Service\VerifyGuestOtpService;
use TriCab\Core\Identity\Contract\EmailOtpChallengeRepository;
use TriCab\Core\Identity\Contract\GuestAccessTokenIssuer;
use TriCab\Core\Identity\Contract\GuestAccessTokenValidator;
use TriCab\Core\Identity\Contract\GuestOtpEmailSender;
use TriCab\Core\Identity\Contract\GuestSessionRepository;
use TriCab\Core\Identity\Contract\OtpGenerator;
use TriCab\Core\Identity\Contract\OtpHasher;
use TriCab\Core\Identity\Contract\SessionTokenHasher;
use TriCab\Core\Identity\Contract\UserProvisioningRepository;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Service\PasswordOtpHasher;
use TriCab\Core\Identity\Domain\Service\SecureOtpGenerator;
use TriCab\Core\Identity\Domain\Service\Sha256SessionTokenHasher;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

final class ContainerFactory
{
    public static function create(Settings $settings): Container
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        $definitions = [
            Settings::class => $settings,
            JsonResponder::class => \DI\create(JsonResponder::class),
            EntraJwksProvider::class => \DI\autowire(EntraJwksClient::class),
            EntraJwksClient::class => \DI\autowire(EntraJwksClient::class),
            EntraJwtValidationDebugLogger::class => \DI\autowire(EntraJwtValidationDebugLogger::class),
            EntraTokenValidator::class => \DI\autowire(MicrosoftEntraJwtValidator::class),
            ResolveAuthenticatedUserHandler::class => \DI\autowire(ResolveAuthenticatedUserHandler::class),
            HealthAction::class => \DI\autowire(HealthAction::class),
            DatabaseHealthAction::class => \DI\autowire(DatabaseHealthAction::class),
            GetAuthenticatedUserAction::class => \DI\autowire(GetAuthenticatedUserAction::class),
            DevelopmentAuthenticationMiddleware::class => \DI\autowire(DevelopmentAuthenticationMiddleware::class),
            BearerTokenAuthenticationMiddleware::class => \DI\autowire(BearerTokenAuthenticationMiddleware::class),
            CorsMiddleware::class => \DI\autowire(CorsMiddleware::class),
            JsonExceptionMiddleware::class => \DI\autowire(JsonExceptionMiddleware::class),
            AzureCliAccessTokenProvider::class => \DI\autowire(AzureCliAccessTokenProvider::class),
            AzureSqlPdoFactory::class => \DI\autowire(AzureSqlPdoFactory::class),
            AzureSqlConnectionFactory::class => \DI\autowire(AzureSqlConnectionFactory::class),
            JsonHttpClient::class => \DI\autowire(StreamJsonHttpClient::class),
            SessionTokenHasher::class => \DI\autowire(Sha256SessionTokenHasher::class),
            Clock::class => \DI\autowire(SystemClock::class),
            GuestAuthEventLogger::class => \DI\create(GuestAuthEventLogger::class),
            GuestAuthErrorMapper::class => \DI\autowire(GuestAuthErrorMapper::class),
            GuestAuthRateLimiter::class => \DI\autowire(NoOpGuestAuthRateLimiter::class),
            GuestOtpEmailSender::class => static function (Container $container) use ($settings): GuestOtpEmailSender {
                return self::createGuestOtpEmailSender($settings, $container);
            },
            CompositeBearerTokenValidator::class => static function (Container $container) use ($settings): CompositeBearerTokenValidator {
                $guestFactory = null;
                if ($settings->isGuestJwtConfigured()) {
                    $guestFactory = static function () use ($container): GuestAccessTokenValidator {
                        return self::get($container, GuestAccessTokenValidator::class);
                    };
                }

                return new CompositeBearerTokenValidator(
                    entraTokenValidator: self::get($container, EntraTokenValidator::class),
                    guestTokenValidatorFactory: $guestFactory,
                    settings: $settings,
                );
            },
        ];

        if ($settings->usesAzureSqlUserRepository()) {
            if (!$settings->isGuestJwtConfigured()) {
                throw new InvalidArgumentException(
                    'GUEST_JWT_ISSUER, GUEST_JWT_AUDIENCE, and GUEST_JWT_SECRET (min 32 bytes) are required when USER_REPOSITORY=azure-sql.',
                );
            }

            $definitions[PDO::class] = static function (Container $container): PDO {
                $factory = self::get($container, AzureSqlPdoFactory::class);

                return $factory->create();
            };
            $definitions[SqlConnection::class] = \DI\autowire(PdoSqlConnection::class);
            $definitions[UserRepository::class] = \DI\autowire(AzureSqlUserRepository::class);
            $definitions[EmailOtpChallengeRepository::class] = \DI\autowire(AzureSqlEmailOtpChallengeRepository::class);
            $definitions[GuestSessionRepository::class] = \DI\autowire(AzureSqlGuestSessionRepository::class);
            $definitions[UserProvisioningRepository::class] = \DI\autowire(AzureSqlUserProvisioningRepository::class);
            self::addGuestAuthDefinitions($definitions, $settings);
        } else {
            $definitions[UserRepository::class] = \DI\autowire(InMemoryApiUserRepository::class);
            $definitions[UserProvisioningRepository::class] = static function (Container $container): UserProvisioningRepository {
                $repository = self::get($container, UserRepository::class);
                if (!$repository instanceof UserProvisioningRepository) {
                    throw new InvalidArgumentException('In-memory UserRepository must also implement UserProvisioningRepository.');
                }

                return $repository;
            };

            if ($settings->isGuestJwtConfigured()) {
                $definitions[EmailOtpChallengeRepository::class] = \DI\autowire(InMemoryEmailOtpChallengeRepository::class);
                $definitions[GuestSessionRepository::class] = \DI\autowire(InMemoryGuestSessionRepository::class);
                self::addGuestAuthDefinitions($definitions, $settings);
            }
        }

        $builder->addDefinitions($definitions);

        return $builder->build();
    }

    /**
     * @param array<string, mixed> $definitions
     */
    private static function addGuestAuthDefinitions(array &$definitions, Settings $settings): void
    {
        $definitions[OtpGenerator::class] = \DI\autowire(SecureOtpGenerator::class);
        $definitions[OtpHasher::class] = \DI\autowire(PasswordOtpHasher::class);
        $definitions[GuestAccessTokenIssuer::class] = static function () use ($settings): GuestAccessTokenIssuer {
            return new GuestJwtIssuer(
                issuer: $settings->guestJwtIssuer,
                audience: $settings->guestJwtAudience,
                secret: $settings->guestJwtSecret,
            );
        };
        $definitions[GuestAccessTokenValidator::class] = static function (Container $container) use ($settings): GuestAccessTokenValidator {
            return new GuestJwtValidator(
                issuer: $settings->guestJwtIssuer,
                audience: $settings->guestJwtAudience,
                secret: $settings->guestJwtSecret,
                ttlSeconds: $settings->guestJwtTtlSeconds,
                clockSkewSeconds: $settings->guestJwtClockSkewSeconds,
                sessions: self::get($container, GuestSessionRepository::class),
                tokenHasher: self::get($container, SessionTokenHasher::class),
                users: self::get($container, UserRepository::class),
                clock: self::get($container, Clock::class),
            );
        };
        $definitions[GuestUserProvisioningService::class] = \DI\autowire(GuestUserProvisioningService::class);
        $definitions[RequestGuestOtpService::class] = \DI\autowire(RequestGuestOtpService::class);
        $definitions[VerifyGuestOtpService::class] = \DI\autowire(VerifyGuestOtpService::class);
        $definitions[CreateGuestSessionService::class] = static function (Container $container) use ($settings): CreateGuestSessionService {
            return new CreateGuestSessionService(
                sessions: self::get($container, GuestSessionRepository::class),
                clock: self::get($container, Clock::class),
                ttl: 'PT' . $settings->guestJwtTtlSeconds . 'S',
            );
        };
        $definitions[IssueGuestSessionService::class] = static function (Container $container) use ($settings): IssueGuestSessionService {
            return new IssueGuestSessionService(
                createGuestSession: self::get($container, CreateGuestSessionService::class),
                tokenIssuer: self::get($container, GuestAccessTokenIssuer::class),
                tokenHasher: self::get($container, SessionTokenHasher::class),
                clock: self::get($container, Clock::class),
                ttlSeconds: $settings->guestJwtTtlSeconds,
            );
        };
        $definitions[RevokeGuestSessionService::class] = \DI\autowire(RevokeGuestSessionService::class);
        $definitions[RequestAndSendGuestOtpService::class] = \DI\autowire(RequestAndSendGuestOtpService::class);
        $definitions[RequestGuestOtpAction::class] = \DI\autowire(RequestGuestOtpAction::class);
        $definitions[VerifyGuestOtpAction::class] = \DI\autowire(VerifyGuestOtpAction::class);
        $definitions[LogoutGuestAction::class] = \DI\autowire(LogoutGuestAction::class);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private static function get(Container $container, string $id): object
    {
        $service = $container->get($id);
        if (!$service instanceof $id) {
            throw new InvalidArgumentException(sprintf('Container service "%s" has unexpected type.', $id));
        }

        return $service;
    }

    private static function createGuestOtpEmailSender(Settings $settings, Container $container): GuestOtpEmailSender
    {
        if ($settings->usesDevelopmentGuestEmail()) {
            return new DevelopmentGuestOtpEmailSender(
                revealEnabled: $settings->guestOtpDevReveal,
                isProduction: $settings->isProduction(),
            );
        }

        $http = self::get($container, JsonHttpClient::class);

        if ($settings->usesMicrosoftGraphGuestEmail()) {
            if (
                $settings->graphTenantId === ''
                || $settings->graphClientId === ''
                || $settings->graphClientSecret === ''
                || $settings->rfqMailbox === ''
            ) {
                throw new InvalidArgumentException(
                    'GRAPH_TENANT_ID, GRAPH_CLIENT_ID, GRAPH_CLIENT_SECRET, and RFQ_MAILBOX are required when GUEST_EMAIL_PROVIDER=microsoft-graph.',
                );
            }

            return new MicrosoftGraphGuestOtpEmailSender(
                http: $http,
                tenantId: $settings->graphTenantId,
                clientId: $settings->graphClientId,
                clientSecret: $settings->graphClientSecret,
                mailbox: $settings->rfqMailbox,
                fromDisplayName: 'TriCab',
                logoUrl: $settings->guestOtpEmailLogoUrl !== '' ? $settings->guestOtpEmailLogoUrl : null,
            );
        }

        if ($settings->postmarkServerToken === '' || $settings->postmarkFromEmail === '') {
            throw new InvalidArgumentException(
                'POSTMARK_SERVER_TOKEN and POSTMARK_FROM_EMAIL are required when GUEST_EMAIL_PROVIDER=postmark.',
            );
        }

        return new PostmarkGuestOtpEmailSender(
            http: $http,
            serverToken: $settings->postmarkServerToken,
            fromEmail: $settings->postmarkFromEmail,
            fromName: $settings->postmarkFromName,
            messageStream: $settings->postmarkMessageStream,
        );
    }
}
