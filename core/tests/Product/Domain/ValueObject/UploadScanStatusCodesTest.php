<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\ProductFileKind;
use TriCab\Core\Product\Domain\ValueObject\ScanStatusCode;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;

final class UploadScanStatusCodesTest extends TestCase
{
    public function test_upload_status_codes_round_trip(): void
    {
        self::assertSame(
            ['pending_upload', 'uploaded', 'active', 'failed', 'deleted', 'replaced'],
            UploadStatusCode::allowedCodes(),
        );
        self::assertTrue(UploadStatusCode::fromString('ACTIVE')->isActive());
        self::assertTrue(UploadStatusCode::pendingUpload()->isPendingUpload());
        self::assertTrue(UploadStatusCode::deleted()->isDeleted());
    }

    public function test_upload_status_rejects_unknown(): void
    {
        $this->expectException(InvalidProductValue::class);
        UploadStatusCode::fromString('processing');
    }

    public function test_scan_status_codes_round_trip(): void
    {
        self::assertSame(
            ['not_required', 'pending_upload', 'pending_scan', 'clean', 'rejected', 'failed', 'skipped'],
            ScanStatusCode::allowedCodes(),
        );
        self::assertTrue(ScanStatusCode::fromString('CLEAN')->isClean());
        self::assertTrue(ScanStatusCode::pendingScan()->isPendingScan());
    }

    public function test_scan_status_rejects_unknown(): void
    {
        $this->expectException(InvalidProductValue::class);
        ScanStatusCode::fromString('quarantined');
    }

    public function test_product_file_kind_includes_360_frame_code(): void
    {
        self::assertSame('360-frame', ProductFileKind::frame360()->toString());
        self::assertTrue(ProductFileKind::fromString('360-frame')->is360Frame());
        self::assertTrue(ProductFileKind::image()->isImage());
        self::assertContains('ar', ProductFileKind::allowedCodes());
    }

    public function test_product_file_kind_rejects_gltf_as_kind(): void
    {
        $this->expectException(InvalidProductValue::class);
        ProductFileKind::fromString('gltf');
    }
}
