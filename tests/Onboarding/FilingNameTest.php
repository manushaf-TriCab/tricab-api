<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Onboarding\Document\TemplatePdf;
use TriCab\Core\Onboarding\Application\DTO\OnboardingDocumentRef;
use TriCab\Core\Onboarding\Domain\Service\FilingName;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * HR's filing name, `YY.MM.DD SURNAME Given - Document name`. The first three
 * cases are the source app's own (test/api.test.mjs), so both systems name a
 * file the same way.
 */
final class FilingNameTest extends TestCase
{
    public function test_it_dates_by_the_melbourne_calendar_day(): void
    {
        self::assertSame(
            "26.08.14 O'BRIEN Elodie - Employee Details Form.pdf",
            FilingName::build("Elodie O'Brien", '2026-08-13T23:30:00.000Z', 'Employee Details Form', 'pdf'),
        );
    }

    public function test_the_last_word_is_the_surname_and_the_rest_are_given_names(): void
    {
        self::assertSame(
            '26.09.01 WATSON Mary Jane - Corporate and WHSE Induction Assessment.pdf',
            FilingName::build('  Mary  Jane Watson ', '2026-09-01T02:00:00', 'Corporate and WHSE Induction Assessment', 'pdf'),
        );
    }

    public function test_characters_a_filesystem_or_header_would_trip_on_become_spaces(): void
    {
        self::assertSame(
            '26.09.01 LEE Sam - Policy A B draft.pdf',
            FilingName::build('Sam Lee', '2026-09-01T02:00:00Z', 'Policy: A/B "draft"?', 'pdf'),
        );
        self::assertSame(
            '26.09.01 LEE Sam - Split.pdf',
            FilingName::build("Sam\r\nLee", '2026-09-01T02:00:00Z', "Split\r\n", 'pdf'),
        );
    }

    public function test_a_missing_part_is_left_out(): void
    {
        self::assertSame('LEE Sam - Photo.jpg', FilingName::build('Sam Lee', null, 'Photo', 'jpg'));
        self::assertSame('26.09.01 - Photo.jpg', FilingName::build(null, '2026-09-01T02:00:00Z', 'Photo', 'jpg'));
        self::assertSame('TriCab record.pdf', FilingName::build(null, 'not a date', null, 'pdf'));
    }

    public function test_the_download_is_dated_by_completion_and_the_header_carries_the_utf8_name(): void
    {
        $document = new OnboardingDocumentRef(
            publicId: '8044a61d-3cb9-f111-a6a9-000d3ae035f9',
            ownerUserId: 1,
            documentKind: 'assessment',
            dataClass: DataClass::generatedDocument(),
            blobContainer: 'onboarding',
            blobPath: 'people/1/attempt-765aa2b2.pdf',
            contentType: 'application/pdf',
            byteSize: 1,
            contentSha256: str_repeat('0', 64),
            generatedAtUtc: '2026-09-27T01:00:00.0000000',
            personName: 'Zoë Müller',
            documentTitle: 'Corporate and WHSE Induction Assessment',
            completedAtUtc: '2026-09-25T10:06:07.2897636',
        );

        self::assertSame('26.09.25 MÜLLER Zoë - Corporate and WHSE Induction Assessment.pdf', $document->downloadFilename());
        self::assertSame(
            'attachment; filename="26.09.25 M_LLER Zo_ - Corporate and WHSE Induction Assessment.pdf"; '
                . "filename*=UTF-8''26.09.25%20M%C3%9CLLER%20Zo%C3%AB%20-%20Corporate%20and%20WHSE%20Induction%20Assessment.pdf",
            $document->contentDisposition(),
        );
    }

    /** The sheet's printed date and the file name agree: both are Melbourne days. */
    public function test_a_sheet_is_dated_by_the_melbourne_day_not_the_utc_one(): void
    {
        self::assertSame('26/09/2026', TemplatePdf::localDate('2026-09-25T23:52:08.2407715'));
        self::assertSame('25/09/2026', TemplatePdf::localDate('2026-09-25T10:00:00Z'));
    }
}
