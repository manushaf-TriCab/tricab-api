<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Storage;

use DateTimeImmutable;
use TriCab\Core\Rfq\Application\DTO\RfqCustomerOption;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;

/**
 * The Quote Details panel as a person filled it in (parity plan E2.3), and
 * how it lands on the stored header: the source's `collectQuoteDetails` keys.
 *
 * | Input               | Stored `quote_details` key                 |
 * |---------------------|--------------------------------------------|
 * | `customerId`        | `customer_id`, `customer_code`, `customer_name` |
 * | `contact`, `contactPhone` | `contact`, `phone`                   |
 * | `author`            | `author`                                   |
 * | `salesOffice`       | `salesoffice`                              |
 * | `payTerms`          | `payterms`                                 |
 * | `validTill` (`YYYY-MM-DD`) | `validtill`, as the source's `dd/mm/yy` |
 * | `customerReference` | `cusref` (Project Ref)                     |
 * | `comment`           | `comment`                                  |
 * | `region`, `currency`| `region`, `currency`                       |
 *
 * **The customer is named by its 4js id only.** The code and name come from
 * the customer book on the server, never from the browser: 4js codes are not
 * unique, and a code typed beside the wrong id would raise the quote against
 * another customer. The name is the customer's name (the source stores
 * whatever its input box showed, which is the code), because the queue reads
 * `customer_name` as the customer's name.
 *
 * Every other key the stored header carries (`directives`, and whatever an
 * email intake wrote) is kept as it is. The author is the worker's operator
 * code where 4js has one ({@see SalesRepAttribution}, applied by the store):
 * what is typed here only stands when it does not.
 */
final class QuoteHeaderEdit
{
    /** The source's `REGION_PROFILE` keys, and the currency its editor pairs with each (`currencyForRegion`). */
    public const REGIONS = ['australia' => 'AUD', 'newzealand' => 'NZD', 'usa' => 'USD', 'asia' => 'SGD'];

    public const CURRENCIES = ['AUD', 'NZD', 'USD', 'SGD'];

    private const LIMITS = [
        'contact' => 100,
        'contactPhone' => 40,
        'author' => 20,
        'salesOffice' => 10,
        'payTerms' => 20,
        'customerReference' => 100,
        'comment' => 500,
    ];

    private function __construct(
        public readonly ?int $customerId,
        public readonly string $contact,
        public readonly string $contactPhone,
        public readonly string $author,
        public readonly string $salesOffice,
        public readonly string $payTerms,
        public readonly ?DateTimeImmutable $validTill,
        public readonly string $customerReference,
        public readonly string $comment,
        public readonly string $region,
        public readonly string $currency,
    ) {
    }

    /**
     * @throws InvalidRfqValue naming the first field that is wrong
     */
    public static function fromInput(mixed $input): self
    {
        if (!is_array($input)) {
            throw new InvalidRfqValue('header must be an object.');
        }
        $customerId = $input['customerId'] ?? null;
        if ($customerId !== null && (!is_int($customerId) || $customerId < 1)) {
            throw new InvalidRfqValue('header.customerId must be a 4js customer id, or null.');
        }

        $text = [];
        foreach (self::LIMITS as $field => $limit) {
            $value = $input[$field] ?? '';
            if (!is_string($value)) {
                throw new InvalidRfqValue(sprintf('header.%s must be text.', $field));
            }
            $value = PythonText::strip($value);
            if (mb_strlen($value) > $limit) {
                throw new InvalidRfqValue(sprintf('header.%s is longer than %d characters.', $field, $limit));
            }
            $text[$field] = $value;
        }

        $validTill = $input['validTill'] ?? '';
        if (!is_string($validTill)) {
            throw new InvalidRfqValue('header.validTill must be a date (YYYY-MM-DD), or blank.');
        }
        $date = null;
        if (trim($validTill) !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($validTill));
            if ($date === false || $date->format('Y-m-d') !== trim($validTill)) {
                throw new InvalidRfqValue('header.validTill must be a date (YYYY-MM-DD), or blank.');
            }
        }

        $region = $input['region'] ?? 'australia';
        if (!is_string($region) || !array_key_exists($region, self::REGIONS)) {
            throw InvalidRfqValue::forOneOf('header.region', is_string($region) ? $region : get_debug_type($region), array_keys(self::REGIONS));
        }
        $currency = $input['currency'] ?? self::REGIONS[$region];
        if (!is_string($currency) || !in_array($currency, self::CURRENCIES, true)) {
            throw InvalidRfqValue::forOneOf('header.currency', is_string($currency) ? $currency : get_debug_type($currency), self::CURRENCIES);
        }

        return new self(
            $customerId,
            $text['contact'],
            $text['contactPhone'],
            PythonText::upper($text['author']),
            PythonText::upper($text['salesOffice']),
            PythonText::upper($text['payTerms']),
            $date,
            $text['customerReference'],
            $text['comment'],
            $region,
            $currency,
        );
    }

    /**
     * @param array<string, mixed> $stored   the header as stored (`[]` for a new draft)
     * @param RfqCustomerOption|null $customer the customer `customerId` names, as the customer book has it
     *
     * @return array<string, mixed>
     */
    public function applyTo(array $stored, ?RfqCustomerOption $customer): array
    {
        if ($this->customerId !== null && ($customer === null || $customer->id !== $this->customerId)) {
            throw new \LogicException('applyTo needs the customer that customerId names.');
        }
        $stored['customer_id'] = $customer === null ? '' : (string) $customer->id;
        $stored['customer_code'] = $customer === null ? '' : $customer->code;
        $stored['customer_name'] = $customer === null ? '' : $customer->name;
        $stored['contact'] = $this->contact;
        $stored['phone'] = $this->contactPhone;
        $stored['cusref'] = $this->customerReference;
        $stored['author'] = $this->author;
        $stored['salesoffice'] = $this->salesOffice;
        $stored['payterms'] = $this->payTerms;
        $stored['validtill'] = $this->validTill?->format('d/m/y') ?? '';
        $stored['comment'] = $this->comment;
        $stored['region'] = $this->region;
        $stored['currency'] = $this->currency;

        return $stored;
    }

    /** The source's `dd/mm/yy` back as `YYYY-MM-DD` for a date input; null for anything else. */
    public static function isoValidTill(mixed $stored): ?string
    {
        if (!is_string($stored)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!d/m/y', trim($stored));

        return $date === false || $date->format('d/m/y') !== trim($stored) ? null : $date->format('Y-m-d');
    }
}
