<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Document;

/**
 * Reads a submitted payload by the dotted paths its form declares, as
 * {@see \TriCab\Core\Onboarding\Domain\Service\FormPayloadSplitter} nests them.
 * Anything missing or not scalar reads as empty — a mapper draws nothing for it.
 */
final class FormAnswers
{
    /** @param array<string, mixed> $answers */
    public function __construct(private readonly array $answers)
    {
    }

    public function text(string $path): string
    {
        $value = $this->value($path);

        return match (true) {
            is_string($value) => trim($value),
            is_int($value), is_float($value) => (string) $value,
            default => '',
        };
    }

    public function ticked(string $path): bool
    {
        return $this->value($path) === true;
    }

    private function value(string $path): mixed
    {
        $node = $this->answers;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }
}
