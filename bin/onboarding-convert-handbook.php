<?php

declare(strict_types=1);

/**
 * Convert the TriCab Employee Handbook's policy sections into platform policy
 * content (handbook policy import, Step 1 — see
 * platform/ONBOARDING-POLICY-IMPORT-PLAN.md).
 *
 * Reads files and writes files. No database, no network, no platform change.
 *
 * For each of the manifest's policies it:
 *   1. checks the section HTML still has the hash the manifest recorded, so
 *      what is converted is the rendition HR approved;
 *   2. converts it with HandbookPolicyConverter;
 *   3. puts the result through AuthoredContent::fromInput — the same parse an
 *      author's save goes through — and checks it came back unchanged, marks
 *      and metadata included;
 *   4. writes <out>/policies/<slug>.json.
 *
 * Then it writes <out>/index.json and <out>/REPORT.md, the page HR reads beside
 * the PDFs. Exits 1 if any policy fails, and writes nothing for that policy.
 *
 * Usage:
 *   php bin/onboarding-convert-handbook.php \
 *     --source=../../hr/onboarding-workflow/tricab-training-vercel/public/policy-library \
 *     --out=../onboarding-policy-import
 */

use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Onboarding\HandbookImport\HandbookPolicyConverter;
use TriCab\Core\Onboarding\Application\DTO\Authoring\AuthoredContent;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

/**
 * Signed in the source, and given a separate signed form on the platform
 * (decision D4, 23 Sep 2026). The form is built in the 4.4 form editor.
 */
const SIGNED_FORM_SLUGS = ['company-vehicle-policy', 'corporate-credit-card-policy', 'employee-declaration'];

/**
 * Review date passed or about to: published anyway, and listed for HR
 * (decision D5, 23 Sep 2026).
 */
const REVIEW_FOR_HR_IDS = [12, 13, 14, 26];

$options = getopt('', ['source:', 'out:']);
$source = is_string($options['source'] ?? null) ? rtrim($options['source'], '/') : null;
$out = is_string($options['out'] ?? null) ? rtrim($options['out'], '/') : null;
if ($source === null || $out === null) {
    fwrite(STDERR, "Usage: php bin/onboarding-convert-handbook.php --source=<policy-library dir> --out=<output dir>\n");
    exit(2);
}

$manifestPath = $source . '/manifest.json';
$manifest = json_decode((string) @file_get_contents($manifestPath), true);
if (!is_array($manifest) || !is_array($manifest['policies'] ?? null)) {
    fwrite(STDERR, "Cannot read {$manifestPath}\n");
    exit(2);
}

$chapters = [];
foreach ($manifest['chapters'] as $chapter) {
    $chapters[$chapter['id']] = $chapter['title'];
}

$commit = trim((string) shell_exec('git -C ' . escapeshellarg($source) . ' rev-parse --short HEAD 2>/dev/null'));
$dirty = trim((string) shell_exec('git -C ' . escapeshellarg($source) . ' status --porcelain -- . 2>/dev/null')) !== '';

if (!is_dir($out . '/policies') && !mkdir($out . '/policies', 0o775, true)) {
    fwrite(STDERR, "Cannot create {$out}/policies\n");
    exit(2);
}

/**
 * Marks must point inside the field they name. Returns problems.
 *
 * @param list<array<string, mixed>> $blocks
 *
 * @return list<string>
 */
function markProblems(array $blocks): array
{
    $problems = [];
    foreach ($blocks as $n => $block) {
        foreach ($block['marks'] ?? [] as $path => $ranges) {
            $field = $block;
            foreach (explode('.', (string) $path) as $step) {
                $field = is_array($field) ? ($field[ctype_digit($step) ? (int) $step : $step] ?? null) : null;
            }
            if (!is_string($field)) {
                $problems[] = sprintf('block %d: marks path %s names no text', $n + 1, $path);
                continue;
            }
            $length = mb_strlen($field);
            foreach ($ranges as $range) {
                if ($range['start'] < 0 || $range['end'] > $length || $range['start'] >= $range['end']) {
                    $problems[] = sprintf('block %d: mark %s %d–%d outside "%s"', $n + 1, $range['mark'], $range['start'], $range['end'], $path);
                }
            }
        }
    }

    return $problems;
}

$index = [];
$failures = [];

