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
        if ((str_contains($binary, '/') || str_starts_with($binary, '\\')) && !is_executable($binary)) {
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
