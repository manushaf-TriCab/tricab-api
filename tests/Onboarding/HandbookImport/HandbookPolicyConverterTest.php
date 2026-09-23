<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding\HandbookImport;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Onboarding\HandbookImport\HandbookPolicyConverter;
use TriCab\Core\Onboarding\Application\DTO\Authoring\AuthoredContent;

final class HandbookPolicyConverterTest extends TestCase
{
    private static function section(string $content): string
    {
        return '<!doctype html><html><body><main><article>'
            . '<header class="policy-hero"><h1>Ignored title</h1></header>'
            . '<div class="policy-content">' . $content . '</div>'
            . '<footer class="policy-footer"><p>Ignored footer</p></footer>'
            . '</article></main></body></html>';
    }

    public function testHeadingsLoseEmphasisAndH4BecomesLevelThree(): void
    {
        $result = HandbookPolicyConverter::convert(self::section(
            '<h2><strong>Purpose</strong></h2><h3>Scope</h3><h4><strong><em>Detail</em></strong></h4>',
        ));

        self::assertSame([
            ['type' => 'heading', 'level' => 2, 'text' => 'Purpose'],
            ['type' => 'heading', 'level' => 3, 'text' => 'Scope'],
            ['type' => 'heading', 'level' => 3, 'text' => 'Detail'],
        ], $result['blocks']);
        self::assertSame(['Detail'], $result['report']['demotedH4']);
        self::assertSame(2, $result['report']['droppedHeadingEmphasis']);
    }

    public function testBoldAndItalicBecomeCodePointRangesBesideTheText(): void
    {
        $result = HandbookPolicyConverter::convert(self::section(
            '<p>“ <strong>Immediate family</strong> ” means <em>spouse</em>, <strong>a</strong> <strong>b</strong>.</p>',
        ));

        self::assertSame([[
            'type' => 'paragraph',
            'text' => '“ Immediate family ” means spouse, a b.',
            'marks' => ['text' => [
                ['start' => 2, 'end' => 18, 'mark' => 'bold'],
                ['start' => 27, 'end' => 33, 'mark' => 'italic'],
                ['start' => 35, 'end' => 38, 'mark' => 'bold'],
            ]],
        ]], $result['blocks']);
        self::assertSame('Immediate family', mb_substr($result['blocks'][0]['text'], 2, 16));
    }

    public function testListsKeepStyleAndStartAndFlattenNestedItems(): void
    {
        $result = HandbookPolicyConverter::convert(self::section(
            '<ol start="3"><li><p>First</p><p>continued</p></li></ol>'
            . '<ul><li><p>Parent</p><ul><li>Child <strong>bold</strong></li></ul></li></ul>',
        ));

        self::assertSame(['type' => 'list', 'style' => 'number', 'start' => 3, 'items' => ['First continued']], $result['blocks'][0]);
        self::assertSame([
            'type' => 'list',
            'style' => 'bullet',
            'start' => 1,
            'items' => ['Parent', '– Child bold'],
            'marks' => ['items.1' => [['start' => 8, 'end' => 12, 'mark' => 'bold']]],
        ], $result['blocks'][1]);
        self::assertSame(['Child bold'], $result['report']['flattenedNestedLists']);
        self::assertSame(1, $result['report']['joinedListParagraphs']);
    }

    public function testLineBreaksInCellsBecomeSpacesAndAlwaysNeverGridsBecomeComparisons(): void
    {
        $result = HandbookPolicyConverter::convert(self::section(
            '<table><thead><tr><th></th><th><strong>Always</strong></th><th></th><th><strong>Never</strong></th></tr></thead>'
            . '<tbody><tr><td>•</td><td>Stop unsafe<br/>work</td><td>•</td><td>Smoke</td></tr>'
            . '<tr><td>•</td><td>Report</td><td>•<br/>Ignore a hazard</td></tr></tbody></table>',
        ));

        self::assertSame([
            'type' => 'comparisonTable',
            'caption' => null,
            'alwaysHeading' => 'Always',
            'neverHeading' => 'Never',
            'rows' => [
                ['always' => ['Stop unsafe work'], 'never' => ['Smoke']],
                ['always' => ['Report'], 'never' => ['Ignore a hazard']],
            ],
        ], $result['blocks'][0]);
        self::assertSame(2, $result['report']['lineBreaksJoined']);
    }

    public function testAnOrdinaryTableKeepsItsHeadersAndCells(): void
    {
        $result = HandbookPolicyConverter::convert(self::section(
            '<table><thead><tr><th>Service</th><th>Phone</th></tr></thead>'
            . '<tbody><tr><td><strong>Lifeline</strong></td><td>13 11 14</td></tr></tbody></table>',
        ));

        self::assertSame([
            'type' => 'table',
            'caption' => null,
            'headers' => ['Service', 'Phone'],
            'rows' => [['Lifeline', '13 11 14']],
            'marks' => ['rows.0.0' => [['start' => 0, 'end' => 8, 'mark' => 'bold']]],
        ], $result['blocks'][0]);
    }

