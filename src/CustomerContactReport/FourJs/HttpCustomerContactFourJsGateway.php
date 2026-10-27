<?php

declare(strict_types=1);

namespace TriCab\Api\CustomerContactReport\FourJs;

use TriCab\Core\CustomerContactReport\Contract\CustomerContactFourJsGateway;
use TriCab\Core\CustomerContactReport\Domain\FourJs\FourJsUnavailable;

/**
 * Calls P008btch on 4JS: ?live=1&type=ORDOCR|INVOCR&ref=<digits>.
 * 8 second ceiling, no redirects, http(s) only. Anything but a 200 is
 * "unavailable" and callers fail closed. The URL is configuration; there is no
 * built-in internal address.
 */
final class HttpCustomerContactFourJsGateway implements CustomerContactFourJsGateway
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly bool $live = true,
        private readonly float $timeoutSeconds = 8.0,
    ) {
    }

    public function fetch(string $type, string $ref): string
    {
        $scheme = strtolower((string) parse_url($this->baseUrl, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new FourJsUnavailable('4JS is not configured.');
        }
        if (!in_array($type, ['ORDOCR', 'INVOCR'], true) || preg_match('/^\d{1,12}$/', $ref) !== 1) {
            throw new FourJsUnavailable('Invalid 4JS request.');
        }
        $url = $this->baseUrl . (str_contains($this->baseUrl, '?') ? '&' : '?')
            . http_build_query(['live' => $this->live ? '1' : '0', 'type' => $type, 'ref' => $ref]);

        $curl = curl_init($url);
        if ($curl === false) {
            throw new FourJsUnavailable('4JS request could not start.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ceil($this->timeoutSeconds),
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (!is_string($body) || $status !== 200) {
            throw new FourJsUnavailable('4JS did not answer.');
        }

        return $body;
    }
}
