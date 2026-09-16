<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\Service;

use DateTimeImmutable;
use TriCab\Core\Expense\Domain\Entity\ExpenseBankFeedEntry;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseBankFeedPublicId;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseCurrency;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseMoney;
use TriCab\Core\Expense\Module;

/**
 * NZ bank-feed CSV parser.
 *
 * Details column is fixed-width: merchant (40) + city (25) + country (3) + postcode (rest).
 * Synthetic bank references: NZ-{statementDate}-{rowIndex}.
 * Payment detection via type/merchant keywords; charge amounts are sign-flipped to positive spend.
 * Statement date is taken from the filename when present (e.g. ..._2024-06-30.csv).
 */
final class ExpenseNzBankFeedCsvParser
{
    /**
     * @return list<ExpenseBankFeedEntry>
     */
    public function parse(string $csvContents, string $filename, DateTimeImmutable $uploadedAt): array
    {
        $statementDate = $this->statementDateFromFilename($filename);
        $rows = $this->readCsv($csvContents);
        if ($rows === []) {
            return [];
        }

        $header = array_shift($rows);
        $map = $this->headerMap($header);
        $entries = [];
        $rowIndex = 0;

        foreach ($rows as $row) {
            if ($this->rowEmpty($row)) {
                continue;
            }
            $rowIndex++;
            $get = static fn (string $key): string => trim((string) ($row[$map[$key] ?? -1] ?? ''));

            $details = $get('details') !== '' ? $get('details') : $get('description');
            [$merchant, $city, $country, $postcode] = $this->splitDetails($details);

            $amountRaw = $get('amount');
            if ($amountRaw === '') {
                continue;
            }
            $amount = ExpenseMoney::fromSignedString($amountRaw);
            $txTypeRaw = $get('transaction type') !== '' ? $get('transaction type') : $get('type');
            $isPayment = $this->isPayment($txTypeRaw, $merchant, $details);

            // Legacy: flip charge sign so spends are positive; payments keep their sign as payment type.
            if (!$isPayment && str_starts_with($amount->amount(), '-')) {
                $amount = $amount->negate();
            }

            $txDate = $this->parseDate($get('transaction date') !== '' ? $get('transaction date') : $get('date'));
            $stmt = $this->parseDate($get('statement date')) ?? $statementDate;
            $bankRef = $get('bank reference');
            if ($bankRef === '') {
                $datePart = ($stmt ?? $txDate)?->format('Y-m-d') ?? 'unknown';
                $bankRef = sprintf('NZ-%s-%d', $datePart, $rowIndex);
            }

            $email = $get('cardholder email');
            if ($email === '') {
                $email = $get('email');
            }

            $entries[] = ExpenseBankFeedEntry::createImported(
                publicId: ExpenseBankFeedPublicId::generate(),
                bankReference: $bankRef,
                cardholderEmail: $email,
                country: Module::COUNTRY_NZ,
                transactionDate: $txDate,
                merchantName: $merchant !== '' ? $merchant : 'Unknown',
                amount: $amount,
                currency: ExpenseCurrency::fromString($get('currency') !== '' ? $get('currency') : 'NZD'),
                uploadedAtUtc: $uploadedAt,
                postingDate: $this->parseDate($get('posting date')),
                statementDate: $stmt,
                merchantCity: $city !== '' ? $city : null,
                merchantCountry: $country !== '' ? $country : null,
                merchantPostcode: $postcode !== '' ? $postcode : null,
                foreignAmount: $get('foreign amount') !== '' ? ExpenseMoney::fromSignedString($get('foreign amount')) : null,
                foreignCurrency: $get('foreign currency') !== '' ? ExpenseCurrency::fromString($get('foreign currency')) : null,
                transactionType: $isPayment ? Module::TYPE_PAYMENT : ($txTypeRaw !== '' ? strtolower($txTypeRaw) : null),
                firstName: $get('first name') ?: null,
                lastName: $get('last name') ?: null,
            );
        }

        return $entries;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function splitDetails(string $details): array
    {
        // Legacy HSBC NZ fixed-width: merchant 22 + city 14 + country 2 (from offset 37).
        if (strlen($details) >= 39 && preg_match('/^[A-Za-z]{2}$/', trim(substr($details, 37, 2))) === 1) {
            return [
                trim(substr($details, 0, 22)),
                trim(substr($details, 23, 14)),
                strtoupper(trim(substr($details, 37, 2))),
                '',
            ];
        }

        // Fallback wider layout used by fixtures / alternate exports.
        $padded = str_pad($details, 68, ' ');
        $merchant = rtrim(substr($padded, 0, 40));
        $city = rtrim(substr($padded, 40, 25));
        $country = rtrim(substr($padded, 65, 3));
        $postcode = rtrim(substr($padded, 68));

        return [$merchant, $city, $country, $postcode];
    }

    private function isPayment(string $type, string $merchant, string $details): bool
    {
        $hay = strtolower($type . ' ' . $merchant . ' ' . $details);
        if (str_contains($hay, 'payment') || str_contains($hay, 'repayment') || str_contains($hay, 'direct debit')) {
            return true;
        }

        return strcasecmp(trim($type), Module::TYPE_PAYMENT) === 0;
    }

    private function statementDateFromFilename(string $filename): ?DateTimeImmutable
    {
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $filename, $m) === 1) {
            return $this->parseDate($m[1]);
        }
        if (preg_match('/(\d{8})/', $filename, $m) === 1) {
            return DateTimeImmutable::createFromFormat('!Ymd', $m[1]) ?: null;
        }

        return null;
    }

    /** @return list<list<string>> */
    private function readCsv(string $csvContents): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $csvContents) ?: [];
        $out = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $out[] = str_getcsv($line);
        }

        return $out;
    }

    /** @param list<string> $header @return array<string, int> */
    private function headerMap(array $header): array
    {
        $map = [];
        foreach ($header as $i => $name) {
            $map[strtolower(trim((string) $name))] = $i;
        }

        return $map;
    }

    /** @param list<string> $row */
    private function rowEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d'] as $fmt) {
            $dt = DateTimeImmutable::createFromFormat('!' . $fmt, $value);
            if ($dt instanceof DateTimeImmutable) {
                return $dt;
            }
        }

        return null;
    }
}
