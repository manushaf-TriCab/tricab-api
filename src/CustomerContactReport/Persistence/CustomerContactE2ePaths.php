<?php

declare(strict_types=1);

namespace TriCab\Api\CustomerContactReport\Persistence;

use RuntimeException;

/**
 * Development-only files for the Customer Contact browser harness.
 * Always under the system temp directory. Never an Azure path.
 */
final class CustomerContactE2ePaths
{
    public static function root(): string
    {
        $override = $_ENV['CUSTOMER_CONTACT_E2E_DIRECTORY'] ?? getenv('CUSTOMER_CONTACT_E2E_DIRECTORY');
        if (is_string($override) && trim($override) !== '') {
            return self::assertInsideTemp(rtrim($override, '/'));
        }

        return rtrim(sys_get_temp_dir(), '/') . '/tricab-dev-customer-contact-e2e';
    }

    public static function repositoryFile(): string
    {
        return self::root() . '/repository.ser';
    }

    public static function fileDirectory(): string
    {
        return self::root() . '/files';
    }

    public static function reset(): void
    {
        $root = self::root();
        if (!is_dir($root)) {
            return;
        }

        $realRoot = realpath($root);
        $realTemp = realpath(sys_get_temp_dir());
        if (
            $realRoot === false
            || $realTemp === false
            || !str_starts_with($realRoot, $realTemp . DIRECTORY_SEPARATOR)
        ) {
            throw new RuntimeException('Refusing to reset Customer Contact E2E data outside the system temp directory.');
        }

        self::deleteTree($realRoot);
    }

    private static function assertInsideTemp(string $path): string
    {
        $realTemp = realpath(sys_get_temp_dir());
        if ($realTemp === false) {
            throw new RuntimeException('System temp directory is not available for Customer Contact E2E data.');
        }

        $parent = is_dir($path) ? $path : dirname($path);
        $realParent = realpath($parent);
        if ($realParent === false || ($realParent !== $realTemp && !str_starts_with($realParent, $realTemp . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('CUSTOMER_CONTACT_E2E_DIRECTORY must stay inside the system temp directory.');
        }

        return $path;
    }

    private static function deleteTree(string $root): void
    {
        $items = scandir($root);
        if ($items === false) {
            throw new RuntimeException('Customer Contact E2E data could not be read for reset.');
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $root . DIRECTORY_SEPARATOR . $item;
            if (is_link($path)) {
                throw new RuntimeException('Refusing to reset Customer Contact E2E data that contains a symlink.');
            }
            if (is_dir($path)) {
                self::deleteTree($path);
                continue;
            }
            if (!unlink($path)) {
                throw new RuntimeException('Customer Contact E2E data could not be removed.');
            }
        }

        if (!rmdir($root)) {
            throw new RuntimeException('Customer Contact E2E directory could not be removed.');
        }
    }
}
