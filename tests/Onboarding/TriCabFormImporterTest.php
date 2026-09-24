<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Onboarding\FormImport\TriCabFormImporter;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Onboarding\Application\DTO\Authoring\AuthoredContent;
use TriCab\Core\Onboarding\Application\Service\AuthorOnboardingContentService;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingContentRepository;

/** Phase 5.4 — TriCab's forms arrive as drafts, audited, once. */
final class TriCabFormImporterTest extends TestCase
{
    public function test_the_employee_details_form_arrives_as_an_unpublished_classified_draft_once(): void
    {
        $content = new InMemoryOnboardingContentRepository();
        $audit = new InMemoryOnboardingAdminAuditLog();
        $importer = new TriCabFormImporter($content, new AuthorOnboardingContentService($content, $audit, new UuidV4Generator()));
        $actor = new ActorContext(40, 'hr-public-id', 'hr@example.test');

        $plan = $importer->plan();
        self::assertSame([
            ['employee-details', TriCabFormImporter::CREATE],
            ['whs-medical-questionnaire', TriCabFormImporter::CREATE],
            ['deed-of-confidentiality', TriCabFormImporter::CREATE],
        ], array_map(static fn ($s) => [$s['slug'], $s['action']], $plan));

        $results = $importer->apply($plan, $actor);
        self::assertSame(array_fill(0, 3, 'created; draft saved, not published'), array_column($results, 'result'));

        $summary = array_values(array_filter($content->listItems(), static fn ($i) => $i->slug === 'employee-details'))[0];
        $item = $content->findItem(AuthoredContent::TYPE_FORM, $summary->publicId);
        self::assertNotNull($item);
        self::assertSame('payroll', $item->accessGroup);
        $versions = $content->listVersions($item);
        self::assertCount(1, $versions);
        $version = $content->findVersion($item, $versions[0]->publicId);
        self::assertNotNull($version);
        self::assertTrue($version->isDraft(), 'HR publishes it, not the import');
        self::assertCount(44, $version->content->fields);
        self::assertSame(DataClass::PAYROLL, $version->content->classifications['bank1.bsb']);
        self::assertSame(DataClass::RESTRICTED_HR, $version->content->classifications['medicalConditions']);

        $again = $importer->plan();
        self::assertSame(array_fill(0, 3, TriCabFormImporter::EXISTS), array_column($again, 'action'));
        self::assertSame('not touched', $importer->apply($again, $actor)[0]['result']);
        self::assertCount(1, $content->listVersions($item));
    }
}
