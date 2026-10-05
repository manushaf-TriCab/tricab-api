<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Throwable;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable;
use TriCab\Core\Rfq\Domain\Exception\RfqLookupUnavailable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;
use TriCab\Core\Rfq\Domain\Exception\RfqSyncBlocked;
use TriCab\Core\Rfq\Domain\Exception\RfqSyncNeedsReconciliation;
use TriCab\Core\Rfq\Domain\Exception\RfqSyncUnavailable;
use TriCab\Core\Rfq\Parser\AccessorySeal;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Parser\RfqServiceLimits;
use TriCab\Core\Rfq\Storage\DraftLineEdits;

/**
 * How an RFQ write's typed failures read at the public endpoint (architecture
 * doc §6.3). Every message is one the domain wrote to be shown to a person;
 * anything untyped is rethrown for the platform's own 500 handling.
 *
 * | Failure                | Status | Code                     |
 * |------------------------|--------|--------------------------|
 * | `InvalidRfqValue`      | 422    | `RFQ_INPUT_INVALID`      |
 * | `RfqActionForbidden`   | 403    | `RFQ_ACTION_FORBIDDEN`   |
 * | `RfqRevisionConflict`  | 409    | `RFQ_REVISION_CONFLICT`, with `currentRevision` |
 * | `RfqDraftNotEditable`  | 409    | `RFQ_NOT_EDITABLE`       |
 * | `RfqStoreUnavailable`  | 503    | `RFQ_STORE_UNAVAILABLE`  |
 * | `RfqLookupUnavailable` | 503    | `RFQ_LOOKUP_UNAVAILABLE` (the customer book, naming the header's customer) |
 * | `RfqParserFailure`     | its `httpStatus()` | `RFQ_PARSER_<CODE>`, with `retryable` and `correlationId` |
 * | `RfqSyncUnavailable`   | 503    | `RFQ_SYNC_DISABLED` (the switch is off) or `RFQ_SYNC_UNAVAILABLE` (no 4js connection); nothing was sent |
 * | `RfqSyncBlocked`       | 422 or 409 | `RFQ_SYNC_BLOCKED`, or `RFQ_SYNC_PRICES_CHANGED` (409), with `issues`; nothing was sent |
 * | `RfqSyncNeedsReconciliation` | 409 | `RFQ_SYNC_NEEDS_RECONCILIATION`, with the `attempt` |
 */
trait RfqWriteFailures
{
    private function failure(JsonResponder $responder, Throwable $failure): ResponseInterface
    {
        return match (true) {
            $failure instanceof InvalidRfqValue => $responder->error('RFQ_INPUT_INVALID', $failure->getMessage(), 422),
            $failure instanceof RfqActionForbidden => $responder->error('RFQ_ACTION_FORBIDDEN', $failure->getMessage(), 403),
            $failure instanceof RfqRevisionConflict => $responder->payload([
                'success' => false,
                'error' => [
                    'code' => 'RFQ_REVISION_CONFLICT',
                    'message' => $failure->getMessage(),
                    'currentRevision' => $failure->currentRevision,
                ],
            ], 409),
            $failure instanceof RfqDraftNotEditable => $responder->error('RFQ_NOT_EDITABLE', $failure->getMessage(), 409),
            $failure instanceof RfqStoreUnavailable => $responder->error('RFQ_STORE_UNAVAILABLE', $failure->getMessage(), 503),
            $failure instanceof RfqLookupUnavailable => $responder->error('RFQ_LOOKUP_UNAVAILABLE', $failure->getMessage(), 503),
            $failure instanceof RfqSyncUnavailable => $responder->error(
                $failure->reason === RfqSyncUnavailable::DISABLED ? 'RFQ_SYNC_DISABLED' : 'RFQ_SYNC_UNAVAILABLE',
                $failure->getMessage(),
                503,
            ),
            $failure instanceof RfqSyncBlocked => $responder->payload([
                'success' => false,
                'error' => [
                    'code' => $failure->pricesChanged ? 'RFQ_SYNC_PRICES_CHANGED' : 'RFQ_SYNC_BLOCKED',
                    'message' => $failure->getMessage(),
                    'issues' => $failure->issues,
                ],
            ], $failure->pricesChanged ? 409 : 422),
            $failure instanceof RfqSyncNeedsReconciliation => $responder->payload([
                'success' => false,
                'error' => [
                    'code' => 'RFQ_SYNC_NEEDS_RECONCILIATION',
                    'message' => $failure->getMessage(),
                    'attempt' => $failure->attempt->toArray(),
                ],
            ], 409),
            $failure instanceof RfqParserFailure => $responder->payload([
                'success' => false,
                'error' => [
                    'code' => 'RFQ_PARSER_' . strtoupper($failure->failureCode),
                    'message' => $failure->getMessage(),
                    'retryable' => $failure->isRetryable(),
                    'correlationId' => $failure->correlationId,
                ],
            ], $failure->httpStatus()),
            default => throw $failure,
        };
    }

