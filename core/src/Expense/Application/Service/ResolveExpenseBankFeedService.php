<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseFinanceViews;
use TriCab\Core\Expense\Application\Exception\ExpenseBankFeedNotFound;
use TriCab\Core\Expense\Application\ExpenseRowVersion;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseBankFeedRepository;
use TriCab\Core\Expense\Contract\ExpenseNotificationPort;
use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseBankFeedPublicId;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseFinanceResolution;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseBankFeedRef;
use TriCab\Core\Expense\Module;
use TriCab\Core\Shared\Time\Clock;

final class ResolveExpenseBankFeedService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseBankFeedRepository $bankFeeds,
        private readonly ExpenseNotificationPort $notifications,
        private readonly ExpenseAuditRecorder $audit,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(
        ExpenseActor $actor,
        string $bankPublicId,
        string $resolution,
        ?string $note,
        string $expectedRowVersion,
    ): array {
        $this->access->requireFinance($actor);
        LegacyExpenseBankFeedRef::assertNotLegacy($bankPublicId);

        $normalized = strtolower(trim($resolution));
        if (!in_array($normalized, Module::BANK_RESOLUTIONS, true)) {
            throw InvalidExpenseValue::for('resolution', $resolution);
        }

        $entry = $this->bankFeeds->findByPublicId(ExpenseBankFeedPublicId::fromString($bankPublicId));
        if ($entry === null) {
            throw ExpenseBankFeedNotFound::forPublicId($bankPublicId);
        }

        $vo = ExpenseFinanceResolution::fromString($normalized);
        $entry->resolve($vo, $note, $actor->userId, $this->clock->now());
        $saved = $this->bankFeeds->save($entry, ExpenseRowVersion::require($expectedRowVersion));

        if ($vo->isChased()) {
            $this->notifications->notifyChaseBankTx($saved);
        }

        $this->audit->record(
            $actor->userId,
            'bank_feed_resolved',
            null,
            sprintf('%s:%s', $saved->publicId()->toString(), $normalized),
        );

        return ExpenseFinanceViews::bankEntry($saved);
    }
}
