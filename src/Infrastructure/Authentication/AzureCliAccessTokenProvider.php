<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Authentication;

use JsonException;
use TriCab\Api\Infrastructure\Database\DatabaseAuthDiagnostics;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;

/**
 * Obtains a short-lived Azure SQL access token via Azure CLI.
 * Tokens are cached in-process until expiry - 60 seconds. Never logged or written to disk.
 */
final class AzureCliAccessTokenProvider
{
    private const RESOURCE = 'https://database.windows.net/';

    private const REFRESH_SKEW_SECONDS = 60;

    private static ?string $cachedToken = null;

    private static int $cachedExpiresAt = 0;

    private static bool $cliAvailable = false;

    /** @var (callable(): int)|null */
    private $nowFn;

    /**
     * @param (callable(): int)|null $now
     */
    public function __construct(
        private readonly string $azBinary = 'az',
        private readonly AzureCliProcessRunner $processRunner = new ProcOpenAzureCliProcessRunner(),
        private readonly ?RequestPerformanceMetrics $metrics = null,
        ?callable $now = null,
    ) {
        $this->nowFn = $now;
    }

    public static function resetProcessCache(): void
    {
        self::$cachedToken = null;
        self::$cachedExpiresAt = 0;
        self::$cliAvailable = false;
    }

    public function getAccessToken(): string
    {
        $started = hrtime(true);
        $now = $this->now();

        if (
            self::$cachedToken !== null
            && self::$cachedToken !== ''
            && $now < (self::$cachedExpiresAt - self::REFRESH_SKEW_SECONDS)
        ) {
            $this->metrics?->recordTokenAcquisition(
                RequestPerformanceMetrics::durationMs($started),
                true,
            );

            return self::$cachedToken;
        }

        DatabaseAuthDiagnostics::log('azure_cli.token.start', [
            'azBinary' => $this->azBinary,
            'resource' => self::RESOURCE,
            'pathEnvPresent' => getenv('PATH') !== false,
            'cacheRefresh' => self::$cachedToken !== null,
        ]);

        $this->assertAzureCliAvailable();

        [$exitCode, $stdout] = $this->processRunner->run([
            $this->azBinary,
            'account',
            'get-access-token',
            '--resource',
            self::RESOURCE,
            '--output',
            'json',
        ]);

        if ($exitCode !== 0) {
            self::$cachedToken = null;
            self::$cachedExpiresAt = 0;
            DatabaseAuthDiagnostics::log('azure_cli.command.failed', [
                'step' => 'az_get_access_token',
                'success' => false,
                'exitCode' => $exitCode,
                'tokenAcquired' => false,
            ]);
            throw new AzureCliAccessTokenException(
                'Failed to acquire an Azure SQL access token via Azure CLI.',
            );
        }

        try {
            $parsed = $this->parseTokenResponse($stdout);
        } catch (AzureCliAccessTokenException $exception) {
            self::$cachedToken = null;
            self::$cachedExpiresAt = 0;
            throw $exception;
        }

        self::$cachedToken = $parsed['token'];
        self::$cachedExpiresAt = $parsed['expiresAt'];
        self::$cliAvailable = true;

        DatabaseAuthDiagnostics::log('azure_cli.command.success', [
            'step' => 'az_get_access_token',
            'success' => true,
            'tokenAcquired' => true,
            'tokenLength' => strlen($parsed['token']),
            'expiresAtUnix' => $parsed['expiresAt'],
        ]);

        $this->metrics?->recordTokenAcquisition(
            RequestPerformanceMetrics::durationMs($started),
            false,
        );

        return $parsed['token'];
    }

    private function assertAzureCliAvailable(): void
    {
        if (self::$cliAvailable) {
            return;
        }

        if ($this->looksLikePath($this->azBinary)) {
            if (!is_executable($this->azBinary)) {
                DatabaseAuthDiagnostics::log('azure_cli.command.failed', [
                    'step' => 'az_available',
                    'success' => false,
                ]);
                throw new AzureCliAccessTokenException(
                    'Azure CLI is unavailable. Install Azure CLI and ensure "az" is on PATH.',
                );
            }
            self::$cliAvailable = true;

            return;
        }
    }

    /**
     * @return array{token: string, expiresAt: int}
     */
    private function parseTokenResponse(string $stdout): array
    {
        $raw = trim($stdout);
        if ($raw === '') {
            DatabaseAuthDiagnostics::log('azure_cli.command.failed', [
                'step' => 'az_get_access_token',
                'success' => false,
                'tokenAcquired' => false,
                'stdoutEmpty' => true,
            ]);
            throw new AzureCliAccessTokenException(
                'Failed to acquire an Azure SQL access token via Azure CLI.',
            );
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            DatabaseAuthDiagnostics::log('azure_cli.command.failed', [
                'step' => 'az_get_access_token_parse',
                'success' => false,
                'tokenAcquired' => false,
            ]);
            throw new AzureCliAccessTokenException(
                'Azure CLI returned an invalid access-token response.',
            );
        }

        if (!is_array($decoded)) {
            throw new AzureCliAccessTokenException(
                'Azure CLI returned an invalid access-token response.',
            );
        }

        $token = $decoded['accessToken'] ?? null;
        if (!is_string($token) || trim($token) === '') {
            throw new AzureCliAccessTokenException(
                'Azure CLI returned an invalid access-token response.',
            );
        }

        $expiresAt = $this->parseExpiry($decoded);
        if ($expiresAt <= $this->now()) {
            throw new AzureCliAccessTokenException(
                'Azure CLI returned an expired access token.',
            );
        }

        return [
            'token' => trim($token),
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * @param array<mixed> $decoded
     */
    private function parseExpiry(array $decoded): int
    {
        $unix = $decoded['expires_on'] ?? $decoded['expiresOnTimestamp'] ?? null;
        if (is_numeric($unix)) {
            return (int) $unix;
        }

        $expiresOn = $decoded['expiresOn'] ?? null;
        if (is_string($expiresOn) && trim($expiresOn) !== '') {
            $parsed = strtotime($expiresOn);
            if ($parsed !== false) {
                return $parsed;
            }
        }

        return $this->now() + 3000;
    }

    private function now(): int
    {
        if ($this->nowFn !== null) {
            return ($this->nowFn)();
        }

        return time();
    }

    private function looksLikePath(string $binary): bool
    {
        return str_contains($binary, '/') || str_starts_with($binary, '\\');
    }
}
