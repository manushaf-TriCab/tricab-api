<?php

declare(strict_types=1);

namespace TriCab\Core\DocumentGeneration\Legacy;

use TriCab\Core\DocumentGeneration\Application\Exception\InvalidOcrStream;

final class LegacyStreamParser
{
    public function parse(string $contents, bool $allowTruncatedFinalRecord = false): LegacyStream
    {
        if (!mb_check_encoding($contents, 'UTF-8')) {
            throw new InvalidOcrStream('The OCR stream is not valid UTF-8.');
        }

        $lines = preg_split('/\r\n|\n|\r/', $contents);
        if ($lines === false || $lines === []) {
            throw new InvalidOcrStream('The OCR stream is empty.');
        }

        // A final newline terminates the preceding line; it does not create a data field.
        if (preg_match('/(?:\r\n|\n|\r)$/', $contents) === 1 && end($lines) === '') {
            array_pop($lines);
        }

        $selector = array_shift($lines);
        if (!is_string($selector) || !str_starts_with($selector, '.RET.')) {
            throw new InvalidOcrStream('Expected a .RET. template selector at line 1.');
        }

        $records = [];
        $warnings = [];
        $offset = 0;

        while ($offset < count($lines)) {
            $recordLine = $lines[$offset];
            $sourceLine = $offset + 2;
            ++$offset;

            if (!str_starts_with($recordLine, '.RER.')) {
                throw new InvalidOcrStream(sprintf(
                    'Expected a .RER. record at line %d; got %s.',
                    $sourceLine,
                    $recordLine === '' ? '<blank>' : $recordLine,
                ));
            }

            if (!array_key_exists($offset, $lines)) {
                throw new InvalidOcrStream(sprintf('Record at line %d has no field count.', $sourceLine));
            }

            $countText = trim($lines[$offset]);
            ++$offset;

            if ($countText === '' || preg_match('/^\d+$/', $countText) !== 1) {
                throw new InvalidOcrStream(sprintf('Invalid field count at line %d.', $sourceLine + 1));
            }

            $declaredCount = (int) $countText;
            $availableCount = count($lines) - $offset;
            $take = min($declaredCount, $availableCount);
            $fields = array_slice($lines, $offset, $take);
            $offset += $take;

            if ($take < $declaredCount) {
                if (!$allowTruncatedFinalRecord || $offset !== count($lines)) {
                    throw new InvalidOcrStream(sprintf(
                        '%s at line %d declares %d fields but only %d remain.',
                        $recordLine,
                        $sourceLine,
                        $declaredCount,
                        $take,
                    ));
                }

                $missing = $declaredCount - $take;
                $fields = array_merge($fields, array_fill(0, $missing, ''));
                $warnings[] = sprintf(
                    '%s at line %d was padded with %d trailing blank field(s).',
                    $recordLine,
                    $sourceLine,
                    $missing,
                );
            }

            $records[] = new LegacyRecord(
                type: substr($recordLine, 5),
                declaredFieldCount: $declaredCount,
                fields: $fields,
                sourceLine: $sourceLine,
            );
        }

        return new LegacyStream($selector, $records, $warnings);
    }
}
