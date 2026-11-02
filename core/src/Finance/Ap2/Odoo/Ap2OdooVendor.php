<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

/**
 * A supplier as Odoo holds it (`res.partner`): the master data Tri-AP shows read-only. `terms` is the day count of the
 * vendor payment terms when the name is one (TriCab's NET14 is 14, COD and "Immediate Payment" 0, "30 Days" 30), else
 * null: an end-of-month term (EOM30) is not a day count. `entity` is the
 * companies it has bills or open lines in ("AU, SG").
 */
final readonly class Ap2OdooVendor
{
    public function __construct(
        public int $partnerId,
        public string $name,
        public string $email = '',
        public string $phone = '',
        public string $industry = '',
        public string $entity = '',
        public ?int $terms = null,
        public string $termsName = '',
        public bool $active = true,
    ) {
    }

    /** The day count in an Odoo payment term's name, or null when it has none. */
    public static function termDays(string $name): ?int
    {
        if ($name === '') {
            return null;
        }
        if (preg_match('/^\s*(cod|immediate\b)/i', $name) === 1) {
            return 0;
        }
        if (preg_match('/^\s*net\s*(\d{1,3})\s*$/i', $name, $m) === 1 || preg_match('/^\s*(\d{1,3})\s*days?\b/i', $name, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /** @return array<mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<mixed> $a a kept copy: read defensively */
    public static function fromArray(array $a): self
    {
        $s = static fn (string $k): string => is_string($a[$k] ?? null) ? $a[$k] : '';

        return new self(
            is_int($a['partnerId'] ?? null) ? $a['partnerId'] : 0,
            $s('name'),
            $s('email'),
            $s('phone'),
            $s('industry'),
            $s('entity'),
            is_int($a['terms'] ?? null) ? $a['terms'] : null,
            $s('termsName'),
            ($a['active'] ?? true) !== false,
        );
    }
}
