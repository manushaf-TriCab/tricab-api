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
 * AU corporate-card CSV parser.
 *
 * Expected columns (header names, case-insensitive):
 * Bank Reference, Cardholder Email, First Name, Last Name, Transaction Date,
 * Posting Date, Statement Date, Merchant Name, Merchant City, Merchant Country,
 * Merchant Postcode, Amount, Currency, Foreign Amount, Foreign Currency,
 * Net Amount, Tax Amount, Transaction Type, Merchant Category Group
 */
final class ExpenseAuBankFeedCsvParser
{
    /**
     * @return list<ExpenseBankFeedEntry>
     */
    public function parse(string $csvContents, DateTimeImmutable $uploadedAt): array
    {
        $rows = $this->readCsv($csvContents);
        if ($rows === []) {
            return [];
        }

        $header = array_shift($rows);
        $map = $this->headerMap($header);
        $entries = [];

        foreach ($rows as $row) {
            if ($this->rowEmpty($row)) {
                continue;
            }
            $get = function (string $key) use ($row, $map): string {
                $aliases = match ($key) {
                    'cardholder email' => ['cardholder email', 'email'],
                    'amount' => ['amount', 'consolidated amount'],
                    'currency' => ['currency', 'consolidated currency'],
                    default => [$key],
                };
                foreach ($aliases as $alias) {
                    if (isset($map[$alias])) {
                        return trim((string) ($row[$map[$alias]] ?? ''));
                    }
                }

                return '';
            };

            $bankRef = $get('bank reference');
            if ($bankRef === '') {
                continue;
            }

            $txType = $get('transaction type');
            $amountRaw = $get('amount');
            if ($amountRaw === '') {
                continue;
            }

            $entries[] = ExpenseBankFeedEntry::createImported(
                publicId: ExpenseBankFeedPublicId::generate(),
                bankReference: $bankRef,
                cardholderEmail: $get('cardholder email'),
                country: Module::COUNTRY_AU,
                transactionDate: $this->parseDate($get('transaction date')),
                merchantName: $get('merchant name') !== '' ? $get('merchant name') : 'Unknown',
                amount: ExpenseMoney::fromSignedString($amountRaw),
                currency: ExpenseCurrency::fromString($get('currency') !== '' ? $get('currency') : 'AUD'),
                uploadedAtUtc: $uploadedAt,
                postingDate: $this->parseDate($get('posting date')),
                statementDate: $this->parseDate($get('statement date')),
                merchantCity: $get('merchant city') ?: null,
                merchantCountry: $get('merchant country') ?: null,
                merchantPostcode: $get('merchant postcode') ?: null,
                foreignAmount: $get('foreign amount') !== '' ? ExpenseMoney::fromSignedString($get('foreign amount')) : null,
                foreignCurrency: $get('foreign currency') !== '' ? ExpenseCurrency::fromString($get('foreign currency')) : null,
                netAmount: $get('net amount') !== '' ? ExpenseMoney::fromSignedString($get('net amount')) : null,
                taxAmount: $get('tax amount') !== '' ? ExpenseMoney::fromSignedString($get('tax amount')) : null,
                transactionType: $txType !== '' ? strtolower($txType) : null,
                merchantCategoryGroup: $get('merchant category group') ?: null,
                firstName: $get('first name') ?: null,
                lastName: $get('last name') ?: null,
            );
        }

        return $entries;
    }

    /**
     * @return list<list<string>>
     */
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

    /**
     * @param list<string> $header
     * @return array<string, int>
     */
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
