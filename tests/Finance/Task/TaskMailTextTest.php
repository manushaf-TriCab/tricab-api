<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Service\TaskMailText;

/** `mailing.py`'s helpers, ported exactly (T2.6): the text to HTML, the address list, the attachments. */
final class TaskMailTextTest extends TestCase
{
    private const P = '<p style="margin:0 0 1em 0">';

    public function test_paragraphs_and_line_breaks_are_kept(): void
    {
        self::assertSame(
            self::P . 'Hi Una,</p>' . self::P . 'Line one<br>Line two</p>' . self::P . 'Thanks</p>',
            TaskMailText::toHtml("Hi Una,\n\nLine one\nLine two\n\nThanks"),
        );
    }

    public function test_windows_and_old_mac_line_endings_read_the_same_as_unix(): void
    {
        self::assertSame(TaskMailText::toHtml("a\nb\n\nc"), TaskMailText::toHtml("a\r\nb\r\n\r\nc"));
        self::assertSame(TaskMailText::toHtml("a\nb\n\nc"), TaskMailText::toHtml("a\rb\r\rc"));
    }

    public function test_markup_in_the_text_is_escaped_so_a_draft_cannot_inject_html(): void
    {
        self::assertSame(
            self::P . '&lt;script&gt;alert(1)&lt;/script&gt; &amp; &lt;b&gt;bold&lt;/b&gt;</p>',
            TaskMailText::toHtml('<script>alert(1)</script> & <b>bold</b>'),
        );
        self::assertSame(self::P . 'a &amp;amp; b</p>', TaskMailText::toHtml('a &amp; b'), 'an existing entity is escaped, not trusted');
    }

    public function test_a_web_address_becomes_a_link_without_the_full_stop_that_ends_the_sentence(): void
    {
        self::assertSame(
            self::P . 'The task is here: <a href="https://tricab.ai/finance/task?task=ab12">https://tricab.ai/finance/task?task=ab12</a>.<br>'
                . 'List (<a href="http://localhost:5173/finance/task">http://localhost:5173/finance/task</a>)</p>',
            TaskMailText::toHtml("The task is here: https://tricab.ai/finance/task?task=ab12.\nList (http://localhost:5173/finance/task)"),
        );
        self::assertSame(
            self::P . '<a href="https://x.test/?a=1&amp;b=2">https://x.test/?a=1&amp;b=2</a>"</p>',
            TaskMailText::toHtml('https://x.test/?a=1&b=2"'),
            'a quote ends the address, so it cannot break out of the href',
        );
    }

    public function test_quotes_are_left_alone_as_html_escape_with_quote_false_does(): void
    {
        self::assertSame(self::P . 'He said "hi" and it\'s fine</p>', TaskMailText::toHtml('He said "hi" and it\'s fine'));
    }

    public function test_blank_blocks_are_dropped_blocks_are_stripped_and_empty_text_is_empty(): void
    {
        self::assertSame('', TaskMailText::toHtml(''));
        self::assertSame('', TaskMailText::toHtml("  \n\n \u{00A0}\n\n"));
        self::assertSame(self::P . 'a</p>' . self::P . 'b</p>', TaskMailText::toHtml("  a  \n\n\n\n  b \n"));
        self::assertSame(self::P . 'Café — ✓</p>', TaskMailText::toHtml('Café — ✓'));
    }

    /** @return iterable<string, array{mixed, list<string>}> */
    public static function addresses(): iterable
    {
        yield 'comma string' => ['A@X.com, b@y.com', ['a@x.com', 'b@y.com']];
        yield 'semicolons too' => ['a@x.com;b@y.com , c@z.com', ['a@x.com', 'b@y.com', 'c@z.com']];
        yield 'a list' => [[' A@X.com ', 'b@y.com'], ['a@x.com', 'b@y.com']];
        yield 'blanks dropped' => ['a@x.com,, ,b@y.com,', ['a@x.com', 'b@y.com']];
        yield 'empty string' => ['', []];
        yield 'null' => [null, []];
        yield 'empty list' => [[], []];
        yield 'a number' => [5, []];
        yield 'duplicates kept for the caller to see' => ['a@x.com, a@x.com', ['a@x.com', 'a@x.com']];
    }

