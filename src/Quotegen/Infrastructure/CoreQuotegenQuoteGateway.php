<?php

declare(strict_types=1);

namespace TriCab\Api\Quotegen\Infrastructure;

use TriCab\Api\Quotegen\Application\QuotegenQuoteGateway;
use TriCab\Api\Quotegen\Application\QuotegenTurnContext;
use TriCab\Core\Rfq\Application\Service\ReadRfqQueueService;
use TriCab\Core\Rfq\Application\Service\RfqLookupService;
use TriCab\Core\Rfq\Application\Service\RfqReviewService;
use TriCab\Core\Rfq\Contract\RfqParserClient;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Storage\DraftLineEdits;
use TriCab\Core\Rfq\Storage\QuoteHeaderEdit;

/**
 * The Quotegen assistant's window onto the RFQ core: the same services the
 * editor's endpoints call (`ReadRfqQueueService`, `RfqReviewService::save` and
 * `previewDraft`, `RfqLookupService`, the parser's product search). There is no
 * sync here.
 */
final readonly class CoreQuotegenQuoteGateway implements QuotegenQuoteGateway
{
    private const PRODUCT_LIMIT = 20;
    private const CODE_PATTERN = '#^[A-Za-z0-9 ./\-_+&()\#]{1,60}$#';
    private const QUANTITY_PATTERN = '/^\s*(\d{1,7}(\.\d{1,5})?)?\s*$/';

    public function __construct(
        private ReadRfqQueueService $queue,
        private RfqReviewService $review,
        private RfqLookupService $lookups,
        private RfqParserClient $parser,
    ) {
    }

    public function snapshot(QuotegenTurnContext $context): ?array
    {
        $detail = $this->queue->detail($context->actor, $context->rfqPublicId);
        if ($detail === null) {
            return null;
        }

        return ['revision' => $detail->revision?->revision, 'rfq' => $detail->toArray($context->mayViewPricing())];
    }

    public function readiness(QuotegenTurnContext $context): ?array
    {
        $view = $this->review->previewDraft($context->actor, $context->actorEmail, $context->rfqPublicId, null, null, CorrelationId::generate());

        return $view?->toArray($context->mayViewPricing());
    }

    public function searchProducts(string $query): array
    {
        return $this->parser->searchProducts(CorrelationId::generate(), $query, self::PRODUCT_LIMIT);
    }

    public function findCustomers(string $query): array
    {
        return array_map(
            static fn ($option): array => $option->toArray(),
            $this->lookups->searchCustomers($query),
        );
    }

    public function applyEdits(QuotegenTurnContext $context, array $edits): array
    {
        $detail = $this->queue->detail($context->actor, $context->rfqPublicId);
        $revision = $detail?->revision;
        if ($detail === null || $revision === null) {
            throw new InvalidRfqValue('This quote has no saved lines to edit yet.');
        }
        if ($revision->revision !== $context->revision) {
            throw new RfqRevisionConflict($revision->revision, $context->revision);
        }

        $byUid = [];
        foreach (is_array($edits['lines'] ?? null) ? $edits['lines'] : [] as $edit) {
            if (is_array($edit) && is_string($edit['lineUid'] ?? null)) {
                $byUid[$edit['lineUid']] = $edit;
            }
        }
        $known = [];
        $decisions = [];
        $touched = [];
        foreach ($revision->lines as $line) {
            $known[$line->lineUid] = true;
            $edit = $byUid[$line->lineUid] ?? null;
            if ($edit !== null && ($edit['remove'] ?? false) === true) {
                $touched[] = $line->lineUid;
                continue;
            }
            if ($edit !== null) {
                $touched[] = $line->lineUid;
            }
            $decisions[] = $this->decision($line->lineUid, $line->comment ?? '', false, $edit ?? []);
        }
        foreach (array_keys($byUid) as $uid) {
            if (!isset($known[$uid])) {
                throw new InvalidRfqValue(sprintf('Line %s is not on this quote.', $uid));
            }
        }
        foreach (is_array($edits['newLines'] ?? null) ? $edits['newLines'] : [] as $new) {
            $decisions[] = $this->decision('ai-' . bin2hex(random_bytes(8)), '', true, is_array($new) ? $new : []);
        }

        $header = null;
        if (is_array($edits['header'] ?? null)) {
            /** @var array<string, mixed> $changes */
            $changes = $edits['header'];
            $header = $this->header($revision->header->toArray(), $changes);
        }

        $before = [];
        foreach ($revision->lines as $line) {
            $before[$line->lineUid] = self::fingerprint($line->toArray(false));
        }
        $saved = $this->review->save(
            $context->actor,
            $context->actorEmail,
            $context->rfqPublicId,
            $context->revision,
            $decisions,
            CorrelationId::generate(),
            $header,
        );
        if ($saved === null) {
            throw new InvalidRfqValue('This quote could not be found.');
        }

        $after = $this->queue->detail($context->actor, $context->rfqPublicId)?->revision;
        $changed = [];
        foreach ($touched as $uid) {
            $changed[$uid] = true;
        }
        foreach ($after === null ? [] : $after->lines as $line) {
            if (!isset($before[$line->lineUid]) || $before[$line->lineUid] !== self::fingerprint($line->toArray(false))) {
                $changed[$line->lineUid] = true;
            }
        }

        return ['revision' => $saved, 'changedLineIds' => array_map('strval', array_keys($changed))];
    }

    /**
     * One line as the save takes it: kept as it is, with whatever the edit changes.
     *
     * @param array<mixed> $edit
     *
     * @return array{lineUid: string, approved: ?bool, comment: string, productCode?: ?string, cuts?: ?string, lengthEach?: ?string, isNew?: bool, status?: ?string, reviewerNote?: ?string}
     *
     * @throws InvalidRfqValue
     */
    private function decision(string $lineUid, string $comment, bool $isNew, array $edit): array
    {
        $approved = $edit['approved'] ?? null;
        $decision = ['lineUid' => $lineUid, 'approved' => is_bool($approved) ? $approved : null, 'comment' => $comment];
        if ($isNew) {
            $decision['isNew'] = true;
        }
        $newComment = $edit['comment'] ?? null;
        if (is_string($newComment)) {
            $decision['comment'] = mb_substr($newComment, 0, 2_000);
        }
        $code = self::text($edit, 'productCode');
        if ($code !== null) {
            if (preg_match(self::CODE_PATTERN, $code) !== 1) {
                throw new InvalidRfqValue('productCode is not a valid product code.');
            }
            $decision['productCode'] = $code;
        }
        foreach (['cuts', 'lengthEach'] as $field) {
            $value = self::text($edit, $field);
            if ($value === null) {
                continue;
            }
            if (preg_match(self::QUANTITY_PATTERN, $value) !== 1) {
                throw new InvalidRfqValue(sprintf('%s must be a decimal number.', $field));
            }
            $decision[$field] = $value;
        }
        $status = self::text($edit, 'status');
        if ($status !== null) {
            if (!in_array($status, DraftLineEdits::STATUSES, true)) {
                throw new InvalidRfqValue('status must be one of ' . implode(', ', DraftLineEdits::STATUSES) . '.');
            }
            $decision['status'] = $status;
        }
        $note = self::text($edit, 'reviewerNote');
        if ($note !== null) {
            if (mb_strlen($note) > DraftLineEdits::MAX_REVIEWER_NOTE) {
                throw new InvalidRfqValue('reviewerNote is too long.');
            }
            $decision['reviewerNote'] = $note;
        }

        return $decision;
    }

    /** @param array<mixed> $values */
    private static function text(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidRfqValue(sprintf('%s must be text.', $key));
        }

        return $value;
    }

    /**
     * The header as it stands, with the given fields replaced.
     *
     * @param array<string, mixed> $current `QuoteHeader::toArray()`
     * @param array<string, mixed> $changes
     */
    private function header(array $current, array $changes): QuoteHeaderEdit
    {
        $base = [
            'customerId' => is_numeric($current['customerId'] ?? null) ? (int) $current['customerId'] : null,
            'contact' => $current['contactCode'] ?? '',
            'contactPhone' => $current['contactPhone'] ?? '',
            'author' => $current['authorCode'] ?? '',
            'salesOffice' => $current['salesOfficeCode'] ?? '',
            'payTerms' => $current['payTermsCode'] ?? '',
            'validTill' => $current['validTill'] ?? '',
            'customerReference' => $current['projectReference'] ?? $current['customerReference'] ?? '',
            'comment' => $current['comment'] ?? '',
            'region' => $current['regionCode'] ?? 'australia',
            'currency' => $current['currency'] ?? 'AUD',
        ];
        foreach ($base as $key => $value) {
            if ($value === null) {
                $base[$key] = '';
            }
        }
        if (($base['customerId'] ?? '') === '') {
            $base['customerId'] = null;
        }

        return QuoteHeaderEdit::fromInput([...$base, ...$changes]);
    }

    /** @param array<string, mixed> $line */
    private static function fingerprint(array $line): string
    {
        unset($line['pricing'], $line['warnings'], $line['confidence']);

        return hash('sha256', json_encode($line, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
    }
}
