<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\SignatureInvalidException;
use RuntimeException;
use stdClass;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use UnexpectedValueException;

/**
 * Validates Microsoft Entra access tokens using OpenID JWKS.
 * Does not accept Graph tokens, unsigned payloads, or mismatched audiences.
 */
final class MicrosoftEntraJwtValidator implements EntraTokenValidator
{
    private const GRAPH_AUDIENCES = [
        'https://graph.microsoft.com',
        '00000003-0000-0000-c000-000000000000',
    ];

    /** @var array<string, Key>|null */
    private ?array $cachedKeys = null;

    private int $cachedKeysFetchedAt = 0;

    public function __construct(
        private readonly \TriCab\Api\Config\Settings $settings,
        private readonly EntraJwksProvider $jwksClient = new EntraJwksClient(),
        private readonly ?EntraJwtValidationDebugLogger $debugLogger = null,
    ) {
    }

    public function validate(string $accessToken): VerifiedIdentity
    {
        if (
            $this->settings->entraTenantId === ''
            || $this->settings->entraAudience === ''
        ) {
            $this->debugStage('configuration', false, 'entra jwt not configured');
            throw EntraTokenValidationNotConfigured::create();
        }

        $token = trim($accessToken);
        if ($token === '' || substr_count($token, '.') !== 2) {
            $this->debugStage('parse', false, 'token not parsed');
            throw EntraTokenValidationFailed::create('Malformed access token.');
        }

        $this->debugStage('parse', true, null);

        try {
            $this->debugStage('jwks', true, null);
            $keys = $this->resolveSigningKeys();
            $this->debugStage('signature', true, null);
            $decoded = JWT::decode($token, $keys);
        } catch (ExpiredException) {
            $this->debugStage('expiry', false, 'expired token');
            throw EntraTokenValidationFailed::create('Access token has expired.');
        } catch (BeforeValidException) {
            $this->debugStage('nbf', false, 'token not yet valid');
            throw EntraTokenValidationFailed::create('Access token is not yet valid.');
        } catch (SignatureInvalidException) {
            $this->debugStage('signature', false, 'invalid signature');
            throw EntraTokenValidationFailed::create('Access token signature is invalid.');
        } catch (UnexpectedValueException $exception) {
            $reason = $this->debugLogger?->classifyFailure($exception->getMessage()) ?? 'unknown key id (kid)';
            $this->debugStage('signature', false, $reason);
            throw EntraTokenValidationFailed::create($exception->getMessage());
        } catch (RuntimeException $exception) {
            $reason = $this->debugLogger?->classifyFailure($exception->getMessage()) ?? 'JWKS download failure';
            $this->debugStage('jwks', false, $reason);
            throw EntraTokenValidationFailed::create($exception->getMessage());
        }

        try {
            $this->assertAudience($decoded);
            $this->debugStage('audience', true, null);
            $this->assertIssuer($decoded);
            $this->debugStage('issuer', true, null);
            $this->assertTenant($decoded);
            $this->debugStage('tenant', true, null);
            $this->assertRequiredScope($decoded);
            $this->debugStage('scope', true, null);
        } catch (EntraTokenValidationFailed $exception) {
            $this->debugStage(
                'claims',
                false,
                $this->debugLogger?->classifyFailure($exception->getMessage()) ?? 'claims validation failed',
            );
            throw $exception;
        }

        $subjectClaim = strtolower($this->settings->entraSubjectClaim);
        $subject = $this->stringClaim($decoded, $subjectClaim);
        if ($subject === '') {
            $reason = $subjectClaim === 'oid' ? 'missing oid' : 'missing subject claim';
            $this->debugStage('subject', false, $reason);
            throw EntraTokenValidationFailed::create(
                sprintf('Required identity claim "%s" is missing.', $subjectClaim),
            );
        }

        $tenant = $this->stringClaim($decoded, 'tid');
        if ($tenant === '') {
            $tenant = $this->settings->entraTenantId;
        }

        $this->debugStage('complete', true, null);

        return new VerifiedIdentity(
            provider: ExternalProvider::MICROSOFT_ENTRA,
            subjectId: $subject,
            tenantId: $tenant,
            verifiedEmail: $this->resolveVerifiedEmail($decoded),
        );
    }