    /** @param list<string> $expected */
    #[DataProvider('addresses')]
    public function test_an_address_list(mixed $value, array $expected): void
    {
        self::assertSame($expected, TaskMailText::addressList($value));
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function file(array $override = []): array
    {
        return $override + ['name' => 'a.pdf', 'content_type' => 'application/pdf', 'data' => base64_encode('PDF-bytes')];
    }

    public function test_attachments_decode_to_name_type_and_raw_bytes(): void
    {
        $files = TaskMailText::attachments([self::file(), self::file(['name' => ' b.txt ', 'content_type' => '', 'data' => base64_encode('hello')])]);

        self::assertCount(2, $files);
        self::assertSame('a.pdf', $files[0]->name);
        self::assertSame('PDF-bytes', $files[0]->content);
        self::assertSame('application/pdf', $files[0]->contentType);
        self::assertSame('b.txt', $files[1]->name);
        self::assertSame('application/octet-stream', $files[1]->contentType);
        self::assertSame([], TaskMailText::attachments(null));
        self::assertSame([], TaskMailText::attachments([]));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function badAttachments(): iterable
    {
        yield 'not a list' => ['x', 'Attachments must be a list'];
        yield 'an object' => [['name' => 'a'], 'Attachments must be a list'];
        yield 'eleven files' => [array_fill(0, 11, ['name' => 'a.txt', 'data' => 'YQ==']), 'Attach at most 10 files'];
        yield 'item not an object' => [['a.txt'], 'Each attachment must be an object'];
        yield 'no name' => [[['data' => 'YQ==']], 'Every attachment needs a file name'];
        yield 'blank name' => [[['name' => '  ', 'data' => 'YQ==']], 'Every attachment needs a file name'];
        yield 'slash in name' => [[['name' => 'a/b.txt', 'data' => 'YQ==']], 'a/b.txt is not a usable file name'];
        yield 'backslash in name' => [[['name' => 'a\\b.txt', 'data' => 'YQ==']], 'a\\b.txt is not a usable file name'];
        yield 'long name' => [[['name' => str_repeat('n', 256), 'data' => 'YQ==']], str_repeat('n', 60) . ' is not a usable file name'];
        yield 'bad base64' => [[['name' => 'a.txt', 'data' => '!!not base64!!']], 'a.txt could not be read'];
        yield 'empty data' => [[['name' => 'a.txt', 'data' => '']], 'a.txt is empty'];
        yield 'missing data' => [[['name' => 'a.txt']], 'a.txt is empty'];
        yield 'over 3 MB' => [[['name' => 'big.bin', 'data' => base64_encode(str_repeat('x', 3 * 1024 * 1024 + 1))]], 'Attachments come to more than 3 MB in total. Attach fewer or smaller files.'];
    }

    #[DataProvider('badAttachments')]
    public function test_a_bad_attachment_is_refused_with_the_sources_message(mixed $items, string $message): void
    {
        try {
            TaskMailText::attachments($items);
            self::fail('Expected a refusal.');
        } catch (TaskInvalid $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    public function test_exactly_three_megabytes_in_total_is_allowed_and_the_total_is_across_files(): void
    {
        $half = str_repeat('x', 1_572_864);
        self::assertCount(2, TaskMailText::attachments([self::file(['data' => base64_encode($half)]), self::file(['name' => 'b.pdf', 'data' => base64_encode($half)])]));
        $this->expectException(TaskInvalid::class);
        TaskMailText::attachments([self::file(['data' => base64_encode($half)]), self::file(['name' => 'b.pdf', 'data' => base64_encode($half . 'x')])]);
    }
}
