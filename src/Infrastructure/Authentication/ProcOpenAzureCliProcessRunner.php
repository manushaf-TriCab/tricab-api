<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Authentication;

final class ProcOpenAzureCliProcessRunner implements AzureCliProcessRunner
{
    /**
     * @param list<string> $command
     * @return array{0: int, 1: string, 2: string}
     */
    public function run(array $command): array
    {
        $binary = $command[0] ?? '';
        if ($binary === '') {
            return [1, '', 'Azure CLI binary not found.'];
        }

        $isWindowsBatchFile = $this->isWindowsBatchFile($binary);
        if ($this->looksLikePath($binary) && !$this->isAvailable($binary, $isWindowsBatchFile)) {
            return [1, '', 'Azure CLI binary not found.'];
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $processCommand = $isWindowsBatchFile
            ? $this->windowsBatchCommand($command)
            : $command;

        $process = @proc_open($processCommand, $descriptors, $pipes, null, null, [
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

    private function looksLikePath(string $binary): bool
    {
        return str_contains($binary, '/') || str_contains($binary, '\\');
    }

    private function isWindowsBatchFile(string $binary): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }

        $extension = strtolower(pathinfo($binary, PATHINFO_EXTENSION));

        return $extension === 'cmd' || $extension === 'bat';
    }

    private function isAvailable(string $binary, bool $isWindowsBatchFile): bool
    {
        if ($isWindowsBatchFile) {
            return is_file($binary) && is_readable($binary);
        }

        return is_executable($binary);
    }

    /**
     * @param list<string> $command
     */
    private function windowsBatchCommand(array $command): string
    {
        $comSpec = getenv('COMSPEC');
        if (!is_string($comSpec) || trim($comSpec) === '') {
            $comSpec = 'cmd.exe';
        }

        $escapedCommand = implode(' ', array_map(escapeshellarg(...), $command));

        return escapeshellarg($comSpec) . ' /d /s /c "' . $escapedCommand . '"';
    }
}