    private function resolveVerifiedEmail(stdClass $decoded): ?string
    {
        foreach (['preferred_username', 'upn', 'email', 'unique_name'] as $claim) {
            $value = $this->stringClaim($decoded, $claim);
            if ($value !== '' && str_contains($value, '@')) {
                return strtolower($value);
            }
        }

        return null;
    }

    private function debugStage(string $stage, bool $ok, ?string $failureReason): void
    {
        $this->debugLogger?->log([
            'jwt_validation_stage' => $stage,
            'jwt_validation_stage_ok' => $ok,
            'validation_failure_reason' => $failureReason ?? 'none',
        ]);
    }

    /**
     * @return array<string, Key>
     */
    private function resolveSigningKeys(): array
    {
        $now = time();
        if ($this->cachedKeys !== null && ($now - $this->cachedKeysFetchedAt) < 3600) {
            return $this->cachedKeys;
        }

        $jwks = $this->jwksClient->fetch($this->settings->entraTenantId);
        // Microsoft Entra JWKS intentionally omit "alg". firebase/php-jwt requires an
        // algorithm when constructing Key objects — pass RS256 as defaultAlg only.
        $this->cachedKeys = JWK::parseKeySet($jwks, 'RS256');
        $this->cachedKeysFetchedAt = $now;

        return $this->cachedKeys;
    }

    private function assertAudience(stdClass $decoded): void
    {
        $audience = $decoded->aud ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];
        $normalized = [];

        foreach ($audiences as $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            $normalized[] = $value;
            if (in_array($value, self::GRAPH_AUDIENCES, true)) {
                throw EntraTokenValidationFailed::create(
                    'Microsoft Graph access tokens are not accepted.',
                );
            }
        }

        $expected = $this->settings->entraAudience;
        $accepted = [$expected];
        if (str_starts_with($expected, 'api://')) {
            $accepted[] = substr($expected, strlen('api://'));
        } else {
            $accepted[] = 'api://' . $expected;
        }

        foreach ($normalized as $value) {
            if (in_array($value, $accepted, true)) {
                return;
            }
        }

        throw EntraTokenValidationFailed::create('Access token audience is invalid.');
    }

    private function assertIssuer(stdClass $decoded): void
    {
        $issuer = $this->stringClaim($decoded, 'iss');
        $tenantId = $this->settings->entraTenantId;
        $allowed = [
            sprintf('https://login.microsoftonline.com/%s/v2.0', $tenantId),
            sprintf('https://sts.windows.net/%s/', $tenantId),
            sprintf('https://login.microsoftonline.com/%s/', $tenantId),
        ];

        if (!in_array($issuer, $allowed, true)) {
            throw EntraTokenValidationFailed::create('Access token issuer is invalid.');
        }
    }

    private function assertTenant(stdClass $decoded): void
    {
        $tid = $this->stringClaim($decoded, 'tid');
        if ($tid !== '' && !hash_equals($this->settings->entraTenantId, $tid)) {
            throw EntraTokenValidationFailed::create('Access token tenant is invalid.');
        }
    }

    private function assertRequiredScope(stdClass $decoded): void
    {
        $required = trim($this->settings->entraRequiredScope);
        if ($required === '') {
            return;
        }

        $scp = $this->stringClaim($decoded, 'scp');
        if ($scp !== '') {
            $scopes = preg_split('/\s+/', $scp) ?: [];
            foreach ($scopes as $scope) {
                if (hash_equals($required, $scope) || str_ends_with($scope, '/' . $required)) {
                    return;
                }
            }

            throw EntraTokenValidationFailed::insufficientScope(
                sprintf('Required delegated scope "%s" is missing.', $required),
            );
        }

        // App-only tokens use roles instead of scp — reject for this SPA flow.
        throw EntraTokenValidationFailed::insufficientScope(
            sprintf('Required delegated scope "%s" is missing.', $required),
        );
    }

    private function stringClaim(stdClass $decoded, string $claim): string
    {
        $value = $decoded->{$claim} ?? null;

        return is_string($value) ? trim($value) : '';
    }
}
