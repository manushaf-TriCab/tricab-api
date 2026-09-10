<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\LogNote;

use TriCab\Core\Customer\Application\DTO\Pagination;
use TriCab\Core\LogNote\Application\DTO\LogNoteDetails;
use TriCab\Core\LogNote\Application\DTO\LogNoteListResult;
use TriCab\Core\LogNote\Application\DTO\LogNoteSummary;
use TriCab\Core\LogNote\Application\DTO\LogNoteSummaryResult;
use TriCab\Core\LogNote\Domain\ValueObject\LogNoteCategory;
use TriCab\Core\LogNote\Domain\ValueObject\LogNoteInteractionType;

final class LogNoteDtoSerializer
{
    /**
     * @return array<string, mixed>
     */
    public static function details(LogNoteDetails $details): array
    {
        return [
            'publicId' => $details->publicId,
            'customerPublicId' => $details->customerPublicId,
            'customerCode' => $details->customerCode,
            'customerName' => $details->customerName,
            'contactPublicId' => $details->contactPublicId,
            'contactName' => $details->contactName,
            'contactPosition' => $details->contactPosition,
            'interactionType' => $details->interactionTypeCode,
            'interactionTypeLabel' => $details->interactionTypeLabel,
            'category' => $details->categoryCode,
            'categoryLabel' => $details->categoryLabel,
            'body' => $details->body,
            'bodyPreview' => $details->bodyPreview,
            'callResultCode' => $details->callResultCode,
            'isAiGenerated' => $details->isAiGenerated,
            'followUpAt' => $details->followUpAt,
            'createdByUserPublicId' => $details->createdByUserPublicId,
            'createdByDisplayName' => $details->createdByDisplayName,
            'createdAtUtc' => $details->createdAtUtc,
            'countryCode' => $details->countryCode,
            'territoryCode' => $details->territoryCode,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(LogNoteSummary $summary): array
    {
        return [
            'publicId' => $summary->publicId,
            'customerPublicId' => $summary->customerPublicId,
            'customerCode' => $summary->customerCode,
            'customerName' => $summary->customerName,
            'contactPublicId' => $summary->contactPublicId,
            'contactName' => $summary->contactName,
            'contactPosition' => $summary->contactPosition,
            'interactionType' => $summary->interactionTypeCode,
            'interactionTypeLabel' => $summary->interactionTypeLabel,
            'category' => $summary->categoryCode,
            'categoryLabel' => $summary->categoryLabel,
            'bodyPreview' => $summary->bodyPreview,
            'isAiGenerated' => $summary->isAiGenerated,
            'followUpAt' => $summary->followUpAt,
            'createdByUserPublicId' => $summary->createdByUserPublicId,
            'createdByDisplayName' => $summary->createdByDisplayName,
            'createdAtUtc' => $summary->createdAtUtc,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function listResult(LogNoteListResult $result): array
    {
        return [
            'items' => array_map(self::summary(...), $result->items),
            'pagination' => self::pagination($result->pagination),
            'lookups' => self::lookups(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function summarised(LogNoteSummaryResult $result): array
    {
        return [
            'summarisedBody' => $result->summarisedBody,
            'provider' => $result->provider,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function lookups(): array
    {
        $types = [];
        foreach (LogNoteInteractionType::CODES as $code) {
            $types[] = [
                'code' => $code,
                'label' => LogNoteInteractionType::LABELS[$code],
            ];
        }

        $categories = [];
        foreach (LogNoteCategory::CODES as $code) {
            $categories[] = [
                'code' => $code,
                'label' => LogNoteCategory::LABELS[$code],
            ];
        }

        return [
            'interactionTypes' => $types,
            'categories' => $categories,
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function pagination(Pagination $pagination): array
    {
        return [
            'page' => $pagination->page,
            'pageSize' => $pagination->pageSize,
            'totalItems' => $pagination->totalItems,
            'totalPages' => $pagination->totalPages,
        ];
    }
}
