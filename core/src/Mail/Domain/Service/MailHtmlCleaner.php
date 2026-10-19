<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Domain\Service;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Masterminds\HTML5;
use TriCab\Core\Mail\Application\DTO\CleanedHtml;

/**
 * Cleans an email body on the server before it leaves it (plan 2.4). The browser sanitises it again
 * with DOMPurify and shows it in an inert viewer; this is the first layer, so it is conservative:
 * a parser-based allowlist, not a pattern strip.
 *
 *  - The body is parsed as HTML5 (masterminds/html5, the parser dompdf already brings) and rebuilt
 *    from an allowlist of tags and attributes. A tag that is not listed is unwrapped (its text stays);
 *    scripts, styles, frames, forms, SVG and the like are removed with their content.
 *  - `class` and `id` are dropped (the page's own scripts and styles must not be reachable by them),
 *    as is every event handler. Inline `style` keeps a short list of presentational properties and
 *    refuses any value that can load or run something.
 *  - Links keep `http`, `https`, `mailto` and `tel` only, and open in a new tab with `noopener`.
 *  - **Remote images are blocked.** An `<img>` pointing at a web address is replaced by its alt text, and
 *    `remoteImagesBlocked` says so, because a tracking pixel tells the sender who opened the mail. Only
 *    small embedded `data:image` pictures stay; `cid:` pictures (inline attachments) are not served.
 *  - Quoted history (Outlook's reply header, Gmail's and Apple's quote blocks) is split off into
 *    `quotedHtml` so the reader can collapse it, but only where nothing else follows it, so no
 *    interleaved reply is ever hidden.
 */
final class MailHtmlCleaner
{
    private const MAX_INPUT_BYTES = 3_000_000;

