<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

/**
 * What differs between Tri-AP's three Settings lists (`COMPANY_FIELDS`, `COST_CENTRE_FIELDS` and
 * `ACCOUNT_CATEGORY_FIELDS` in `store.py`): the table, the text columns with the width the schema
 * gives them, and the one a row cannot be without (which the schema also keeps unique).
 *
 * The URL segment is the list's name: `/companies`, `/cost-centres`, `/account-categories`.
 */
final readonly class ApReferenceSpec
{
    public const COMPANIES = 'companies';
    public const COST_CENTRES = 'cost-centres';
    public const ACCOUNT_CATEGORIES = 'account-categories';
    public const LISTS = [self::COMPANIES, self::COST_CENTRES, self::ACCOUNT_CATEGORIES];

    /**
     * @param array<string, int> $fields text column => its width in the schema, in the source's order
     * @param list<string> $unsortable long text, which a list is not sorted on (question Q5)
     */
    private function __construct(
        public string $list,
        public string $table,
        public string $noun,
        public array $fields,
        public string $required,
        public string $unique,
        public array $unsortable = [],
    ) {
    }

    public static function for(string $list): self
    {
        return match ($list) {
            self::COMPANIES => new self(
                self::COMPANIES,
                'invoice_companies',
                'company',
                ['code' => 50, 'name' => 200, 'sites' => 2000, 'former_names' => 2000],
                'name',
                'name',
                ['sites', 'former_names'],
            ),
            self::COST_CENTRES => new self(
                self::COST_CENTRES,
                'invoice_cost_centres',
                'cost centre',
                ['code_no' => 50, 'code' => 100, 'name' => 200],
                'code',
                'code',
            ),
            self::ACCOUNT_CATEGORIES => new self(
                self::ACCOUNT_CATEGORIES,
                'invoice_account_categories',
                'account category',
                ['code' => 100, 'name' => 200],
                'code',
                'code',
            ),
            default => throw new ApNotFound('Not found'),
        };
    }

    /** What a duplicate of the unique column is told (the source had no message: SQLite raised and the answer was a 500). */
    public function conflictMessage(): string
    {
        return (in_array($this->noun[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'An ' : 'A ') . $this->noun . ' with that ' . $this->unique . ' already exists';
    }

    /**
     * The columns a list may be sorted on: its bounded text, `created_date` and `id`.
     *
     * @return list<string>
     */
    public function sortable(): array
    {
        return [...array_values(array_diff(array_keys($this->fields), $this->unsortable)), 'created_date', 'id'];
    }
}
