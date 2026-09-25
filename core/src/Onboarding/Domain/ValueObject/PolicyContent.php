<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\ValueObject;

/**
 * The wording of one policy version, as `PolicyVersions.ContentJson` holds it.
 *
 * ## Blocks, not HTML
 *
 * The column's own comment says why: policy text is authored by HR, shown to
 * every new starter, and is therefore an injection path. Content is a list of
 * `{type, …}` blocks and the renderer emits elements for the types it knows —
 * so a `<script>` somebody pasted into a paragraph is text, everywhere, without
 * anybody having to remember to escape it.
 *
 * Unknown block types are **kept** rather than dropped, and the renderer decides
 * what to do with them. Dropping here would mean a policy silently losing a
 * clause because the web client is a version behind, and a missing clause in a
 * document somebody is about to accept is the worst of the available failures.
 *
 * ## The declaration
 *
 * `onboarding.PolicyVersions` has no declaration column, and
 * `PolicyAcknowledgements.DeclarationText` stores whatever was agreed to,
 * verbatim. So the wording lives in the version's content under `declaration`,
 * with {@see defaultFor()} behind it — because a version authored without one
 * must still be acknowledgeable, and an empty declaration on an acknowledgement
 * row is evidence of nothing.
 *
 * The default names the policy. "I have read and accept this policy" on a row
 * whose only other identifier is a version id is a sentence that needs a join to
 * be understood; naming the title makes the row legible on its own, which is
 * what a person reading it years later has.
 */
final class PolicyContent
{
    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function __construct(
        private readonly array $blocks,
        private readonly ?string $declaration,
    ) {
    }

    public static function fromJson(string $json, string $policyTitle): self
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            // Unparseable content is shown as nothing rather than refused. The
            // trainee-facing side of this module is forgiving where a defect
            // would otherwise lock somebody out of their own induction; an empty
            // policy is visibly wrong and reportable, where a 500 is neither.
            return new self([], self::defaultFor($policyTitle));
        }

        $rawBlocks = array_key_exists('blocks', $decoded) ? $decoded['blocks'] : $decoded;
        $declaration = null;

        if (isset($decoded['declaration']) && is_string($decoded['declaration'])) {
            $trimmed = trim($decoded['declaration']);
            $declaration = $trimmed === '' ? null : $trimmed;
        }

        $blocks = [];
        if (is_array($rawBlocks)) {
            foreach (array_values($rawBlocks) as $block) {
                if (is_array($block)) {
                    /** @var array<string, mixed> $block */
                    $blocks[] = $block;
                } elseif (is_string($block)) {
                    // A bare string is a paragraph. Authored content in the
                    // source uses both shapes and neither is wrong.
                    $blocks[] = ['type' => 'paragraph', 'text' => $block];
                }
            }
        }

        return new self($blocks, $declaration ?? self::defaultFor($policyTitle));
    }

    public static function defaultFor(string $policyTitle): string
    {
        return sprintf(
            'I confirm that I have read and understood the %s policy, and I agree to comply with it.',
            $policyTitle,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function blocks(): array
    {
        return $this->blocks;
    }

    public function declaration(): string
    {
        return $this->declaration ?? self::defaultFor('');
    }
}
