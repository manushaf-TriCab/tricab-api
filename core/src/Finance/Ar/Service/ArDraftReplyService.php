<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

use TriCab\Core\Finance\Ar\Contract\ArRecordStore;
use TriCab\Core\Finance\Ar\Domain\ArInvalid;
use TriCab\Core\Finance\Ar\Domain\ArNotFound;
use TriCab\Core\Finance\Ar\Domain\ArUpstream;

/**
 * `POST /records/{id}/email-draft` (source route 19): an editable reply drafted, or revised, from the person's
 * instruction. The model call is {@see ArAiService::draftReply()}; the source's 400 "Tell the AI what the reply should
 * say" is a 422, its 404 stays, and its 502 "AI draft failed: <reason>" is a typed {@see ArUpstream}.
 */
final class ArDraftReplyService
{
    public const NO_INSTRUCTION = 'Tell the AI what the reply should say';

    public function __construct(
        private readonly ArRecordStore $records,
        private readonly ArAiService $ai,
    ) {
    }

    /**
     * @param array<string, mixed> $payload `{instruction, previous_draft}`
     * @return array{subject: string, body: string, to: string}
     */
    public function draft(string $recordId, array $payload): array
    {
        $record = $this->records->find($recordId) ?? throw new ArNotFound(ArRecordService::NOT_FOUND);
        $instruction = is_string($payload['instruction'] ?? null) ? trim($payload['instruction']) : '';
        if ($instruction === '') {
            throw new ArInvalid(self::NO_INSTRUCTION);
        }
        $previous = is_string($payload['previous_draft'] ?? null) ? $payload['previous_draft'] : '';

        try {
            $draft = $this->ai->draftReply($record, $instruction, $previous);
        } catch (ArUpstream $failure) {
            throw new ArUpstream('AI draft failed: ' . $failure->getMessage(), ArUpstream::AI, $failure);
        }

        return $draft + ['to' => $record->senderEmail];
    }
}
