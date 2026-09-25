<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use setasign\Fpdi\Fpdi;
use TriCab\Api\Onboarding\Document\TemplateOnboardingFormDocumentRenderer;
use TriCab\Api\Onboarding\FormImport\VehicleReportFormDefinition;
use TriCab\Core\Onboarding\Application\DTO\Document\FormSubmissionForDocument;
use TriCab\Core\Onboarding\Domain\Service\FormSchemaRules;

/** Phase 5.4 — the vehicle report. Checked by eye against HR-FOR-024-V002 on 24 Sep 2026. */
#[RequiresPhpExtension('gd')]
final class VehicleReportDocumentTest extends TestCase
{
    public function test_hr_inspects_with_photos_and_the_starter_acknowledges_as_driver(): void
    {
        $schema = FormSchemaRules::normalise(VehicleReportFormDefinition::definition()['fields'], 'hr');
        $roles = array_count_values(array_map(static fn (array $f): string => FormSchemaRules::filledBy($f), $schema['fields']));

        self::assertSame(['hr' => 16, 'starter' => 6], $roles);
    }

    public function test_each_photo_gets_its_own_page_and_only_this_persons_photos_are_read(): void
    {
        $asked = [];
        $renderer = TemplateOnboardingFormDocumentRenderer::withTriCabTemplates(dirname(__DIR__, 2), static function (int $userId, string $id) use (&$asked): string {
            $asked[] = [$userId, $id];
            $image = imagecreatetruecolor(800, 600);
            ob_start();
            imagejpeg($image);

            return (string) ob_get_clean();
        });

        $document = $renderer->render(
            new FormSubmissionForDocument(1, 1, 55, VehicleReportFormDefinition::SLUG, 'hr', 1, '2026-10-07T01:00:00Z', 'Sam'),
            ['registration' => '1AB2CD', 'photos' => 'aaaaaaaa-0000-4000-8000-000000000001,aaaaaaaa-0000-4000-8000-000000000002'],
            'completion',
        );

        self::assertNotNull($document);
        self::assertSame([55, 55], array_column($asked, 0), 'photos are looked up as the form\'s own person\'s');
        $file = tempnam(sys_get_temp_dir(), 'pdf');
        self::assertIsString($file);
        file_put_contents($file, $document->bytes);
        self::assertSame(4, (new Fpdi())->setSourceFile($file), 'two template pages and two evidence pages');
        unlink($file);
    }
}