foreach ($manifest['policies'] as $policy) {
    $slug = $policy['slug'];
    $htmlPath = $source . '/' . $policy['htmlFile'];
    $pdfPath = $source . '/' . $policy['pdfFile'];
    $html = @file_get_contents($htmlPath);
    if ($html === false) {
        $failures[$slug] = ['Cannot read ' . $htmlPath];
        continue;
    }
    if (hash('sha256', $html) !== $policy['htmlSha256']) {
        $failures[$slug] = ['Section HTML does not match the manifest hash: it is not the approved rendition.'];
        continue;
    }

    try {
        $converted = HandbookPolicyConverter::convert($html);
    } catch (RuntimeException $e) {
        $failures[$slug] = ['Converter: ' . $e->getMessage()];
        continue;
    }

    $requiresAcknowledgement = in_array($policy['acknowledgement'], ['required', 'conditional'], true);
    $content = [
        'blocks' => $converted['blocks'],
        'declaration' => $converted['declaration'],
        'effectiveFrom' => null,
        'documentReference' => $policy['documentReference'] !== '' ? $policy['documentReference'] : null,
        'issuedLabel' => $policy['issued'] !== '' ? $policy['issued'] : null,
        'reviewDueLabel' => $policy['reviewDue'] !== '' ? $policy['reviewDue'] : null,
        'requiresAcknowledgement' => $requiresAcknowledgement,
    ];
    $problems = markProblems($converted['blocks']);
    try {
        $authored = AuthoredContent::fromInput(AuthoredContent::TYPE_POLICY, $content, null);
        $problems = [...$problems, ...$authored->publishProblems(null)];
        if ($authored->toArray() !== $content) {
            $problems[] = 'PolicyBlockRules changed the content: the converter is not emitting the stored shape.';
        }
    } catch (InvalidOnboardingValue $e) {
        $problems[] = 'PolicyBlockRules: ' . $e->getMessage();
    }
    if ($requiresAcknowledgement && $converted['declaration'] === null) {
        $problems[] = 'Acknowledgement is required but no declaration wording was found.';
    }
    if ($problems !== []) {
        $failures[$slug] = $problems;
        continue;
    }

    $record = [
        'schema' => 'tricab.onboarding.handbook-policy/1',
        'slug' => $slug,
        'title' => $policy['title'],
        'summary' => $policy['description'],
        'metadata' => [
            'documentReference' => $policy['documentReference'] !== '' ? $policy['documentReference'] : null,
            'issuedLabel' => $policy['issued'] !== '' ? $policy['issued'] : null,
            'reviewDueLabel' => $policy['reviewDue'] !== '' ? $policy['reviewDue'] : null,
            'chapterId' => $policy['chapterId'],
            'chapterTitle' => $chapters[$policy['chapterId']] ?? null,
            'sortOrder' => $policy['id'],
            'requiresAcknowledgement' => $requiresAcknowledgement,
            'acknowledgement' => $policy['acknowledgement'],
            'audience' => $policy['audience'],
            'signedForm' => in_array($slug, SIGNED_FORM_SLUGS, true),
            'reviewForHr' => in_array($policy['id'], REVIEW_FOR_HR_IDS, true),
        ],
        'source' => [
            'repository' => 'tricab-training-vercel',
            'commit' => $commit . ($dirty ? '-dirty' : ''),
            'htmlFile' => 'policy-library/' . $policy['htmlFile'],
            'htmlSha256' => $policy['htmlSha256'],
            'pdfFile' => 'policy-library/' . $policy['pdfFile'],
            'pdfSha256' => is_file($pdfPath) ? hash_file('sha256', $pdfPath) : null,
            'extractionPdfSha256' => $policy['pdfSha256'],
            'sourcePages' => $policy['sourcePageLabel'],
            'sourceNotes' => $policy['sourceNotes'],
        ],
        'content' => $content,
        'report' => $converted['report'],
    ];

    file_put_contents(
        $out . '/policies/' . $slug . '.json',
        json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
    );
    $index[] = $record;
}

// ---- index.json -------------------------------------------------------------

