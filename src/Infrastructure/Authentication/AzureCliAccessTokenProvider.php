<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Authentication;

/**
 * Obtains a short-lived Azure SQL access token via Azure CLI.
 * Tokens are never logged or written to disk.
 */
final class AzureCliAccessTokenProvider
{
    private const RESOURCE = 'https://database.windows.net/';

    public function __construct(
        private readonly string $azBinary = 'az',
    ) {
    }

    public function getAccessToken(): string
    {
        if (!$this->isAzureCliAvailable()) {
            throw new AzureCliAccessTokenException(
                'Azure CLI is unavailable. Install Azure CLI and ensure "az" is on PATH.',
            );
        }

        if (!$this->isLoggedIn()) {
            throw new AzureCliAccessTokenException(
                'Azure CLI is not logged in. Run "az login" and retry.',
            );
        }

        [$exitCode, $stdout, $stderr] = $this->run([
            $this->azBinary,
            'account',
            'get-access-token',
            '--resource',
            self::RESOURCE,
            '--query',
            'accessToken',
            '--output',
            'tsv',
        ]);

        $token = trim($stdout);

        if ($exitCode !== 0 || $token === '') {
            // Do not include stdout/stderr (may contain sensitive material).
            unset($stderr);
            throw new AzureCliAccessTokenException(
                'Failed to acquire an Azure SQL access token via Azure CLI.',
            );
        }

        return $token;
    }

    private function isAzureCliAvailable(): bool
    {
        if ($this->looksLikePath($this->azBinary)) {
            return is_executable($this->azBinary);
        }

        [$exitCode] = $this->run([$this->azBinary, '--version']);

        return $exitCode === 0;
    }

    private function isLoggedIn(): bool
    {
        [$exitCode] = $this->run([$this->azBinary, 'account', 'show']);

        return $exitCode === 0;
    }

    private function looksLikePath(string $binary): bool
    {
        return str_contains($binary, '/') || str_starts_with($binary, '\\');
    }

    /**
     * @param list<string> $command
     * @return array{0: int, 1: string, 2: string}
     */
    private function run(array $command): array
    {
        $binary = $command[0] ?? '';
        if ($binary === '' || ($this->looksLikePath($binary) && !is_executable($binary))) {
            return [1, '', 'Azure CLI binary not found.'];
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, null, null, [
            'bypass_shell' => true,
        ]);

        if (!is_resource($process)) {
            return [1, '', 'Unable to start Azure CLI process.'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            $exitCode,
            is_string($stdout) ? $stdout : '',
            is_string($stderr) ? $stderr : '',
        ];
    }
}
