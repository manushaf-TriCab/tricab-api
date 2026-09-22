<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\ValueObject;

use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Exception\UnknownPresentationSlide;

/**
 * The ordered slide list a training module version publishes.
 *
 * `onboarding.TrainingModuleVersions.PresentationManifestJson` holds it, and it
 * is the authority on two things a client must not be trusted for: which slides
 * exist, and what order they are in. Progress is validated against it, and
 * completion is derived from it.
 *
 * ## Strict, unlike the trainee-facing reads
 *
 * {@see AssignmentStatus::fromStorage()} forgives a bad status because a data
 * defect must not lock somebody out of their own induction. A manifest is the
 * opposite case: it is content an author published, the author is the person who
 * can fix it, and a "forgiving" reading would silently shorten a presentation so
 * that people are marked complete without seeing the end of it. So a malformed
 * manifest throws, and the author finds out at publish time.
 *
 * ## Why ids and not indices
 *
 * The manifest could have been a slide count, and progress a number. It is a
 * list of ids because inserting a slide at position 3 would then advance
 * everybody's recorded position by one without anybody having seen the new
 * slide. Ids make an insertion visible: the new id is simply not in anyone's
 * reached set.
 *
 * Accepted shapes, both of which appear in authored content:
 *
 *   ["intro", "hazards", "sign-off"]
 *   [{"id": "intro", "title": "Welcome"}, …]
 *
 * Anything else — a duplicate id, an empty id, a nested object with no `id` —
 * is a defect in the content and is reported as one.
 */
final class PresentationManifest
{
    /**
     * @param list<string>          $slideIds ordered, unique, non-empty
     * @param array<string, string> $titles   slide id to title, where one was authored
     */
    private function __construct(
        private readonly array $slideIds,
        private readonly array $titles,
    ) {
    }

    /**
     * @throws InvalidOnboardingValue when the stored manifest is not a usable slide list
     */
    public static function fromJson(string $json): self
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw InvalidOnboardingValue::for('presentation manifest', 'not a JSON array');
        }

        return self::fromArray($decoded);
    }

    /**
     * @param array<array-key, mixed> $slides
     *
     * @throws InvalidOnboardingValue
     */
    public static function fromArray(array $slides): self
    {
        $ids = [];
        $titles = [];

        foreach (array_values($slides) as $index => $slide) {
            $id = self::slideId($slide, $index);

            if (in_array($id, $ids, true)) {
                throw InvalidOnboardingValue::for('presentation manifest', 'duplicate slide id "' . $id . '"');
            }

            $ids[] = $id;

            if (is_array($slide) && isset($slide['title']) && is_string($slide['title'])) {
                $titles[$id] = $slide['title'];
            }
        }

        return new self($ids, $titles);
    }

    /**
     * @throws InvalidOnboardingValue
     */
    private static function slideId(mixed $slide, int $index): string
    {
        if (is_string($slide)) {
            $id = trim($slide);
        } elseif (is_array($slide) && isset($slide['id']) && is_string($slide['id'])) {
            $id = trim($slide['id']);
        } else {
            throw InvalidOnboardingValue::for(
                'presentation manifest',
                'slide ' . ($index + 1) . ' has no id',
            );
        }

        if ($id === '') {
            throw InvalidOnboardingValue::for(
                'presentation manifest',
                'slide ' . ($index + 1) . ' has an empty id',
            );
        }

        return $id;
    }

    /** @return list<string> */
    public function slideIds(): array
    {
        return $this->slideIds;
    }

    public function slideCount(): int
    {
        return count($this->slideIds);
    }

    public function isEmpty(): bool
    {
        return $this->slideIds === [];
    }

    public function titleFor(string $slideId): ?string
    {
        return $this->titles[$slideId] ?? null;
    }

    public function contains(string $slideId): bool
    {
        return in_array($slideId, $this->slideIds, true);
    }

    /** The slide somebody has to reach for the presentation to be over. */
    public function finalSlideId(): ?string
    {
        return $this->slideIds === [] ? null : $this->slideIds[count($this->slideIds) - 1];
    }

    /**
     * @param list<string> $slideIds
     *
     * @throws UnknownPresentationSlide naming every offending id at once
     */
    public function assertContainsAll(array $slideIds): void
    {
        $unknown = [];

        foreach ($slideIds as $slideId) {
            if (!$this->contains($slideId) && !in_array($slideId, $unknown, true)) {
                $unknown[] = $slideId;
            }
        }

        if ($unknown !== []) {
            throw UnknownPresentationSlide::forIds($unknown);
        }
    }

    /**
     * Sorts a set of reached slides into manifest order and drops duplicates.
     *
     * Order matters because the stored list is read back as "where this person
     * got to". A client that reports slides out of order — which any client with
     * a back button does — must not produce a record whose last entry is slide 2.
     *
     * @param list<string> $slideIds
     *
     * @return list<string>
     */
    public function inManifestOrder(array $slideIds): array
    {
        $reached = [];

        foreach ($this->slideIds as $slideId) {
            if (in_array($slideId, $slideIds, true)) {
                $reached[] = $slideId;
            }
        }

        return $reached;
    }

    /**
     * @return list<array{id: string, title: string|null}>
     */
    public function toArray(): array
    {
        return array_map(
            fn (string $id): array => ['id' => $id, 'title' => $this->titleFor($id)],
            $this->slideIds,
        );
    }
}