file_put_contents($out . '/index.json', json_encode([
    'schema' => 'tricab.onboarding.handbook-import-index/1',
    'source' => ['repository' => 'tricab-training-vercel', 'commit' => $commit . ($dirty ? '-dirty' : ''), 'manifestSha256' => hash_file('sha256', $manifestPath)],
    'converted' => count($index),
    'failed' => array_keys($failures),
    'policies' => array_map(static fn (array $r): array => [
        'slug' => $r['slug'],
        'file' => 'policies/' . $r['slug'] . '.json',
        'title' => $r['title'],
        'sortOrder' => $r['metadata']['sortOrder'],
        'requiresAcknowledgement' => $r['metadata']['requiresAcknowledgement'],
        'signedForm' => $r['metadata']['signedForm'],
    ], $index),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");

// ---- REPORT.md --------------------------------------------------------------

$md = static fn (string $text): string => str_replace(['|', "\n"], ['\\|', ' '], $text);
$lines = [];
$lines[] = '# Handbook policy conversion report';
$lines[] = '';
$lines[] = sprintf('Generated %s by `tricab-api/bin/onboarding-convert-handbook.php` from `tricab-training-vercel` @ `%s`%s.', date('j M Y H:i'), $commit, $dirty ? ' (**uncommitted changes in the source**)' : '');
$lines[] = '';
$lines[] = sprintf('**%d of %d converted and passed `PolicyBlockRules`.**%s', count($index), count($manifest['policies']), $failures === [] ? '' : ' Failures are listed first.');
$lines[] = '';
$lines[] = 'Nothing has been written to a database. This is for HR to read beside each policy\'s PDF: the converted';
$lines[] = 'text is not the rendition HR approved until they have checked it.';
$lines[] = '';

if ($failures !== []) {
    $lines[] = '## Failures';
    $lines[] = '';
    foreach ($failures as $slug => $problems) {
        $lines[] = '- **' . $slug . '**';
        foreach ($problems as $problem) {
            $lines[] = '  - ' . $md($problem);
        }
    }
    $lines[] = '';
}

$lines[] = '## Summary';
$lines[] = '';
$lines[] = '| # | Policy | Blocks | Bold / italic | Ack | Word coverage | Needs a look |';
$lines[] = '|---|---|---|---|---|---|---|';
foreach ($index as $r) {
    $rep = $r['report'];
    $bold = 0;
    $italic = 0;
    foreach ($r['content']['blocks'] as $block) {
        foreach ($block['marks'] ?? [] as $ranges) {
            foreach ($ranges as $range) {
                $range['mark'] === 'bold' ? $bold++ : $italic++;
            }
        }
    }
    $look = [];
    if ($rep['droppedRunningHeaders'] !== []) {
        $look[] = count($rep['droppedRunningHeaders']) . ' running headers dropped';
    }
    if ($rep['demotedH4'] !== []) {
        $look[] = count($rep['demotedH4']) . ' h4 → h3';
    }
    if ($rep['flattenedNestedLists'] !== []) {
        $look[] = count($rep['flattenedNestedLists']) . ' nested items flattened';
    }
    if ($rep['comparisonTables'] > 0) {
        $look[] = $rep['comparisonTables'] . ' always/never tables';
    }
    if ($rep['droppedUnderline'] !== []) {
        $look[] = count($rep['droppedUnderline']) . ' underline';
    }
    if ($rep['droppedLinks'] !== []) {
        $look[] = count($rep['droppedLinks']) . ' links';
    }
    if ($rep['formItems'] !== [] || $rep['declarationNeedsReview'] !== []) {
        $look[] = '**sign-off sheet**';
    }
    if ($r['metadata']['reviewForHr']) {
        $look[] = '**review date ' . $r['metadata']['reviewDueLabel'] . '**';
    }
    $ack = $r['metadata']['requiresAcknowledgement'] ? ($r['metadata']['signedForm'] ? 'yes + form' : 'yes') : '';
    $lines[] = sprintf(
        '| %d | %s | %d | %d / %d | %s | %.1f%% | %s |',
        $r['metadata']['sortOrder'],
        $md($r['title']),
        count($r['content']['blocks']),
        $bold,
        $italic,
        $ack,
        $rep['wordCoverage']['coverage'] * 100,
        implode('; ', $look),
    );
}
$lines[] = '';

$lines[] = '## What the conversion does to every policy';
$lines[] = '';
$lines[] = '- Headings keep their words; any bold or italic on them is dropped (a heading is already bold).';
$lines[] = '  `<h4>` becomes a level-3 heading, the same as `<h3>`.';
$lines[] = '- Bold and italic in body text are kept as marks beside the text, not as HTML. Underline is dropped.';
$lines[] = '- A link keeps its words and loses its target.';
$lines[] = '- `<br>` (the PDF\'s line wraps, almost all inside table cells) becomes a space.';
$lines[] = '- A list inside a list is flattened into it; the inner items start with "– ".';
$lines[] = '- A bold paragraph that repeats is the PDF\'s running page header, and is dropped.';
$lines[] = '- The acknowledgement panel becomes the policy\'s declaration — the words a new starter agrees to.';
$lines[] = '  Its blank fields (Name, Signature, Date) and anything asking them to tick, choose or attach are left';
$lines[] = '  out and listed below: they belong on the signed form.';
$lines[] = '';
$lines[] = 'Text is otherwise untouched: extraction faults the PDF introduced (missing spaces such as';
$lines[] = '"(PPE)is not used", spaced quotes such as "“ Immediate family ”") are carried through as they are, for HR to fix.';
$lines[] = '';

$lines[] = '## Per policy';
$lines[] = '';
foreach ($index as $r) {
    $rep = $r['report'];
    $m = $r['metadata'];
    $lines[] = sprintf('### %d. %s', $m['sortOrder'], $md($r['title']));
    $lines[] = '';
    $lines[] = sprintf(
        '`%s` · %s · issued %s · review due %s · %s · PDF `%s`',
        $r['slug'],
        $m['documentReference'] ?? 'no reference',
        $m['issuedLabel'] ?? '—',
        $m['reviewDueLabel'] ?? '—',
        $m['requiresAcknowledgement'] ? 'acknowledge' . ($m['signedForm'] ? ' + signed form' : '') : 'read only',
        $r['source']['pdfFile'],
    );
    $lines[] = '';
    $counts = [];
    foreach ($rep['blockCounts'] as $type => $count) {
        $counts[] = $count . ' ' . $type;
    }
    $lines[] = '- Blocks: ' . implode(', ', $counts) . '.';
    $coverage = $rep['wordCoverage'];
    $lines[] = sprintf('- Words: %d in the source, %.1f%% accounted for%s.', $coverage['sourceWords'], $coverage['coverage'] * 100, $coverage['missing'] === [] ? '' : ' — not found in the output: ' . implode(', ', array_map(static fn (string $w): string => '"' . $w . '"', $coverage['missing'])));
    if ($rep['droppedRunningHeaders'] !== []) {
        $lines[] = '- Running headers dropped: ' . count($rep['droppedRunningHeaders']) . ' × "' . $md(implode('", "', array_values(array_unique($rep['droppedRunningHeaders'])))) . '"';
    }
    if ($rep['demotedH4'] !== []) {
        $lines[] = '- h4 made level 3: ' . $md(implode(' · ', $rep['demotedH4']));
    }
    if ($rep['flattenedNestedLists'] !== []) {
        $lines[] = '- Nested list items flattened: ' . $md(implode(' · ', $rep['flattenedNestedLists']));
    }
    if ($rep['joinedListParagraphs'] > 0) {
        $lines[] = '- List items made of more than one paragraph, joined: ' . $rep['joinedListParagraphs'];
    }
    if ($rep['lineBreaksJoined'] > 0) {
        $lines[] = '- Line breaks joined: ' . $rep['lineBreaksJoined'];
    }
    if ($rep['comparisonTables'] > 0) {
        $lines[] = '- Always/never grids made comparison tables: ' . $rep['comparisonTables'];
    }
    if ($rep['droppedHeadingEmphasis'] > 0) {
        $lines[] = '- Headings that lost bold/italic: ' . $rep['droppedHeadingEmphasis'];
    }
    if ($rep['droppedUnderline'] !== []) {
        $lines[] = '- Underline dropped: "' . $md(implode('", "', $rep['droppedUnderline'])) . '"';
    }
    foreach ($rep['droppedLinks'] as $link) {
        $lines[] = '- Link dropped: "' . $md($link['text']) . '" → `' . $link['href'] . '`';
    }
    if ($rep['unknownElements'] !== []) {
        $lines[] = '- Unknown elements read as paragraphs: ' . implode(', ', $rep['unknownElements']);
    }
    if ($r['content']['declaration'] !== null) {
        $lines[] = '- **Declaration** (from "' . $md((string) $rep['declarationTitle']) . '"):';
        $lines[] = '';
        foreach (explode("\n", $r['content']['declaration']) as $line) {
            $lines[] = '  > ' . $line;
        }
        $lines[] = '';
    }
    if ($rep['declarationShownAsBody']) {
        $lines[] = '- The source has no wording outside its sign-off panel, so the declaration is also shown as the body.';
    }
    if ($rep['signOffFields'] !== []) {
        $lines[] = '- Sign-off fields left for the form: ' . $md(implode(', ', $rep['signOffFields']));
    }
    foreach ($rep['formItems'] as $item) {
        $lines[] = '- **Left out, needs the form:** "' . $md($item) . '"';
    }
    foreach ($rep['declarationNeedsReview'] as $item) {
        $lines[] = '- **Needs a decision:** ' . $md($item);
    }
    foreach ($r['source']['sourceNotes'] as $note) {
        $lines[] = '- Source note: ' . $md($note);
    }
    if ($r['source']['pdfSha256'] !== $r['source']['extractionPdfSha256']) {
        $lines[] = '- The PDF has changed since extraction (fillable fields were added); both hashes are kept.';
    }
    $lines[] = '';
}

file_put_contents($out . '/REPORT.md', implode("\n", $lines));

fwrite(STDOUT, sprintf("%d of %d converted. Report: %s/REPORT.md\n", count($index), count($manifest['policies']), $out));
foreach ($failures as $slug => $problems) {
    fwrite(STDOUT, "FAILED {$slug}\n  " . implode("\n  ", $problems) . "\n");
}
exit($failures === [] ? 0 : 1);
