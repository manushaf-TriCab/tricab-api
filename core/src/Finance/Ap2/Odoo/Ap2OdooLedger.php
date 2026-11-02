<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

use DateTimeImmutable;

/**
 * Odoo's payables as read at `readAt`: every open (unreconciled) payable line of the configured companies, the
 * suppliers with a posted vendor bill or an open line, and each company's currency and short entity code.
 */
final readonly class Ap2OdooLedger
{
    /**
     * @param list<Ap2OdooPayableLine> $openLines
     * @param array<int, Ap2OdooVendor> $vendors by partner id
     * @param array<int, string> $entities company id => entity code (AU, NZ, SG, US)
     */
    public function __construct(
        public array $openLines,
        public array $vendors,
        public array $entities,
        public DateTimeImmutable $readAt,
    ) {
    }

    public function entity(int $companyId): string
    {
        return $this->entities[$companyId] ?? (string) $companyId;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'openLines' => array_map(static fn (Ap2OdooPayableLine $l): array => $l->toArray(), $this->openLines),
            'vendors' => array_values(array_map(static fn (Ap2OdooVendor $v): array => $v->toArray(), $this->vendors)),
            'entities' => $this->entities,
            'readAt' => $this->readAt->format(DATE_ATOM),
        ];
    }

    /** @param array<mixed> $a a kept copy: read defensively */
    public static function fromArray(array $a): ?self
    {
        $readAt = is_string($a['readAt'] ?? null) ? DateTimeImmutable::createFromFormat(DATE_ATOM, $a['readAt']) : false;
        if ($readAt === false || !is_array($a['openLines'] ?? null) || !is_array($a['vendors'] ?? null) || !is_array($a['entities'] ?? null)) {
            return null;
        }
        $lines = [];
        foreach ($a['openLines'] as $row) {
            if (is_array($row)) {
                $lines[] = Ap2OdooPayableLine::fromArray($row);
            }
        }
        $vendors = [];
        foreach ($a['vendors'] as $row) {
            if (is_array($row)) {
                $v = Ap2OdooVendor::fromArray($row);
                $vendors[$v->partnerId] = $v;
            }
        }
        $entities = [];
        foreach ($a['entities'] as $id => $code) {
            if (is_int($id) && is_string($code)) {
                $entities[$id] = $code;
            }
        }

        return new self($lines, $vendors, $entities, $readAt);
    }
}
