<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Odoo;

use Closure;

/**
 * One list: what to ask Odoo for and how to shape what comes back.
 */
final readonly class OdooListSpec
{
    /**
     * @param list<string> $fields
     * @param string|null $codeField the payload key lifted into the record's `code` (null: none)
     * @param Closure(list<int>): list<array{0: string, 1: string, 2: mixed}> $domain from the company ids to an Odoo domain
     * @param Closure(array<string, mixed>): array<string, mixed> $shape an Odoo row to the payload
     */
    public function __construct(
        public string $key,
        public string $model,
        public array $fields,
        public ?string $codeField,
        public Closure $domain,
        public Closure $shape,
    ) {
    }

    /**
     * @param list<int> $companyIds
     * @return list<array{0: string, 1: string, 2: mixed}>
     */
    public function domainFor(array $companyIds): array
    {
        return ($this->domain)($companyIds);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function payload(array $row): array
    {
        return ($this->shape)($row);
    }

    public function isCompanies(): bool
    {
        return $this->key === OdooLists::COMPANIES;
    }
}
