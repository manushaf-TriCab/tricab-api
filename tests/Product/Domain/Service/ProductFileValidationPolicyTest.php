<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Domain\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Domain\Exception\InvalidProductFileException;
use TriCab\Core\Product\Domain\Service\ProductFileValidationPolicy;
use TriCab\Core\Product\Domain\ValueObject\ProductFileKind;

final class ProductFileValidationPolicyTest extends TestCase
{
    private ProductFileValidationPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ProductFileValidationPolicy();
    }

    public function test_validate_image_accepts_png(): void
    {
        $result = $this->policy->validateImage('Hero.PNG', 'image/png', 1024);
        self::assertSame('Hero.PNG', $result['safeFileName']);
        self::assertSame('png', $result['extension']);
        self::assertSame('image/png', $result['contentType']);
        self::assertSame(ProductFileKind::IMAGE, $result['fileKind']);
    }

    public function test_validate_image_rejects_bad_mime(): void
    {
        $this->expectException(InvalidProductFileException::class);
        $this->policy->validateImage('hero.png', 'image/gif', 1024);
    }

    public function test_validate_image_rejects_incompatible_ext_mime(): void
    {
        $this->expectException(InvalidProductFileException::class);
        $this->policy->validateImage('hero.png', 'image/jpeg', 1024);
    }

    public function test_validate_image_rejects_oversize(): void
    {
        $this->expectException(InvalidProductFileException::class);
        $this->policy->validateImage('hero.jpg', 'image/jpeg', 21 * 1024 * 1024);
    }

    public function test_validate_document_accepts_pdf(): void
    {
        $result = $this->policy->validateDocument('spec.PDF', 'application/pdf', 1000);
        self::assertSame('pdf', $result['extension']);
        self::assertSame(ProductFileKind::DOCUMENT, $result['fileKind']);
    }

    public function test_validate_document_rejects_docx(): void
    {
        $this->expectException(InvalidProductFileException::class);
        $this->policy->validateDocument('spec.docx', 'application/pdf', 1000);
    }

    public function test_validate_cad_allows_octet_stream(): void
    {
        $result = $this->policy->validateCad('part.step', 'application/octet-stream', 5000);
        self::assertSame('step', $result['extension']);
        self::assertSame(ProductFileKind::CAD, $result['fileKind']);
    }

    public function test_validate_ar_accepts_glb_rejects_gltf(): void
    {
        $ok = $this->policy->validateAr('model.glb', 'model/gltf-binary', 2000);
        self::assertSame('glb', $ok['extension']);

        $this->expectException(InvalidProductFileException::class);
        $this->policy->validateAr('model.gltf', 'model/gltf+json', 2000);
    }

    public function test_validate_360_frame_same_as_image_limits(): void
    {
        $result = $this->policy->validate360Frame('frame-01.webp', 'image/webp', 2048);
        self::assertSame(ProductFileKind::FRAME_360, $result['fileKind']);
    }

    public function test_sanitize_strips_path_components(): void
    {
        $safe = $this->policy->sanitizeOriginalFileName('../../etc/passwd.jpg');
        self::assertSame('passwd.jpg', $safe);
        self::assertSame('jpg', $this->policy->normalizeExtension($safe));
    }
}