    /**
     * A save's `lines`: every line to keep, in order, with what the person
     * decided. `approved` may be left out, which keeps the stored approval;
     * `productCode`, `cuts` and `lengthEach` are sent only when edited
     * (quantities as decimal strings); `isNew` marks a line a person added, and `accessoryToken` (only on a new line) names an accessory Match Accessories proposed.
     *
     * @return list<array{lineUid: string, approved: ?bool, comment: string, productCode?: ?string, cuts?: ?string, lengthEach?: ?string, isNew?: bool, marginPercent?: ?string, sellPrice?: ?string, status?: ?string, correctionReason?: ?string, reviewerNote?: ?string, accessoryToken?: string}>|string
     *         the lines, or what is wrong with them
     */
    private static function lineDecisions(mixed $lines): array|string
    {
        if (!is_array($lines) || !array_is_list($lines)) {
            return 'lines must be a list.';
        }
        if (count($lines) > RfqServiceLimits::MAX_LINES_PER_REVISION) {
            return sprintf('A revision holds at most %d lines.', RfqServiceLimits::MAX_LINES_PER_REVISION);
        }
        $out = [];
        foreach ($lines as $i => $line) {
            $approved = is_array($line) ? ($line['approved'] ?? null) : null;
            if (!is_array($line)
                || !is_string($line['lineUid'] ?? null) || $line['lineUid'] === ''
                || !($approved === null || is_bool($approved))
                || !is_string($line['comment'] ?? null)) {
                return sprintf('lines[%d] must be {lineUid, comment} with an optional approved.', $i);
            }
            if (mb_strlen($line['comment']) > 2_000) {
                return sprintf('lines[%d].comment is longer than 2,000 characters.', $i);
            }
            $decision = ['lineUid' => $line['lineUid'], 'approved' => $approved, 'comment' => $line['comment']];
            $code = $line['productCode'] ?? null;
            if ($code !== null) {
                if (!is_string($code) || mb_strlen($code) > 60 || preg_match('#^[A-Za-z0-9 ./\-_+&()\#]*$#', $code) !== 1) {
                    return sprintf('lines[%d].productCode must be a product code of up to 60 characters.', $i);
                }
                $decision['productCode'] = $code;
            }
            foreach (['cuts', 'lengthEach'] as $field) {
                $value = $line[$field] ?? null;
                if ($value === null) {
                    continue;
                }
                if (!is_string($value) || preg_match('/^\s*(\d{1,7}(\.\d{1,5})?)?\s*$/', $value) !== 1) {
                    return sprintf('lines[%d].%s must be a decimal number, as text, or blank.', $i, $field);
                }
                $decision[$field] = $value;
            }
            $margin = $line['marginPercent'] ?? null;
            if ($margin !== null) {
                // Whole percent, under 100: 4js truncates the margin it shows.
                if (!is_string($margin) || preg_match('/^\s*(\d{1,2}(\.\d{1,5})?)?\s*$/', $margin) !== 1) {
                    return sprintf('lines[%d].marginPercent must be a percentage under 100, as text, or blank.', $i);
                }
                $decision['marginPercent'] = $margin;
            }
            $sell = $line['sellPrice'] ?? null;
            if ($sell !== null) {
                if (!is_string($sell) || preg_match('/^\s*(\d{1,9}(\.\d{1,6})?)?\s*$/', $sell) !== 1 || (trim($sell) !== '' && round((float) $sell, 3) <= 0.0)) {
                    return sprintf('lines[%d].sellPrice must be a price above zero, as text, or blank.', $i);
                }
                $decision['sellPrice'] = $sell;
            }
            $status = $line['status'] ?? null;
            if ($status !== null) {
                if (!is_string($status) || !in_array($status, DraftLineEdits::STATUSES, true)) {
                    return sprintf('lines[%d].status must be one of %s.', $i, implode(', ', DraftLineEdits::STATUSES));
                }
                $decision['status'] = $status;
            }
            $reason = $line['correctionReason'] ?? null;
            if ($reason !== null) {
                if (!is_string($reason) || !in_array($reason, DraftLineEdits::CORRECTION_REASONS, true)) {
                    return sprintf('lines[%d].correctionReason must be blank or one of %s.', $i, implode(', ', array_filter(DraftLineEdits::CORRECTION_REASONS)));
                }
                $decision['correctionReason'] = $reason;
            }
            $note = $line['reviewerNote'] ?? null;
            if ($note !== null) {
                if (!is_string($note) || mb_strlen($note) > DraftLineEdits::MAX_REVIEWER_NOTE) {
                    return sprintf('lines[%d].reviewerNote must be text of up to 2,000 characters.', $i);
                }
                $decision['reviewerNote'] = $note;
            }
            if (($line['isNew'] ?? false) === true) {
                $decision['isNew'] = true;
            } elseif (array_key_exists('isNew', $line) && $line['isNew'] !== false) {
                return sprintf('lines[%d].isNew must be true or false.', $i);
            }
            $accessory = $line['accessoryToken'] ?? null;
            if ($accessory !== null) {
                // An accessory the engine proposed, added as a new line (E7.2): the token is the server's, and says the rest.
                if (!is_string($accessory) || $accessory === '' || strlen($accessory) > AccessorySeal::MAX_TOKEN_BYTES || ($decision['isNew'] ?? false) !== true) {
                    return sprintf('lines[%d].accessoryToken must be an accessory match, on a new line.', $i);
                }
                $decision['accessoryToken'] = $accessory;
            }
            $out[] = $decision;
        }

        return $out;
    }
}
