<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Finance\Ar\Domain\ArOption;
use TriCab\Core\Finance\Ar\Domain\ArRecord;

/**
 * Every Tri-AR value as the source's JSON shaped it, which is what the ported pages read.
 * Differences, all for the platform: ids that name a person are text (`"12"`, as in Tri-Task)
 * and a record id is 32 hex characters rather than a UUID.
 */
final class ArPresenter
{
    /** A stored UTC timestamp as ISO 8601 with its offset (`+00:00`): JavaScript reads an offset-less one as local time. */
    public static function iso(?DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    }

    /** `email_date` in the one form Graph uses and the pages sort on: `2026-09-27T01:23:45Z` (finding 15). */
    public static function instant(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public static function person(?int $userId): ?string
    {
        return $userId === null ? null : (string) $userId;
    }

    /**
     * A record in the source's keys (what the pages read), with the derived status and, for one record,
     * its body. `assigned_person` is the name at the time (decision D43) with `assigned_user_id` beside it;
     * `due_date` and `credit_note_request_id` are the empty string for none, as the source stored them.
     *
     * @return array<string, mixed>
     */
    public static function record(ArRecord $record, string $today, bool $withBody): array
    {
        $row = [
            'id' => $record->id,
            'email_date' => self::instant($record->emailDate),
            'customer_name' => $record->customerName,
            'sender_email' => $record->senderEmail,
            'sender_name' => $record->senderName,
            'subject' => $record->subject,
        ];
        if ($withBody) {
            $row['body'] = $record->body ?? '';
        }

        return $row + [
            'ai_summary' => $record->aiSummary,
            'follow_up_category' => $record->followUpCategory,
            'assigned_person' => $record->assignedName,
            'assigned_user_id' => self::person($record->assignedUserId),
            'proposed_response_type' => $record->proposedResponseType,
            'proposed_response' => $record->proposedResponse,
            'follow_up_status' => $record->effectiveStatus($today),
            'due_date' => $record->dueDate ?? '',
            'final_outcome' => $record->finalOutcome,
            'source_message_id' => $record->sourceMessageId,
            'original_message_unreachable' => $record->messageUnreachable,
            'mailbox' => $record->mailbox,
            'classification_confidence' => $record->classificationConfidence,
            'status' => $record->status,
            'assigned_sales_rep_email' => $record->assignedSalesRepEmail,
            'credit_note_request_id' => $record->creditNoteRequestId ?? '',
            'reminder_sent_at' => self::iso($record->reminderSentAt),
            'deleted_at' => self::iso($record->deletedAt),
            'deleted_by' => $record->deletedAt === null || $record->deletedBy === '' ? null : $record->deletedBy,
            'created_date' => self::iso($record->createdAt),
            'updated_date' => self::iso($record->updatedAt),
        ];
    }

    /**
     * The source's `_option_row` (plus `usage_count` when it is known).
     *
     * @return array<string, mixed>
     */
    public static function option(ArOption $option, ?int $usage = null): array
    {
        $row = [
            'list_name' => $option->listName,
            'value' => $option->value,
            'label' => $option->label,
            'description' => $option->description,
            'color' => $option->color,
            'sort_order' => $option->sortOrder,
            'active' => $option->active,
            'is_system' => $option->isSystem,
            'created_date' => self::iso($option->createdAt),
            'updated_date' => self::iso($option->updatedAt),
        ];
        if ($usage !== null) {
            $row['usage_count'] = $usage;
        }

        return $row;
    }
}
