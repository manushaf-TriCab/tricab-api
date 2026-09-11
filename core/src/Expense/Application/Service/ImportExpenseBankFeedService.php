<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseBankFeedRepository;
use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;
use TriCab\Core\Expense\Domain\Service\ExpenseAuBankFeedCsvParser;
use TriCab\Core\Expense\Domain\Service\ExpenseNzBankFeedCsvParser;
use TriCab\Core\Expense\Module;
use TriCab\Core\Shared\Time\Clock;

final class ImportExpenseBankFeedService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseBankFeedRepository $bankFeeds,
        private readonly ExpenseAuBankFeedCsvParser $auParser,
        private readonly ExpenseNzBankFeedCsvParser $nzParser,
        private readonly GetOrCreateExpensePeriodService $periods,
        private readonly RunExpenseBankFeedMatchingService $matching,
        private readonly ExpenseAuditRecorder $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{
     *   totalRows: int,
     *   inserted: int,
     *   duplicates: int,
     *   invalid: int,
     *   warnings: list<string>,
     *   imported: int,
     *   skippedDuplicates: int,
     *   entries: list<string>,
     *   period: array<string, mixed>|null,
     *   matching: array<string, mixed>|null
     * }
     */
    public function execute(
        ExpenseActor $actor,
        string $country,
        string $csvContents,
        ?string $filename = null,
    ): array {
        $this->access->requireFinance($actor);

        $countryCode = strtoupper(trim($country));
        if (!in_array($countryCode, [Module::COUNTRY_AU, Module::COUNTRY_NZ], true)) {
            throw InvalidExpenseValue::for('country', $country);
        }

        $now = $this->clock->now();
        $parsed = $countryCode === Module::COUNTRY_AU
            ? $this->auParser->parse($csvContents, $now)
            : $this->nzParser->parse($csvContents, (string) ($filename ?? 'nz.csv'), $now);

        $imported = 0;
        $skipped = 0;
        $publicIds = [];
        $warnings = [];

        foreach ($parsed as $entry) {
            if ($this->bankFeeds->findByBankReference($entry->bankReference()) !== null) {
                $skipped++;
                continue;
            }

            $saved = $this->bankFeeds->save($entry);
            $imported++;
            $publicIds[] = $saved->publicId()->toString();
        }

        $period = null;
        $matching = null;
        if ($imported > 0 || $skipped > 0) {
            $period = $this->periods->execute($actor, $countryCode);
            $matching = $this->matching->execute($actor, $countryCode);
        }

        $this->audit->record(
            $actor->userId,
            'bank_feed_imported',
            null,
            sprintf('%s imported=%d skipped=%d file=%s', $countryCode, $imported, $skipped, $filename ?? ''),
        );

        return [
            'totalRows' => count($parsed),
            'inserted' => $imported,
            'duplicates' => $skipped,
            'invalid' => 0,
            'warnings' => $warnings,
            'imported' => $imported,
            'skippedDuplicates' => $skipped,
            'entries' => $publicIds,
            'period' => $period,
            'matching' => $matching,
        ];
    }
}
