<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Database;

use Throwable;

/**
 * Temporary diagnostics-only logger for Azure SQL auth failures.
 * Writes to var/log/database-auth.log and error_log. Never logs tokens/secrets.
 */
final class DatabaseAuthDiagnostics
{
    private const RELATIVE_LOG_PATH = '/var/log/database-auth.log';

    public static function log(string $event, array $context = []): void
    {
        $safeContext = self::sanitize($context);
        $line = sprintf(
            "[%s] %s %s\n",
            gmdate('c'),
            $event,
            $safeContext === [] ? '{}' : json_encode($safeContext, JSON_UNESCAPED_SLASHES),
        );

        $logFile = self::resolveLogFile();
        if ($logFile !== null) {
            @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
        }

        error_log(rtrim($line));
    }

    public static function logThrowable(string $event, Throwable $throwable, array $context = []): void
    {
        $context['exceptionClass'] = $throwable::class;
        $context['exceptionMessage'] = self::redactSensitive($throwable->getMessage());
        if ($throwable instanceof \PDOException) {
            $context['sqlState'] = (string) ($throwable->errorInfo[0] ?? $throwable->getCode());
            $context['driverCode'] = $throwable->errorInfo[1] ?? null;
            $context['driverMessage'] = isset($throwable->errorInfo[2])
                ? self::redactSensitive((string) $throwable->errorInfo[2])
                : null;
        }

        $previous = $throwable->getPrevious();
        if ($previous instanceof Throwable) {
            $context['previousClass'] = $previous::class;
            $context['previousMessage'] = self::redactSensitive($previous->getMessage());
            if ($previous instanceof \PDOException) {
                $context['previousSqlState'] = (string) ($previous->errorInfo[0] ?? $previous->getCode());
            }
        }

        self::log($event, $context);
    }

    private static function resolveLogFile(): ?string
    {
        $basePath = dirname(__DIR__, 3);
        $dir = $basePath . '/var/log';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        return $dir . '/database-auth.log';
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function sanitize(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            $lower = strtolower((string) $key);
            if (
                str_contains($lower, 'token')
                || str_contains($lower, 'password')
                || str_contains($lower, 'secret')
            ) {
                $safe[$key] = is_string($value) && $value !== '' ? '[REDACTED]' : $value;
                continue;
            }

            if (is_string($value)) {
                $safe[$key] = self::redactSensitive($value);
                continue;
            }

            $safe[$key] = $value;
        }

        return $safe;
    }

    private static function redactSensitive(string $value): string
    {
        // Strip JWT-like segments and AccessToken= values if present.
        $value = preg_replace('/AccessToken=[^;]+/i', 'AccessToken=[REDACTED]', $value) ?? $value;
        $value = preg_replace('/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', '[REDACTED_JWT]', $value) ?? $value;

        return $value;
    }
}
