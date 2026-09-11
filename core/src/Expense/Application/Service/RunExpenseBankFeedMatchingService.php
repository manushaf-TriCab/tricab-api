<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseBankFeedRepository;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Domain\Service\ExpenseBankFeedMatcher;
use TriCab\Core\Expense\Domain\Service\ExpenseNearMissDiagnostics;
use TriCab\Core\Expense\Module;
use TriCab\Core\Shared\Time\Clock;

final class RunExpenseBankFeedMatchingService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseBankFeedRepository $bankFeeds,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseProfileRepository $profiles,
        private readonly ExpenseBankFeedMatcher $matcher,
        private readonly ExpenseNearMissDiagnostics $nearMiss,
        private readonly ExpenseAuditRecorder $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{
     *   matched: int,
     *   ambiguous: int,
     *   unmatched: int,
     *   nearMisses: list<array{bankPublicId: string, diagnostics: list<array{receiptId: int, amountDelta: string, reason: string}>}>
     * }
     */
    public function execute(ExpenseActor $actor, ?string $country = null): array
    {
        $this->access->requireFinance($actor);

        $entries = [];
        if ($country !== null && trim($country) !== '') {
            $entries = $this->bankFeeds->listUnmatchedByCountry(strtoupper(trim($country)));
        } else {
            foreach ([Module::COUNTRY_AU, Module::COUNTRY_NZ] as $code) {
                foreach ($this->bankFeeds->listUnmatchedByCountry($code) as $entry) {
                    $entries[] = $entry;
                }
            }
        }

        $candidates = $this->receipts->listMatchCandidates();
        $profilesByUserId = $this->profilesForCandidates($candidates);

        $matched = 0;
        $ambiguous = 0;
        $unmatched = 0;
        $nearMisses = [];
        $now = $this->clock->now();

        foreach ($entries as $entry) {
            $matches = $this->matcher->findCandidates($entry, $candidates, $profilesByUserId);
            $diagnostics = $this->nearMiss->describe($entry, $candidates);
            if ($diagnostics !== []) {
                $nearMisses[] = [
                    'bankPublicId' => $entry->publicId()->toString(),
                    'diagnostics' => $diagnostics,
                ];
            }

            if (count($matches) === 1) {
                $receipt = $matches[0];
                if ($receipt->id() === null || $entry->id() === null) {
                    $unmatched++;
                    continue;
                }
                $entry->matchTo($receipt->id()->toInt());
                $receipt->linkBankFeed($entry->id(), $now);
                $this->bankFeeds->save($entry);
                $this->receipts->save($receipt);
                $candidates = array_values(array_filter(
                    $candidates,
                    static fn (ExpenseReceipt $r): bool => $r->publicId()->toString() !== $receipt->publicId()->toString(),
                ));
                $matched++;
                continue;
            }

            if (count($matches) >= 2) {
                $entry->markAmbiguous();
                $this->bankFeeds->save($entry);
                $ambiguous++;
                continue;
            }

            $unmatched++;
        }

        $this->audit->record(
            $actor->userId,
            'bank_feed_matching_run',
            null,
            sprintf('matched=%d ambiguous=%d unmatched=%d', $matched, $ambiguous, $unmatched),
        );

        return [
            'matched' => $matched,
            'ambiguous' => $ambiguous,
            'unmatched' => $unmatched,
            'nearMisses' => $nearMisses,
        ];
    }

    /**
     * @param list<ExpenseReceipt> $candidates
     * @return array<int, ExpenseProfile>
     */
    private function profilesForCandidates(array $candidates): array
    {
        $byUserId = [];
        foreach ($candidates as $receipt) {
            $uid = $receipt->ownerUserId()->toInt();
            if (isset($byUserId[$uid])) {
                continue;
            }
            $profile = $this->profiles->findByUserId($receipt->ownerUserId());
            if ($profile !== null) {
                $byUserId[$uid] = $profile;
            }
        }

        return $byUserId;
    }
}
