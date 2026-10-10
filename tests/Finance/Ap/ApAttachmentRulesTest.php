<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Ap\Service\ApAttachmentRules;
use TriCab\Core\Shared\Mailbox\MailboxAttachmentSummary;

/**
 * Which attachments the scan reads, and under what name (`_attachment_filename`): the six suffixes, the content-type fallback
 * that APPENDS a suffix, the inline and size rules that spare PDFs, and the name made safe once.
 */
final class ApAttachmentRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: int, 3: bool, 4: ?string}>
     */
    public static function cases(): iterable
    {
        yield 'a pdf of any size' => ['a.pdf', 'application/pdf', 3, false, 'a.pdf'];
        yield 'an upper-case suffix is kept as sent' => ['SCAN.PDF', 'application/octet-stream', 3, false, 'SCAN.PDF'];
        yield 'an inline pdf (Apple Mail)' => ['a.pdf', 'application/pdf', 3, true, 'a.pdf'];
        yield 'a png over the floor' => ['a.png', 'image/png', 25600, false, 'a.png'];
        yield 'a png under the floor' => ['a.png', 'image/png', 25599, false, null];
        yield 'an inline png' => ['a.png', 'image/png', 90000, true, null];
        yield 'a jpeg' => ['a.JPEG', 'image/jpeg', 90000, false, 'a.JPEG'];
        yield 'a tiff follows the image rules' => ['a.tif', 'image/tiff', 90000, false, 'a.tif'];
        yield 'a small tiff' => ['a.tiff', 'image/tiff', 10, false, null];
        yield 'a name with no suffix takes the pdf type' => ['Invoice 12345', 'application/pdf', 5, false, 'Invoice 12345.pdf'];
        yield 'the type may carry parameters and capitals' => ['Invoice', 'Application/PDF; name="x"', 5, false, 'Invoice.pdf'];
        yield 'an office name with a pdf type is appended to' => ['report.docx', 'application/pdf', 5, false, 'report.docx.pdf'];
        yield 'image/jpg becomes .jpg' => ['photo', 'image/jpg', 90000, false, 'photo.jpg'];
        yield 'a typed image under the floor' => ['photo', 'image/png', 100, false, null];
        yield 'image/tiff becomes .tiff' => ['scan', 'image/tiff', 90000, false, 'scan.tiff'];
        yield 'a gif is never read' => ['a.gif', 'image/gif', 90000, false, null];
        yield 'a webp is never read' => ['a.webp', 'image/webp', 90000, false, null];
        yield 'an office file' => ['a.xlsx', 'application/vnd.ms-excel', 90000, false, null];
        yield 'no name and a pdf type' => ['', 'application/pdf', 5, false, 'attachment.pdf'];
        yield 'a dot file is a name with no suffix' => ['.pdf', 'application/pdf', 5, false, '.pdf.pdf'];
        yield 'a non-ascii name keeps its suffix' => ['Фактура №5.pdf', 'application/pdf', 5, false, 'Фактура №5.pdf'];
        yield 'a non-ascii name with no suffix gets one' => ['Rechnung Müller', 'application/pdf', 5, false, 'Rechnung Müller.pdf'];
    }

    #[DataProvider('cases')]
    public function test_the_selection_and_the_name(string $name, string $type, int $size, bool $inline, ?string $expected): void
    {
        self::assertSame($expected, ApAttachmentRules::nameFor(new MailboxAttachmentSummary('id', $name, $type, $size, $inline)));
    }

    public function test_the_floor_is_a_setting(): void
    {
        $small = new MailboxAttachmentSummary('id', 'a.png', 'image/png', 1000, false);

        self::assertNull(ApAttachmentRules::nameFor($small));
        self::assertSame('a.png', ApAttachmentRules::nameFor($small, 1000));
    }

    public function test_a_name_is_made_safe_once_and_a_long_one_is_cut_in_its_stem(): void
    {
        $long = str_repeat('é', 300) . '.pdf';

        $name = ApAttachmentRules::nameFor(new MailboxAttachmentSummary('id', $long, 'application/pdf', 5));

        self::assertSame(255, mb_strlen((string) $name));
        self::assertStringEndsWith('.pdf', (string) $name);
        self::assertSame("a b.pdf", ApAttachmentRules::nameFor(new MailboxAttachmentSummary('id', "a\tb.pdf", 'application/pdf', 5)));
        $typed = ApAttachmentRules::nameFor(new MailboxAttachmentSummary('id', str_repeat('x', 300), 'image/jpeg', 90000));
        self::assertSame(255, mb_strlen((string) $typed));
        self::assertStringEndsWith('.jpg', (string) $typed);
    }

    public function test_the_content_type_comes_from_the_suffix(): void
    {
        foreach (['a.pdf' => 'application/pdf', 'a.PNG' => 'image/png', 'a.jpg' => 'image/jpeg', 'a.jpeg' => 'image/jpeg', 'a.tif' => 'image/tiff', 'a.tiff' => 'image/tiff', 'x.docx.pdf' => 'application/pdf'] as $name => $type) {
            self::assertSame($type, ApAttachmentRules::contentTypeOf($name), $name);
        }
    }
}
