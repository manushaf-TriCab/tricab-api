<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use TriCab\Core\CustomerContactReport\Contract\CustomerContactFourJsGateway;
use TriCab\Core\CustomerContactReport\Domain\FourJs\FourJsDocument;
use TriCab\Core\CustomerContactReport\Domain\FourJs\FourJsOcrParser;
use TriCab\Core\CustomerContactReport\Domain\FourJs\FourJsUnavailable;

/**
 * Order and invoice lookups. Results are kept for the life of the object so
 * ticking lines and saving reuse one 4JS call per document within a request.
 *
 * A number is digits only, at most 12. "O-639952" and "639952 " are the same.
 * An invoice number is its order number plus a 2-digit sequence.
 */
final class CustomerContactFourJsLookup
{
    private const MAX_INVOICES_PER_ORDER = 30;

    /** @var array<string, ?FourJsDocument> */
    private array $cache = [];

    public function __construct(
        private readonly CustomerContactFourJsGateway $gateway,
    ) {
    }

    public static function reference(string $raw): string
    {
        $digits = FourJsOcrParser::digits($raw);

        return ($digits !== '' && strlen($digits) <= 12) ? $digits : '';
    }

    /**
     * @return ?FourJsDocument null when 4JS answers "not found" or the number is invalid
     * @throws FourJsUnavailable
     */
    public function order(string $raw): ?FourJsDocument
    {
        return $this->get('ORDOCR', $raw);
    }

    /**
     * @return ?FourJsDocument null when 4JS answers "not found" or the number is invalid
     * @throws FourJsUnavailable
     */
    public function invoice(string $raw): ?FourJsDocument
    {
        return $this->get('INVOCR', $raw);
    }

    /**
     * <order>01, 02, ... until the first that does not exist, capped at 30.
     *
     * @return list<FourJsDocument>
     * @throws FourJsUnavailable
     */
    public function orderInvoices(string $orderRaw): array
    {
        $order = self::reference($orderRaw);
        $out = [];
        if ($order === '') {
            return $out;
        }
        for ($seq = 1; $seq <= self::MAX_INVOICES_PER_ORDER; $seq++) {
            $invoice = $this->invoice($order . str_pad((string) $seq, 2, '0', STR_PAD_LEFT));
            if ($invoice === null) {
                break;
            }
            $out[] = $invoice;
        }

        return $out;
    }

    /**
     * @param 'ORDOCR'|'INVOCR' $type
     */
    private function get(string $type, string $raw): ?FourJsDocument
    {
        $ref = self::reference($raw);
        if ($ref === '') {
            return null;
        }
        $key = $type . ':' . $ref;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        $body = $this->gateway->fetch($type, $ref);
        $document = $type === 'ORDOCR' ? FourJsOcrParser::order($body) : FourJsOcrParser::invoice($body);

        return $this->cache[$key] = $document;
    }
}
