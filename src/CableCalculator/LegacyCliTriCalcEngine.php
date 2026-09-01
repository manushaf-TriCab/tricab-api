<?php

declare(strict_types=1);

namespace TriCab\Api\CableCalculator;

use TriCab\Core\CableCalculator\Application\DTO\CableCalculationRequest;
use TriCab\Core\CableCalculator\Application\DTO\CableCalculationResult;
use TriCab\Core\CableCalculator\Contract\CableCalculatorEngine;
use TriCab\Core\CableCalculator\Application\Exception\CableCalculationValidationException;

/**
 * Delegates to verified legacy PHP engine via tc-web calculate-json CLI.
 * LOCAL_LEGACY_PARITY_BASELINE — requires legacy tc DB or snapshot DB configured in tc-web.
 */
final class LegacyCliTriCalcEngine implements CableCalculatorEngine
{
    public function __construct(
        private readonly string $calculateJsonScriptPath,
        private readonly string $phpBinary,
    ) {
    }

    public function calculate(CableCalculationRequest $request, bool $isPreview = false): CableCalculationResult
    {
        if (!is_readable($this->calculateJsonScriptPath)) {
            throw new \RuntimeException(
                'Legacy TriCalc script not found: ' . $this->calculateJsonScriptPath,
            );
        }

        $inputJson = json_encode($request->toLegacyInput(), JSON_THROW_ON_ERROR);
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $process = proc_open(
            [$this->phpBinary, $this->calculateJsonScriptPath],
            $descriptor,
            $pipes,
            dirname($this->calculateJsonScriptPath),
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start legacy TriCalc process.');
        }

        fwrite($pipes[0], $inputJson);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        $decoded = self::decodeLegacyJsonPayload($stdout ?: '');
        if (!is_array($decoded) || empty($decoded['ok'])) {
            $message = is_array($decoded) ? (string) ($decoded['error'] ?? 'unknown') : 'invalid JSON';
            throw new \RuntimeException('Legacy TriCalc error: ' . $message);
        }

        $output = $decoded['output'] ?? null;
        if (!is_array($output)) {
            throw new \RuntimeException('Legacy TriCalc returned empty output.');
        }

        if (empty($output['result90']) && empty($output['result110'])) {
            throw CableCalculationValidationException::withFieldErrors([
                'loadAmps' => 'No suitable cable size found for the given inputs.',
            ]);
        }

        return CableCalculationResult::fromLegacyOutput($request, $output, $isPreview);
    }

    /** @return array<string, mixed>|null */
    private static function decodeLegacyJsonPayload(string $stdout): ?array
    {
        $trimmed = trim($stdout);
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($stdout, '{"ok"');
        if ($start === false) {
            $start = strpos($stdout, '{');
        }
        if ($start === false) {
            return null;
        }

        $decoded = json_decode(substr($stdout, $start), true);

        return is_array($decoded) ? $decoded : null;
    }
}
