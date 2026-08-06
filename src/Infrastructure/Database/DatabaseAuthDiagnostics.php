<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Database;

use PDOException;
use Throwable;

/**
 * Diagnostics-only logger for Azure SQL auth/persistence failures.
 * Writes to var/log/database-auth.log and error_log. Never logs tokens/secrets.
 */
final class DatabaseAuthDiagnostics
{
    /**
     * @param array<string, mixed> $context
     */
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

    /**
     * @param array<string, mixed> $context
     */
    public static function logThrowable(string $event, Throwable $throwable, array $context = []): void
    {
        $context['exceptionClass'] = $throwable::class;
        $context['exceptionMessage'] = self::redactSensitive($throwable->getMessage());
        self::appendPdoDetails($context, $throwable, '');

        $previous = $throwable->getPrevious();
        $depth = 0;
        while ($previous instanceof Throwable && $depth < 5) {
            $prefix = $depth === 0 ? 'previous' : 'previous' . ($depth + 1);
            $context[$prefix . 'Class'] = $previous::class;
            $context[$prefix . 'Message'] = self::redactSensitive($previous->getMessage());
            self::appendPdoDetails($context, $previous, $prefix);
            $previous = $previous->getPrevious();
            ++$depth;
        }

        self::log($event, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function appendPdoDetails(array &$context, Throwable $throwable, string $prefix): void
    {
        if (!$throwable instanceof PDOException) {
            return;
        }

        $stateKey = $prefix === '' ? 'sqlState' : $prefix . 'SqlState';
        $codeKey = $prefix === '' ? 'driverCode' : $prefix . 'DriverCode';
        $messageKey = $prefix === '' ? 'driverMessage' : $prefix . 'DriverMessage';

        $sqlState = $throwable->errorInfo[0] ?? $throwable->getCode();
        $context[$stateKey] = self::stringifyScalar($sqlState);

        $driverCode = $throwable->errorInfo[1] ?? null;
        if (is_int($driverCode) || is_string($driverCode)) {
            $context[$codeKey] = $driverCode;
        }

        $driverMessage = $throwable->errorInfo[2] ?? null;
        $context[$messageKey] = self::stringifyScalar($driverMessage);
        if (is_string($context[$messageKey])) {
            $context[$messageKey] = self::redactSensitive($context[$messageKey]);
        }
    }

    private static function stringifyScalar(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
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
                || str_contains($lower, 'otp')
                || str_contains($lower, 'authorization')
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
