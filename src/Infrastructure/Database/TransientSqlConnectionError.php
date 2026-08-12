<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Database;

use PDOException;

/**
 * Narrow classifier for PDO *connection establishment* failures that may be
 * Azure SQL serverless resume. Must not be used for query errors.
 */
final class TransientSqlConnectionError
{
    public static function classify(PDOException $exception): ?string
    {
        $info = $exception->errorInfo;
        $sqlState = '';
        if (is_array($info) && isset($info[0]) && is_string($info[0])) {
            $sqlState = strtoupper($info[0]);
        }
        if ($sqlState === '') {
            $sqlState = strtoupper((string) $exception->getCode());
        }

        $driverCode = 0;
        if (is_array($info) && isset($info[1]) && is_numeric($info[1])) {
            $driverCode = (int) $info[1];
        }

        $message = strtolower($exception->getMessage());

        if (self::isAuthFailure($sqlState, $driverCode, $message)) {
            return null;
        }

        if (
            $sqlState === 'HYT00'
            || str_contains($message, 'login timeout')
            || str_contains($message, 'sqlstate[hyt00]')
        ) {
            return 'HYT00';
        }
        if ($sqlState === 'HYT01' || str_contains($message, 'sqlstate[hyt01]')) {
            return 'HYT01';
        }
        if (
            $sqlState === '08001'
            && (
                str_contains($message, 'timeout')
                || str_contains($message, 'not currently available')
            )
        ) {
            return '08001';
        }
        if ($driverCode === 40613 || str_contains($message, 'not currently available')) {
            return '40613';
        }

        return null;
    }

    private static function isAuthFailure(string $sqlState, int $driverCode, string $message): bool
    {
        if ($sqlState === '28000' || $sqlState === '28001') {
            return true;
        }
        if ($driverCode === 18456 || $driverCode === 18452) {
            return true;
        }
        if (str_contains($message, 'login failed') || str_contains($message, 'authentication failed')) {
            return true;
        }

        return false;
    }
}