    /** Removed together with everything inside them. */
    private const DROP = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button',
        'select', 'option', 'textarea', 'link', 'meta', 'base', 'svg', 'math', 'template', 'noscript', 'head',
        'title', 'video', 'audio', 'source', 'track', 'canvas', 'map', 'area', 'dialog', 'portal', 'slot',
        'xml', 'o:p',
    ];

    private const ALLOWED = [
        'a', 'abbr', 'b', 'big', 'blockquote', 'br', 'caption', 'center', 'cite', 'code', 'col', 'colgroup', 'dd', 'div',
        'dl', 'dt', 'em', 'font', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'img', 'li', 'ol', 'p', 'pre', 'q', 's',
        'small', 'span', 'strike', 'strong', 'sub', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'u', 'ul',
    ];

    /** Attributes any allowed tag may keep (with their value checks below). */
    private const ATTRIBUTES = [
        'title', 'dir', 'lang', 'align', 'valign', 'width', 'height', 'bgcolor', 'colspan', 'rowspan',
        'cellpadding', 'cellspacing', 'border', 'color', 'size', 'face', 'style',
    ];

    private const STYLE_PROPERTIES = [
        'color', 'background-color', 'font', 'font-family', 'font-size', 'font-style', 'font-weight', 'line-height',
        'text-align', 'text-decoration', 'text-indent', 'vertical-align', 'white-space', 'direction', 'letter-spacing',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-collapse', 'border-spacing',
        'border-color', 'border-style', 'border-width', 'width', 'height', 'max-width', 'list-style-type',
    ];

    private int $remote = 0;

    public function cleanHtml(string $html): CleanedHtml
    {
        $this->remote = 0;
        if (strlen($html) > self::MAX_INPUT_BYTES) {
            $html = substr($html, 0, self::MAX_INPUT_BYTES);
        }
        $parser = new HTML5(['disable_html_ns' => true]);
        // Wrapped so a bare fragment still gets a body; a full document's own html/body tags are ignored inside it.
        $doc = $parser->loadHTML('<!DOCTYPE html><html><body>' . $html);
        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body instanceof DOMElement) {
            return new CleanedHtml('', false, null);
        }

        $quote = $this->splitQuoted($doc, $body);
        $this->clean($body);
        $main = $parser->saveHTML($body->childNodes);
        $quoted = null;
        if ($quote !== null) {
            $this->clean($quote);
            $quoted = $parser->saveHTML($quote->childNodes);
        }

        return new CleanedHtml(trim($main), $this->remote > 0, $quoted === null || trim($quoted) === '' ? null : trim($quoted));
    }

    /** A plain-text body: escaped, line breaks kept, and the quoted tail split off. */
    public function fromText(string $text): CleanedHtml
    {
        $text = str_replace(["\r\n", "\r"], "\n", substr($text, 0, self::MAX_INPUT_BYTES));
        $lines = explode("\n", $text);
        $cut = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(On .{5,200} wrote:|-{3,}\s*Original Message\s*-{3,}|_{10,})\s*$/i', $line) === 1) {
                $cut = $i;
                break;
            }
        }
        $main = $lines;
        $quoted = null;
        if ($cut !== null && trim(implode("\n", array_slice($lines, 0, $cut))) !== '') {
            $main = array_slice($lines, 0, $cut);
            $quoted = array_slice($lines, $cut);
        }

        return new CleanedHtml(self::paragraph($main), false, $quoted === null ? null : self::paragraph($quoted));
    }

    /** @param list<string> $lines */
    private static function paragraph(array $lines): string
    {
        return '<div>' . nl2br(htmlspecialchars(trim(implode("\n", $lines)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</div>';
    }

    // --- quoted history -------------------------------------------------------------------------

    /** Moves the quoted tail out of $body into a detached `div` and returns it, or null if there is none to split. */
    private function splitQuoted(DOMDocument $doc, DOMElement $body): ?DOMElement
    {
        $marker = null;
        $strong = false;
        foreach ($body->getElementsByTagName('*') as $el) {
            $id = strtolower($el->getAttribute('id'));
            $class = ' ' . strtolower($el->getAttribute('class')) . ' ';
            if ($id === 'divrplyfwdmsg' || $id === 'appendonsend') {
                $marker = $el;
                $strong = true;
                break;
            }
            if (str_contains($class, ' gmail_quote ') || str_contains($class, ' yahoo_quoted ')
                || ($el->tagName === 'blockquote')) {
                $marker = $el;
                break;
            }
        }
        if (!$marker instanceof DOMElement) {
            return null;
        }

        $moved = [];
        $previous = self::previousElementSibling($marker);
        if ($strong && $previous !== null && $previous->tagName === 'hr') {
            $moved[] = $previous;
        }
        $moved[] = $marker;
        for ($n = $marker->nextSibling; $n !== null; $n = $n->nextSibling) {
            $moved[] = $n;
        }
        for ($p = $marker->parentNode; $p !== null && $p !== $body; $p = $p->parentNode) {
            for ($n = $p->nextSibling; $n !== null; $n = $n->nextSibling) {
                $moved[] = $n;
            }
        }

        // A weak marker (a plain quote block) splits only a trailing quote: anything after it is read, not hidden.
        if (!$strong) {
            foreach ($moved as $node) {
                if ($node !== $marker && !self::blank($node)) {
                    return null;
                }
            }
        }
        // And never split everything away: the person must be left with something to read.
        $movedText = 0;
        foreach ($moved as $node) {
            $movedText += strlen((string) preg_replace('/\s+/u', '', $node->textContent));
        }
        if (strlen((string) preg_replace('/\s+/u', '', $body->textContent)) <= $movedText) {
            return null;
        }

        $quote = $doc->createElement('div');
        foreach ($moved as $node) {
            $quote->appendChild($node);
        }

        return $quote;
    }

    private static function previousElementSibling(DOMNode $node): ?DOMElement
    {
        for ($n = $node->previousSibling; $n !== null; $n = $n->previousSibling) {
            if ($n instanceof DOMElement) {
                return $n;
            }
            if (!($n instanceof DOMText) || trim($n->wholeText) !== '') {
                return null;
            }
        }

        return null;
    }

    private static function blank(DOMNode $node): bool
    {
        if ($node instanceof DOMText || $node instanceof DOMComment) {
            return trim($node->textContent) === '';
        }

        return $node instanceof DOMElement && $node->tagName === 'br';
    }

    // --- the allowlist --------------------------------------------------------------------------

    private function clean(DOMNode $parent): void
    {
        $children = [];
        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            if ($child instanceof DOMText) {
                continue;
            }
            if (!$child instanceof DOMElement) {
                $parent->removeChild($child); // comments, processing instructions, CDATA
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true) || str_contains($tag, ':')) {
                $parent->removeChild($child);
                continue;
            }
            $this->clean($child);
            if ($tag === 'img') {
                $this->image($parent, $child);
                continue;
            }
            if (!in_array($tag, self::ALLOWED, true)) {
                while ($child->firstChild !== null) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);
                continue;
            }
            $this->attributes($child, $tag);
        }
    }

    private function image(DOMNode $parent, DOMElement $img): void
    {
        $src = trim($img->getAttribute('src'));
        $doc = $img->ownerDocument;
        if ($doc !== null && strlen($src) <= 400000
            && preg_match('#^data:image/(?:png|gif|jpe?g|webp|bmp);base64,[A-Za-z0-9+/=\s]+$#i', $src) === 1) {
            // A fresh element, so nothing the sender put on the original can come along.
            $kept = $doc->createElement('img');
            $kept->setAttribute('src', $src);
            $kept->setAttribute('alt', mb_substr($img->getAttribute('alt'), 0, 300));
            foreach (['width', 'height'] as $dimension) {
                $size = self::size($img->getAttribute($dimension));
                if ($size !== null) {
                    $kept->setAttribute($dimension, $size);
                }
            }
            $parent->replaceChild($kept, $img);

            return;
        }
        if (preg_match('#^(?:https?:)?//#i', $src) === 1) {
            ++$this->remote;
        }
        // What the picture said, in its place: a blocked image should not leave a hole with no explanation.
        $replacement = $img->ownerDocument?->createElement('span');
        if ($replacement !== null) {
            $alt = trim($img->getAttribute('alt'));
            if ($alt !== '') {
                $replacement->appendChild($img->ownerDocument->createTextNode($alt));
            }
            $parent->replaceChild($replacement, $img);
        } else {
            $parent->removeChild($img);
        }
    }

    private function attributes(DOMElement $el, string $tag): void
    {
        $names = [];
        foreach ($el->attributes ?? [] as $attribute) {
            $names[] = $attribute->nodeName;
        }
        foreach ($names as $name) {
            $lower = strtolower($name);
            $value = $el->getAttribute($name);
            $el->removeAttribute($name);
            if (!in_array($lower, self::ATTRIBUTES, true) && !($tag === 'a' && $lower === 'href')) {
                continue;
            }
            $kept = match ($lower) {
                'style' => $this->style($value),
                'href' => self::href($value),
                'width', 'height', 'cellpadding', 'cellspacing', 'border', 'colspan', 'rowspan', 'size' => self::size($value),
                'bgcolor', 'color' => preg_match('/^#?[0-9a-z]{3,20}$/i', trim($value)) === 1 ? trim($value) : null,
                'align', 'valign', 'dir' => preg_match('/^[a-z]{1,10}$/i', trim($value)) === 1 ? strtolower(trim($value)) : null,
                'lang' => preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/i', trim($value)) === 1 ? trim($value) : null,
                'face' => preg_match('/^[\w ,\'"-]{1,100}$/u', $value) === 1 ? $value : null,
                default => mb_substr($value, 0, 300),
            };
            if ($kept !== null && $kept !== '') {
                $el->setAttribute($lower, (string) $kept);
            }
        }
        if ($tag === 'a' && $el->hasAttribute('href')) {
            $el->setAttribute('target', '_blank');
            $el->setAttribute('rel', 'noopener noreferrer nofollow');
        }
    }

    private static function size(string $value): ?string
    {
        return preg_match('/^\s*(\d{1,4}%?)(?:px)?\s*$/', $value, $m) === 1 ? $m[1] : null;
    }

    private static function href(string $value): ?string
    {
        // Control characters and spaces inside the scheme are how `java\tscript:` gets through.
        $clean = (string) preg_replace('/[\x00-\x20\x7f]+/', '', $value);

        return preg_match('#^(?:https?://|mailto:|tel:)#i', $clean) === 1 ? mb_substr($clean, 0, 2000) : null;
    }

    private function style(string $value): ?string
    {
        $kept = [];
        foreach (explode(';', $value) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $property = strtolower(trim($parts[0]));
            $v = trim((string) preg_replace('/\s*!important\s*$/i', '', $parts[1]));
            // Whatever the property, a url() is a load the reader did not ask for.
            if (preg_match('#url\s*\(#i', $v) === 1) {
                ++$this->remote;
                continue;
            }
            if (!in_array($property, self::STYLE_PROPERTIES, true) || $v === '' || strlen($v) > 200) {
                continue;
            }
            if (preg_match('/expression|javascript|@import|behaviou?r|binding|[<>\\\\{}]/i', $v) === 1) {
                continue;
            }
            $kept[] = $property . ': ' . $v;
        }

        return $kept === [] ? null : implode('; ', $kept);
    }
}
