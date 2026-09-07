<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\ProductMediaSummary;

/**
 * Canonical public/customer-view eligibility for product images.
 * Matches admin {@see isDisplayableProductMedia} and customer-view listPublicMedia filters.
 */
final class PublicEligibleProductMedia
{
    private const ACTIVE_UPLOAD = ['active', 'uploaded'];

    private const CLEAN_SCAN = ['clean', 'not_required', 'skipped'];

    public static function isEligible(ProductMediaSummary $item): bool
    {
        $upload = strtolower(trim((string) ($item->uploadStatusCode ?? 'active')));
        $scan = strtolower(trim((string) ($item->scanStatusCode ?? 'not_required')));
        if (!in_array($upload, self::ACTIVE_UPLOAD, true)) {
            return false;
        }
        if (!in_array($scan, self::CLEAN_SCAN, true)) {
            return false;
        }

        $public = trim((string) ($item->publicUrl ?? ''));
        $thumb = trim((string) ($item->thumbnailUrl ?? ''));

        return $public !== '' || $thumb !== '';
    }
}
