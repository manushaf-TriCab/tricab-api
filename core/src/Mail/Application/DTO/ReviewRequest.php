<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

use TriCab\Core\Mail\Domain\Exception\MailInputInvalid;
use TriCab\Core\Mail\Domain\MailVocabulary;

/** `MailReviewRequest`: the body of `POST /threads/{id}/review`. */
final readonly class ReviewRequest
{
    public function __construct(
        public string $action,
        public ?string $label = null,
        public ?string $note = null,
    ) {
    }

    public static function fromInput(mixed $body): self
    {
        if (!is_array($body)) {
            throw new MailInputInvalid('Send the review as a JSON object.');
        }
        $action = $body['action'] ?? null;
        if (!is_string($action) || !in_array($action, MailVocabulary::ACTIONS, true)) {
            throw new MailInputInvalid('action must be one of: ' . implode(', ', MailVocabulary::ACTIONS) . '.');
        }
        $label = $body['label'] ?? null;
        if ($action === MailVocabulary::ACTION_CORRECT) {
            if (!is_string($label) || !MailVocabulary::isLabel($label)) {
                throw new MailInputInvalid('label must be one of: ' . implode(', ', MailVocabulary::LABELS) . '.');
            }
        } else {
            $label = null;
        }
        $note = $body['note'] ?? null;
        if ($note !== null && !is_string($note)) {
            throw new MailInputInvalid('note must be text.');
        }
        $note = $note === null ? null : trim($note);
        if ($note !== null && mb_strlen($note) > MailVocabulary::MAX_NOTE_LENGTH) {
            throw new MailInputInvalid(sprintf('note is at most %d characters.', MailVocabulary::MAX_NOTE_LENGTH));
        }

        return new self($action, $label, $note === '' ? null : $note);
    }
}
