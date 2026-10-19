<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Mail\Domain\Service\MailHtmlCleaner;

final class MailHtmlCleanerTest extends TestCase
{
    public function test_scripts_frames_forms_and_event_handlers_are_gone(): void
    {
        $out = (new MailHtmlCleaner())->cleanHtml(
            '<p onclick="steal()" onmouseover=x>Hello <b>there</b></p><script>alert(1)</script><iframe src="https://evil.example"></iframe>'
            . '<form action="https://evil.example"><input name="p"></form><svg onload=x><circle/></svg><style>p{position:fixed}</style>',
        );

        self::assertSame('<p>Hello <b>there</b></p>', $out->html);
        self::assertFalse($out->remoteImagesBlocked);
    }

    public function test_remote_images_are_replaced_by_their_alt_text_and_reported(): void
    {
        $out = (new MailHtmlCleaner())->cleanHtml('<p>Logo: <img src="https://tracker.example/p.gif?u=1" alt="TriCab"></p><img src="//cdn.example/x.png">');

        self::assertTrue($out->remoteImagesBlocked);
        self::assertStringNotContainsString('tracker.example', $out->html);
        self::assertStringNotContainsString('cdn.example', $out->html);
        self::assertStringNotContainsString('<img', $out->html);
        self::assertStringContainsString('<span>TriCab</span>', $out->html);
    }

    public function test_a_small_embedded_picture_stays_and_a_cid_picture_does_not(): void
    {
        $out = (new MailHtmlCleaner())->cleanHtml('<img src="data:image/png;base64,iVBORw0KGgo=" onerror="x()" width="40"><img src="cid:image001.png">');

        self::assertStringContainsString('<img src="data:image/png;base64,iVBORw0KGgo="', $out->html);
        self::assertStringNotContainsString('onerror', $out->html);
        self::assertStringNotContainsString('cid:', $out->html);
        self::assertFalse($out->remoteImagesBlocked, 'a cid picture is not a remote load');
    }

    public function test_only_safe_link_schemes_survive_and_links_open_safely(): void
    {
        $out = (new MailHtmlCleaner())->cleanHtml(
            '<a href="https://ok.example/a">ok</a><a href="mailto:a@b.example">m</a><a href="java&#9;script:alert(1)">bad</a>'
            . '<a href="javascript:alert(1)">bad2</a><a href="data:text/html;base64,AAAA">bad3</a><a href="/relative">rel</a>',
        );

        self::assertStringContainsString('<a href="https://ok.example/a" target="_blank" rel="noopener noreferrer nofollow">ok</a>', $out->html);
        self::assertStringContainsString('href="mailto:a@b.example"', $out->html);
        self::assertStringNotContainsString('javascript', strtolower($out->html));
        self::assertStringNotContainsString('data:text', $out->html);
        self::assertSame(2, substr_count($out->html, 'href='));
    }

    public function test_inline_style_keeps_presentation_and_drops_anything_that_loads_or_runs(): void
    {
        $out = (new MailHtmlCleaner())->cleanHtml(
            '<p style="color: #c00; position: fixed; background-color: red; width: expression(alert(1)); margin: 0 !important">x</p>'
            . '<p style="background: url(https://t.example/p.png)">y</p>',
        );

        self::assertStringContainsString('style="color: #c00; background-color: red; margin: 0"', $out->html);
        self::assertStringNotContainsString('position', $out->html);
        self::assertStringNotContainsString('url(', $out->html);
        self::assertTrue($out->remoteImagesBlocked, 'a url() in a style is a remote load');
    }

    public function test_class_and_id_are_dropped_so_the_page_cannot_be_clobbered(): void
    {
        $out = (new MailHtmlCleaner())->cleanHtml('<div id="root" class="admin-shell" name="body"><table cellpadding="2" bgcolor="#eee"><tr><td colspan="2" onclick="x">cell</td></tr></table></div>');

        self::assertStringNotContainsString('id=', $out->html);
        self::assertStringNotContainsString('class=', $out->html);
        self::assertStringContainsString('<table cellpadding="2" bgcolor="#eee">', $out->html);
        self::assertStringContainsString('<td colspan="2">cell</td>', $out->html);
    }

    public function test_an_unlisted_tag_is_unwrapped_and_its_text_kept(): void
    {
        $out = (new MailHtmlCleaner())->cleanHtml('<custom-tag>kept <o:p>word-junk</o:p></custom-tag><marquee>scroll</marquee>');

        self::assertSame('kept scroll', $out->html);
    }

    public function test_outlook_reply_history_is_split_off(): void
    {
        $out = (new MailHtmlCleaner())->cleanHtml(
            '<div>Please quote this.</div><div id="appendonsend"></div><hr><div id="divRplyFwdMsg"><b>From:</b> Sam</div><div>Earlier message</div>',
        );

        self::assertSame('<div>Please quote this.</div>', $out->html);
        self::assertNotNull($out->quotedHtml);
        self::assertStringContainsString('Earlier message', $out->quotedHtml);
        self::assertStringContainsString('<hr>', $out->quotedHtml);
    }

    public function test_a_trailing_quote_block_is_split_but_an_interleaved_reply_is_not(): void
    {
        $cleaner = new MailHtmlCleaner();

        $trailing = $cleaner->cleanHtml('<p>Yes, go ahead.</p><div class="gmail_quote"><blockquote>old words</blockquote></div>');
        self::assertSame('<p>Yes, go ahead.</p>', $trailing->html);
        self::assertStringContainsString('old words', (string) $trailing->quotedHtml);

        $interleaved = $cleaner->cleanHtml('<blockquote>Can you do 100m?</blockquote><p>Yes, 100m is fine.</p><blockquote>And 20 lugs?</blockquote><p>Yes.</p>');
        self::assertNull($interleaved->quotedHtml);
        self::assertStringContainsString('Yes, 100m is fine.', $interleaved->html);
        self::assertStringContainsString('Yes.', $interleaved->html);
    }

    public function test_a_body_that_is_all_quote_is_not_split_away(): void
    {
        $out = (new MailHtmlCleaner())->cleanHtml('<blockquote>forwarded text only</blockquote>');

        self::assertNull($out->quotedHtml);
        self::assertStringContainsString('forwarded text only', $out->html);
    }

    public function test_a_plain_text_body_is_escaped_and_its_quoted_tail_split(): void
    {
        $out = (new MailHtmlCleaner())->fromText("Thanks <b>team</b>\r\n\r\nOn Mon, 12 Oct 2026 at 9:00 AM, Jo <jo@customer.example> wrote:\r\n> please quote");

        self::assertSame('<div>Thanks &lt;b&gt;team&lt;/b&gt;</div>', $out->html);
        self::assertNotNull($out->quotedHtml);
        self::assertStringContainsString('&gt; please quote', $out->quotedHtml);
        self::assertStringNotContainsString('<b>', $out->html);
    }

    public function test_an_empty_or_unparsable_body_gives_an_empty_result(): void
    {
        $out = (new MailHtmlCleaner())->cleanHtml('');

        self::assertSame('', $out->html);
        self::assertNull($out->quotedHtml);
    }
}
