<?php

declare(strict_types=1);

namespace TriCab\Api\Quotegen\Application;

use Throwable;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable;
use TriCab\Core\Rfq\Domain\Exception\RfqLookupUnavailable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;
use TriCab\Core\Rfq\Domain\ValueObject\ExtractionState;
use TriCab\Core\Rfq\Parser\RfqParserFailure;

/**
 * The Quotegen assistant's six tools. The actor, the RFQ and the revision come
 * from the {@see QuotegenTurnContext}; no schema has a field for any of them.
 *
 * Reads are plain. `update_lines` goes through the gateway's revision-checked
 * save and is recorded under an operation id (turn + tool call), so a tool call
 * that comes round again is answered from the record, never written twice.
 * `propose_sync` only records a proposal for the saved revision: this class has
 * no way to reach the sync service.
 */
final readonly class QuotegenTools implements QuotegenToolExecutor
{
    private const MAX_LINE_EDITS = 50;
    private const MAX_NEW_LINES = 20;
    private const LINE_FIELDS = ['lineUid', 'productCode', 'cuts', 'lengthEach', 'comment', 'approved', 'status', 'reviewerNote', 'remove'];
    private const NEW_LINE_FIELDS = ['productCode', 'cuts', 'lengthEach', 'comment'];
    private const HEADER_FIELDS = ['customerId', 'contact', 'contactPhone', 'payTerms', 'customerReference', 'comment', 'validTill'];

    public function __construct(
        private QuotegenQuoteGateway $quotes,
        private QuotegenConversationRepository $conversations,
    ) {
    }

    public function definitions(): array
    {
        $none = ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
        $text = static fn (string $description): array => ['type' => 'string', 'description' => $description];
        $quantity = static fn (string $description): array => ['type' => ['string', 'number'], 'description' => $description];

        return [
            [
                'name' => 'get_quote',
                'description' => 'Read the open quote: header, every line with its match status, quantities and warnings, readiness, and the lines that still have open questions. Call it before answering about the quote or editing it.',
                'input_schema' => $none,
            ],
            [
                'name' => 'search_products',
                'description' => 'Find catalogue products and codes matching a few words or a partial code (two characters or more). Up to 20 matches.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['query' => $text('A product code, partial code or description words.')],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'find_customer',
                'description' => 'Look a customer up by name or code. Returns up to 10 with the id the quote header needs.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['query' => $text('Customer name or code.')],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'update_lines',
                'description' => 'Edit the saved quote: change lines, remove lines, add lines, or set header fields. It saves a new revision straight away and returns the lines it changed. Only edit what the user asked for. Prices and margins cannot be set here.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'lines' => [
                            'type' => 'array',
                            'maxItems' => self::MAX_LINE_EDITS,
                            'description' => 'Edits to existing lines, by lineUid. Only the fields given change.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'lineUid' => $text('The lineUid from get_quote.'),
                                    'productCode' => $text('Product code to match this line to.'),
                                    'cuts' => $quantity('Number of cuts (pieces).'),
                                    'lengthEach' => $quantity('Length of each cut in metres.'),
                                    'comment' => $text('The line comment.'),
                                    'approved' => ['type' => 'boolean', 'description' => 'Approve or un-approve the line.'],
                                    'status' => $text('The line status, as the editor offers it.'),
                                    'reviewerNote' => $text('A reviewer note.'),
                                    'remove' => ['type' => 'boolean', 'description' => 'true removes the line from the quote.'],
                                ],
                                'required' => ['lineUid'],
                                'additionalProperties' => false,
                            ],
                        ],
                        'newLines' => [
                            'type' => 'array',
                            'maxItems' => self::MAX_NEW_LINES,
                            'description' => 'Lines to add.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'productCode' => $text('Product code.'),
                                    'cuts' => $quantity('Number of cuts (pieces).'),
                                    'lengthEach' => $quantity('Length of each cut in metres.'),
                                    'comment' => $text('The line comment.'),
                                ],
                                'required' => ['productCode'],
                                'additionalProperties' => false,
                            ],
                        ],
                        'header' => [
                            'type' => 'object',
                            'description' => 'Quote header fields to set; the rest are kept.',
                            'properties' => [
                                'customerId' => ['type' => 'integer', 'description' => 'The 4js customer id from find_customer.'],
                                'contact' => $text('Contact code.'),
                                'contactPhone' => $text('Contact phone.'),
                                'payTerms' => $text('Pay terms code.'),
                                'customerReference' => $text('Customer reference / project ref.'),
                                'comment' => $text('Quote comment.'),
                                'validTill' => $text('Valid-till date, YYYY-MM-DD.'),
                            ],
                            'additionalProperties' => false,
                        ],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'check_readiness',
                'description' => 'Check whether the saved quote is ready to send: blocking issues, per-line severity and reasons, and pricing where the user may see it.',
                'input_schema' => $none,
            ],
            [
                'name' => 'propose_sync',
                'description' => 'Propose syncing the saved quote to 4js. This does NOT sync anything: it shows the user a confirm button for the current saved revision. Use it only when the user asks to send or sync and the quote has no blocking issues.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['note' => $text('Optional one-line note for the user.')],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    public function execute(QuotegenTurnContext $context, string $toolUseId, string $name, array $input): QuotegenToolResult
    {
        try {
            return match ($name) {
                'get_quote' => $this->getQuote($context),
                'search_products' => $this->searchProducts($input),
                'find_customer' => $this->findCustomer($input),
                'update_lines' => $this->recorded($context, $toolUseId, $name, fn (): QuotegenToolResult => $this->updateLines($context, $input)),
                'check_readiness' => $this->checkReadiness($context),
                'propose_sync' => $this->recorded($context, $toolUseId, $name, fn (): QuotegenToolResult => $this->proposeSync($context)),
                default => QuotegenToolResult::failure('unknown_tool', 'Unknown tool'),
            };
        } catch (RfqRevisionConflict $conflict) {
            return QuotegenToolResult::failure(
                'revision_conflict',
                'The quote changed since it was read',
                ['currentRevision' => $conflict->currentRevision, 'instruction' => 'Someone saved first. Call get_quote, then redo the edit if it still applies.'],
            );
        } catch (InvalidRfqValue|RfqDraftNotEditable|RfqActionForbidden|RfqLookupUnavailable|RfqStoreUnavailable $failure) {
            // These messages are written by the domain to be shown to a person.
            return QuotegenToolResult::failure('rejected', 'The quote refused the change', ['message' => $failure->getMessage()]);
        } catch (RfqParserFailure) {
            return QuotegenToolResult::failure('service_unavailable', 'The matching service is unavailable');
        } catch (Throwable) {
            return QuotegenToolResult::failure('tool_execution_failed', 'The tool failed');
        }
    }

    /**
     * The model sees `extractionState` only as one field among many and does not
     * treat `partial` as "requested items may be missing", so say it outright.
     *
     * @return array{state: ?string, complete: bool, note: string}
     */
    private static function sourceCoverage(mixed $state): array
    {
        $state = is_string($state) ? $state : null;
        $note = match ($state) {
            ExtractionState::COMPLETE => 'Every row of the source was accounted for when it was read.',
            ExtractionState::PARTIAL => 'Some rows of the source were not accounted for when it was read: requested items may be missing from these lines.',
            ExtractionState::READING => 'The source is still being read; more lines may arrive.',
            ExtractionState::ATTENTION => 'A read of the source failed or offers changes nobody has resolved yet.',
            default => 'Coverage of the source was not measured (for example a PDF or image): requested items may be missing.',
        };

        return ['state' => $state, 'complete' => $state === ExtractionState::COMPLETE, 'note' => $note];
    }

    private function getQuote(QuotegenTurnContext $context): QuotegenToolResult
    {
        $snapshot = $this->quotes->snapshot($context);
        if ($snapshot === null) {
            return QuotegenToolResult::failure('not_found', 'Quote not found');
        }
        if ($snapshot['revision'] !== null) {
            $context->revision = $snapshot['revision'];
        }
        $rfq = $snapshot['rfq'];
        unset($rfq['documents'], $rfq['reads'], $rfq['syncAttempts'], $rfq['aliflex']);
        $readiness = null;
        try {
            $readiness = $this->quotes->readiness($context);
        } catch (Throwable) {
            // Readiness needs the matching service; the quote is still worth reading without it.
        }
        $pending = [];
        foreach (is_array($readiness['lines'] ?? null) ? $readiness['lines'] : [] as $line) {
            if (!is_array($line) || !in_array($line['severity'] ?? null, ['fix_required', 'check'], true)) {
                continue;
            }
            $reasons = [];
            foreach (is_array($line['codes'] ?? null) ? $line['codes'] : [] as $code) {
                if (is_array($code) && ($code['display'] ?? 'note') !== 'note') {
                    $reasons[] = (is_string($code['code'] ?? null) ? $code['code'] : '') . ': ' . (is_string($code['detail'] ?? null) ? $code['detail'] : '');
                }
            }
            $pending[] = ['lineUid' => $line['lineUid'] ?? null, 'severity' => $line['severity'], 'reasons' => $reasons];
        }

        return new QuotegenToolResult(
            true,
            ['revision' => $snapshot['revision'], 'quote' => $rfq, 'sourceCoverage' => self::sourceCoverage($rfq['extractionState'] ?? null), 'readiness' => $readiness, 'pendingQuestions' => $pending],
            'Read the quote',
            containsPricing: $context->mayViewPricing(),
        );
    }

    /** @param array<string, mixed> $input */
    private function searchProducts(array $input): QuotegenToolResult
    {
        $query = self::query($input);
        if ($query === null) {
            return QuotegenToolResult::failure('invalid_input', 'Search needs at least two characters');
        }
        $items = $this->quotes->searchProducts($query);

        return new QuotegenToolResult(true, ['items' => $items], sprintf('Found %d products', count($items)));
    }

    /** @param array<string, mixed> $input */
    private function findCustomer(array $input): QuotegenToolResult
    {
        $query = self::query($input);
        if ($query === null) {
            return QuotegenToolResult::failure('invalid_input', 'Search needs at least two characters');
        }
        $items = $this->quotes->findCustomers($query);

        return new QuotegenToolResult(true, ['items' => $items], sprintf('Found %d customers', count($items)));
    }

    private function checkReadiness(QuotegenTurnContext $context): QuotegenToolResult
    {
        $readiness = $this->quotes->readiness($context);
        if ($readiness === null) {
            return QuotegenToolResult::failure('not_found', 'Quote not found');
        }

        return new QuotegenToolResult(true, ['readiness' => $readiness], ($readiness['ready'] ?? false) === true ? 'Quote is ready' : 'Quote is not ready', containsPricing: $context->mayViewPricing());
    }

    /** @param array<string, mixed> $input */
    private function updateLines(QuotegenTurnContext $context, array $input): QuotegenToolResult
    {
        $edits = self::validEdits($input);
        if (is_string($edits)) {
            return QuotegenToolResult::failure('invalid_input', 'The edit was not valid', ['message' => $edits]);
        }
        $saved = $this->quotes->applyEdits($context, $edits);
        $context->revision = $saved['revision'];
        $count = count($saved['changedLineIds']);

        return new QuotegenToolResult(
            true,
            ['revision' => $saved['revision'], 'changedLineIds' => $saved['changedLineIds'], 'note' => 'Saved as a new revision. Call get_quote to see the result.'],
            $count === 0 ? 'Saved with no line changes' : sprintf('Changed %d %s', $count, $count === 1 ? 'line' : 'lines'),
            $saved['changedLineIds'],
            $saved['revision'],
        );
    }

    private function proposeSync(QuotegenTurnContext $context): QuotegenToolResult
    {
        $snapshot = $this->quotes->snapshot($context);
        if ($snapshot === null || $snapshot['revision'] === null) {
            return QuotegenToolResult::failure('not_found', 'Quote not found');
        }
        if ($snapshot['revision'] !== $context->revision) {
            return QuotegenToolResult::failure(
                'revision_conflict',
                'The quote changed since it was read',
                ['currentRevision' => $snapshot['revision'], 'instruction' => 'Call get_quote and check readiness again before proposing.'],
            );
        }

        return new QuotegenToolResult(
            true,
            ['proposed' => true, 'revision' => $snapshot['revision'], 'note' => 'Nothing has been synced. The user must press the confirm button.'],
            sprintf('Proposed syncing revision %d', $snapshot['revision']),
            proposedSyncRevision: $snapshot['revision'],
        );
    }

    /**
     * Runs a write once per operation id; a repeat is answered from the record.
     *
     * @param \Closure(): QuotegenToolResult $run
     */
    private function recorded(QuotegenTurnContext $context, string $toolUseId, string $name, \Closure $run): QuotegenToolResult
    {
        $operationId = hash('sha256', $context->turnMessageId . '|' . $toolUseId . '|' . $name);
        $previous = $this->conversations->findOperation($context->conversationId, $operationId);
        if ($previous !== null) {
            $changed = is_array($previous['changedLineIds'] ?? null) ? array_values(array_filter($previous['changedLineIds'], 'is_string')) : [];
            $revision = is_int($previous['revision'] ?? null) ? $previous['revision'] : null;
            if ($revision !== null) {
                $context->revision = $revision;
            }

            return new QuotegenToolResult(
                (bool) ($previous['ok'] ?? true),
                [...$previous, 'alreadyDone' => true],
                is_string($previous['summary'] ?? null) ? $previous['summary'] : 'Already done',
                $changed,
                $revision,
                is_int($previous['proposedSyncRevision'] ?? null) ? $previous['proposedSyncRevision'] : null,
                replayed: true,
            );
        }

        $result = $run();
        if ($result->ok) {
            $this->conversations->recordOperation(
                $context->conversationId,
                $operationId,
                $name,
                'ok',
                $result->summary,
                [
                    ...$result->data,
                    'ok' => true,
                    'summary' => $result->summary,
                    'changedLineIds' => $result->changedLineIds,
                    'proposedSyncRevision' => $result->proposedSyncRevision,
                    'revision' => $result->newRevision ?? $result->proposedSyncRevision ?? ($result->data['revision'] ?? null),
                ],
            );
        }

        return $result;
    }

    /** @param array<string, mixed> $input */
    private static function query(array $input): ?string
    {
        $query = $input['query'] ?? null;
        $query = is_string($query) ? mb_substr(trim($query), 0, 100) : '';

        return mb_strlen($query) >= 2 ? $query : null;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>|string the cleaned edits, or what is wrong
     */
    private static function validEdits(array $input): array|string
    {
        $unknown = array_diff(array_keys($input), ['lines', 'newLines', 'header']);
        if ($unknown !== []) {
            return 'Unknown field: ' . implode(', ', array_map('strval', $unknown));
        }
        $edits = ['lines' => [], 'newLines' => [], 'header' => null];

        $lines = $input['lines'] ?? [];
        if (!is_array($lines) || !array_is_list($lines) || count($lines) > self::MAX_LINE_EDITS) {
            return sprintf('lines must be a list of at most %d edits.', self::MAX_LINE_EDITS);
        }
        $seen = [];
        foreach ($lines as $i => $line) {
            $clean = is_array($line) ? self::fields($line, self::LINE_FIELDS) : 'must be an object.';
            if (is_string($clean)) {
                return sprintf('lines[%d]: %s', $i, $clean);
            }
            $uid = $clean['lineUid'] ?? null;
            if (!is_string($uid) || $uid === '') {
                return sprintf('lines[%d] needs a lineUid.', $i);
            }
            if (isset($seen[$uid])) {
                return sprintf('lines[%d] repeats a lineUid; give each line once.', $i);
            }
            $seen[$uid] = true;
            $edits['lines'][] = $clean;
        }

        $new = $input['newLines'] ?? [];
        if (!is_array($new) || !array_is_list($new) || count($new) > self::MAX_NEW_LINES) {
            return sprintf('newLines must be a list of at most %d lines.', self::MAX_NEW_LINES);
        }
        foreach ($new as $i => $line) {
            $clean = is_array($line) ? self::fields($line, self::NEW_LINE_FIELDS) : 'must be an object.';
            if (is_string($clean)) {
                return sprintf('newLines[%d]: %s', $i, $clean);
            }
            if (!is_string($clean['productCode'] ?? null) || trim($clean['productCode']) === '') {
                return sprintf('newLines[%d] needs a productCode.', $i);
            }
            $edits['newLines'][] = $clean;
        }

        if (array_key_exists('header', $input)) {
            $header = is_array($input['header']) ? self::fields($input['header'], self::HEADER_FIELDS) : 'header must be an object.';
            if (is_string($header)) {
                return $header;
            }
            $edits['header'] = $header === [] ? null : $header;
        }
        if ($edits['lines'] === [] && $edits['newLines'] === [] && $edits['header'] === null) {
            return 'Nothing to change: give lines, newLines or header.';
        }

        return $edits;
    }

    /**
     * @param array<mixed> $values
     * @param list<string> $allowed
     *
     * @return array<string, mixed>|string
     */
    private static function fields(array $values, array $allowed): array|string
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                return 'unknown field ' . (is_string($key) ? $key : '#' . $key) . '.';
            }
            if (in_array($key, ['cuts', 'lengthEach'], true) && (is_int($value) || is_float($value))) {
                $value = (string) $value;
            }
            if (in_array($key, ['approved', 'remove'], true)) {
                if (!is_bool($value)) {
                    return $key . ' must be true or false.';
                }
            } elseif ($key === 'customerId') {
                if (!is_int($value) || $value < 1) {
                    return 'customerId must be a 4js customer id.';
                }
            } elseif (!is_string($value) || mb_strlen($value) > 2_000) {
                return $key . ' must be short text.';
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
