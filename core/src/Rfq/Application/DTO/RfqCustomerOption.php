<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

/**
 * One customer the quote header can name, as the source's header holds it:
 * `id` is the 4js customer id (`cust.id`, the source's `customer_id`), which
 * is what identifies the customer — 4js codes are not unique — and `code` is
 * the code as 4js spells it. `salesOfficeCode` is what its territory implies
 * (`SalesOfficePolicy`).
 */
final class RfqCustomerOption
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $territory,
        public readonly string $salesOfficeCode,
    ) {
    }

    /** @return array{id: int, code: string, name: string, territory: ?string, salesOfficeCode: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'territory' => $this->territory,
            'salesOfficeCode' => $this->salesOfficeCode,
        ];
    }
}
