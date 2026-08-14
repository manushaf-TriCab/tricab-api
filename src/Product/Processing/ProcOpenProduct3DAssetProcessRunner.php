<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Processing;

use TriCab\Core\Product\Contract\Product3DAssetProcessRunner as Product3DAssetProcessRunnerContract;

/**
 * Invokes tools/product-3d CLI via proc_open argv arrays (bypass_shell).
 * Env: PRODUCT_3D_TOOLS_DIR, PRODUCT_3D_NODE_BIN, PRODUCT_3D_PROCESS_TIMEOUT_MS.
 */
final class ProcOpenProduct3DAssetProcessRunner implements Product3DAssetProcessRunnerContract
{
    public function __construct(
        private readonly ?string $toolsDir = null,
        private readonly ?string $nodeBinary = null,
        private readonly int $timeoutMs = 120_000,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $tools = getenv('PRODUCT_3D_TOOLS_DIR');
        $node = getenv('PRODUCT_3D_NODE_BIN');
        $timeoutRaw = getenv('PRODUCT_3D_PROCESS_TIMEOUT_MS');
        $timeout = is_string($timeoutRaw) && ctype_digit($timeoutRaw)
            ? max(1000, (int) $timeoutRaw)
            : 120_000;

        return new self(
            toolsDir: is_string($tools) && $tools !== '' ? $tools : null,
            nodeBinary: is_string($node) && $node !== '' ? $node : null,
            timeoutMs: $timeout,
        );
    }

    public function isAvailable(): bool
    {
        $cli = $this->cliPath();
        $node = $this->resolveNodeBinary();

        return $cli !== null && $node !== null && is_file($cli);
    }

    public function analyse(string $inputGlbPath): array
    {
        return $this->runJson(['analyse', $inputGlbPath]);
    }

    public function optimise(string $inputGlbPath, string $outputGlbPath, string $profile): array
    {
        return $this->runJson([
            'optimise',
            $inputGlbPath,
            $outputGlbPath,
            '--profile',
            $profile,
        ]);
    }

    /**
     * @param list<string> $cliArgs
     * @return array<string, mixed>
     */
    private function runJson(array $cliArgs): array
    {
        $node = $this->resolveNodeBinary();
        $cli = $this->cliPath();
        if ($node === null || $cli === null) {
            return [
                'ok' => false,
                'error' => [
                    'code' => 'PRODUCT_3D_OPTIMISATION_FAILED',
                    'message' => 'Product 3D tooling is not available (PRODUCT_3D_TOOLS_DIR / node).',
                ],
            ];
        }

        $command = array_merge([$node, $cli], $cliArgs);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, dirname($cli), null, [
            'bypass_shell' => true,
        ]);
        if (!is_resource($process)) {
            return [
                'ok' => false,
                'error' => [
                    'code' => 'PRODUCT_3D_OPTIMISATION_FAILED',
                    'message' => 'Unable to start product-3d process.',
                ],
            ];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + ($this->timeoutMs / 1000);
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(20_000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($timedOut) {
            return [
                'ok' => false,
                'error' => [
                    'code' => 'PRODUCT_3D_OPTIMISATION_FAILED',
                    'message' => 'Product 3D process timed out after ' . $this->timeoutMs . 'ms.',
                ],
            ];
        }

        $decoded = $this->decodeStdout($stdout);
        if ($decoded !== null) {
            return $decoded;
        }

        return [
            'ok' => false,
            'error' => [
                'code' => 'PRODUCT_3D_OPTIMISATION_FAILED',
                'message' => trim($stderr) !== '' ? trim($stderr) : ('Process exited with code ' . $exitCode),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeStdout(string $stdout): ?array
    {
        $trimmed = trim($stdout);
        if ($trimmed === '') {
            return null;
        }
        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // CLI may print trailing newlines; try last JSON object line.
            $lines = array_reverse(preg_split('/\R/', $trimmed) ?: []);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || !str_starts_with($line, '{')) {
                    continue;
                }
                try {
                    $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                } catch (\JsonException) {
                    continue;
                }
            }

            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function cliPath(): ?string
    {
        $dir = $this->toolsDir;
        if ($dir === null || $dir === '') {
            return null;
        }
        $cli = rtrim($dir, "/\\") . '/cli.mjs';

        return is_file($cli) ? $cli : null;
    }

    private function resolveNodeBinary(): ?string
    {
        if ($this->nodeBinary !== null && $this->nodeBinary !== '') {
            return $this->nodeBinary;
        }
        foreach (['node', '/usr/local/bin/node', '/opt/homebrew/bin/node'] as $candidate) {
            if ($candidate === 'node') {
                // Rely on PATH at proc_open time.
                return 'node';
            }
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
