<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Migration\Form;

/**
 * Bookkeeping for {@see SourceFormTranslator}: copies source leaves to target
 * paths and remembers which leaves were used, so whatever is left over and not
 * empty is reported as dropped rather than lost silently.
 *
 * @internal
 */
final class Collector
{
    public const TRAINEE = 'trainee';
    public const HR = 'hr';
    public const MANAGER = 'manager';

    /** @var array<string, scalar> */
    public array $trainee = [];
    /** @var array<string, scalar> */
    public array $hr = [];
    /** @var array<string, scalar> */
    public array $manager = [];

    /** @var array<string, mixed> source leaf path => value, not yet used */
    private array $leaves;

    /** @param array<array-key, mixed> $data */
    public function __construct(array $data)
    {
        $this->leaves = self::flatten($data, '');
    }

    public function copy(string $sourcePath, string $targetPath, string $part = self::TRAINEE): void
    {
        if (!array_key_exists($sourcePath, $this->leaves)) {
            return;
        }
        $value = $this->leaves[$sourcePath];
        unset($this->leaves[$sourcePath]);
        if ($value === null || (is_string($value) && trim($value) === '') || !is_scalar($value)) {
            return;
        }
        match ($part) {
            self::HR => $this->hr[$targetPath] = $value,
            self::MANAGER => $this->manager[$targetPath] = $value,
            default => $this->trainee[$targetPath] = $value,
        };
    }

    public function consume(string $sourcePath): void
    {
        unset($this->leaves[$sourcePath]);
    }

    /** @return array<string, mixed> */
    public function remaining(): array
    {
        return $this->leaves;
    }

    /** @return list<string> source paths of non-empty values nothing used, sorted */
    public function leftovers(): array
    {
        $left = [];
        foreach ($this->leaves as $path => $value) {
            if ($value === null || $value === false || (is_string($value) && trim($value) === '')) {
                continue;
            }
            $left[] = $path;
        }
        sort($left);

        return $left;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function flatten(array $data, string $prefix): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value) && $value !== []) {
                $out += self::flatten($value, $path);
            } elseif (!is_array($value)) {
                $out[$path] = $value;
            }
        }

        return $out;
    }
}
