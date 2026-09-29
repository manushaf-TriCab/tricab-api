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
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Parser\RfqServiceLimits;

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
     * (quantities as decimal strings); `isNew` marks a line a person added.
     *
     * @return list<array{lineUid: string, approved: ?bool, comment: string, productCode?: ?string, cuts?: ?string, lengthEach?: ?string, isNew?: bool}>|string
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
            if (($line['isNew'] ?? false) === true) {
                $decision['isNew'] = true;
            } elseif (array_key_exists('isNew', $line) && $line['isNew'] !== false) {
                return sprintf('lines[%d].isNew must be true or false.', $i);
            }
            $out[] = $decision;
        }

        return $out;
    }
}