    public function testRepeatedBoldParagraphsAreRunningHeadersAndAreDropped(): void
    {
        $result = HandbookPolicyConverter::convert(self::section(
            '<p><strong>TriCab Leave Policy</strong></p><p>Body.</p>'
            . '<p><strong>TriCab Leave Policy</strong></p><h2>TriCab Leave Policy</h2>'
            . '<p><strong>Said once</strong></p>',
        ));

        self::assertSame(['Body.', 'Said once'], array_column($result['blocks'], 'text'));
        self::assertCount(3, $result['report']['droppedRunningHeaders']);
    }

    public function testTheSignOffSheetBecomesTheDeclarationAndItsFieldsAreSetAside(): void
    {
        $result = HandbookPolicyConverter::convert(self::section(
            '<p>Policy body.</p>'
            . '<aside class="policy-acknowledgement"><h2><strong>Acknowledgement</strong></h2>'
            . '<p>I agree to comply with this policy.</p>'
            . '<ul><li><p>put safety first;</p></li></ul>'
            . '<p>I have attached a copy of my licence.</p>'
            . '<p>Full Name (Print):</p><p>Signature</p>'
            . '<table><thead><tr><th>Full Name</th><th>Date</th></tr></thead><tbody><tr><td></td><td></td></tr></tbody></table>'
            . '</aside>',
        ));

        self::assertSame([['type' => 'paragraph', 'text' => 'Policy body.']], $result['blocks']);
        self::assertSame("I agree to comply with this policy.\n• put safety first;", $result['declaration']);
        self::assertSame('Acknowledgement', $result['report']['declarationTitle']);
        self::assertSame(['Full Name (Print)', 'Signature', 'Full Name', 'Date'], $result['report']['signOffFields']);
        self::assertSame(['I have attached a copy of my licence.'], $result['report']['formItems']);
        self::assertFalse($result['report']['declarationShownAsBody']);
    }

    public function testEverythingAfterATickHeadingIsAFormOptionNotDeclarationWording(): void
    {
        $result = HandbookPolicyConverter::convert(self::section(
            '<p>Body.</p><aside class="policy-acknowledgement"><h3>Declaration</h3>'
            . '<p>As an Executive / Employee (please check) of TriCab, I acknowledge that I will:</p><ul><li>comply with the Code;</li></ul>'
            . '<h4><strong>I declare that (please indicate by ticking the box):</strong></h4>'
            . '<p>I am in a Conflict-of-Interest situation.</p>'
            . '<p>I am not in a Conflict-of-Interest situation.</p>'
            . '<p>I will not knowingly become involved in a Conflict of Interest.</p>'
            . '<p>Name:</p></aside>',
        ));

        self::assertSame("I acknowledge that I will:\n• comply with the Code;", $result['declaration']);
        self::assertSame([
            'As an Executive / Employee (please check) of TriCab',
            'I declare that (please indicate by ticking the box):',
            'I am in a Conflict-of-Interest situation.',
            'I am not in a Conflict-of-Interest situation.',
            'I will not knowingly become involved in a Conflict of Interest.',
        ], $result['report']['formItems']);
        self::assertSame(['Name'], $result['report']['signOffFields']);
    }

    public function testADeclarationWithNoBodyIsAlsoShownAsTheBody(): void
    {
        $result = HandbookPolicyConverter::convert(self::section(
            '<aside class="policy-acknowledgement"><h2>Employee Declaration</h2><p>I acknowledge the handbook.</p>'
            . '<p class="source-fields"><strong>Name</strong> · <strong>Signature</strong></p></aside>',
        ));

        self::assertSame([
            ['type' => 'heading', 'level' => 2, 'text' => 'Employee Declaration'],
            ['type' => 'paragraph', 'text' => 'I acknowledge the handbook.'],
        ], $result['blocks']);
        self::assertSame(['Name', 'Signature'], $result['report']['signOffFields']);
        self::assertTrue($result['report']['declarationShownAsBody']);
    }

    public function testLinksKeepTheirWordsAndAreReported(): void
    {
        $result = HandbookPolicyConverter::convert(self::section('<p>Email <a href="mailto:hr@example.com">HR</a>.</p>'));

        self::assertSame('Email HR.', $result['blocks'][0]['text']);
        self::assertSame([['text' => 'HR', 'href' => 'mailto:hr@example.com']], $result['report']['droppedLinks']);
    }

    public function testWordCoverageCountsWordsSplitByLineBreaks(): void
    {
        $result = HandbookPolicyConverter::convert(self::section('<table><tr><th>A</th></tr><tr><td>one<br>two</td></tr></table>'));

        self::assertSame([], $result['report']['wordCoverage']['missing']);
        self::assertSame(1.0, $result['report']['wordCoverage']['coverage']);
    }

    public function testOutputWithoutMarksIsWhatTheAuthoringRulesStore(): void
    {
        $result = HandbookPolicyConverter::convert(self::section(
            '<h2>Purpose</h2><p><strong>Bold</strong> text</p><ol><li>One</li></ol>'
            . '<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>',
        ));
        $content = ['blocks' => $result['blocks'], 'declaration' => $result['declaration'], 'effectiveFrom' => null];

        $stored = AuthoredContent::fromInput(AuthoredContent::TYPE_POLICY, $content, null)->toArray();

        unset($content['blocks'][1]['marks']);
        self::assertSame($content, $stored);
    }

    public function testASectionWithoutPolicyContentIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        HandbookPolicyConverter::convert('<html><body><p>No content div</p></body></html>');
    }
}
