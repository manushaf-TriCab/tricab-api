<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use JsonException;
use Throwable;
use TriCab\Api\Expense\Observability\ExpenseEventLogger;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\Expense\Application\DTO\ExpenseOcrSuggestion;
use TriCab\Core\Expense\Application\Exception\ExpenseOcrFailed;
use TriCab\Core\Expense\Contract\ExpenseCaptureAssistPort;

/**
 * Azure AI Document Intelligence extraction, with optional Azure OpenAI tidy-up.
 *
 * Both prebuilt models are tried: `prebuilt-receipt` first because till receipts
 * are the common case, then `prebuilt-invoice`, which is what a hotel or freight
 * vendor actually issues. The first model that returns usable fields wins.
 *
 * Document bytes are posted to Azure and never written to a log. Results are
 * suggestions: this class has no repository and cannot modify a receipt.
 */
final class AzureExpenseCaptureAssistPort implements ExpenseCaptureAssistPort
{
    private const API_VERSION = '2024-11-30';

    private const MODELS = ['prebuilt-receipt', 'prebuilt-invoice'];

    /** Document Intelligence is asynchronous: submit, then poll the operation. */
    private const MAX_POLL_ATTEMPTS = 20;

    private const POLL_INTERVAL_SECONDS = 1;

    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly ExpenseEventLogger $logger,
        private readonly string $docIntelEndpoint,
        private readonly string $docIntelKey,
        private readonly string $openAiEndpoint = '',
        private readonly string $openAiKey = '',
        private readonly string $openAiDeployment = '',
        private readonly bool $pauseBetweenPolls = true,
    ) {
    }

    public function isEnabled(): bool
    {
        return trim($this->docIntelEndpoint) !== '' && trim($this->docIntelKey) !== '';
    }

    public function suggestReceiptFields(string $fileContent, string $mimeType): array
    {
        if (!$this->isEnabled()) {
            throw ExpenseOcrFailed::extraction();
        }

        $this->logger->log('expense.ocr.started', [
            'mimeType' => $mimeType,
            // Named "documentSize" rather than anything containing "bytes": the
            // logger drops byte-ish keys wholesale to keep document content out.
            'documentSize' => strlen($fileContent),
        ]);

        foreach (self::MODELS as $model) {
            try {
                $analysis = $this->analyze($model, $fileContent, $mimeType);
            } catch (Throwable $failure) {
                $this->logger->log('expense.ocr.failed', [
                    'model' => $model,
                    'reason' => $failure->getMessage(),
                ]);
                continue;
            }

            $payload = self::mapAnalysis($analysis, $model);
            if (($payload['fields'] ?? []) === []) {
                continue;
            }

            $payload = $this->normalizeWithOpenAi($payload);

            $this->logger->log('expense.ocr.completed', [
                'model' => $model,
                'fieldCount' => count($payload['fields']),
            ]);

            return $payload;
        }

        $this->logger->log('expense.ocr.failed', ['reason' => 'no_model_returned_fields']);

        throw ExpenseOcrFailed::noFieldsExtracted();
    }

    public function suggestCategory(string $vendor, string $rawText, array $categories): ?string
    {
        if (!$this->isOpenAiConfigured() || $categories === []) {
            return null;
        }

        $options = [];
        foreach ($categories as $code => $label) {
            $options[] = $code . ' = ' . $label;
        }

        $prompt = "Pick the single best TriCab general-ledger expense category code for this receipt.\n"
            . "Reply with the code only, or UNKNOWN.\n\n"
            . 'Vendor: ' . $vendor . "\n"
            . 'Receipt text: ' . mb_substr($rawText, 0, 1500) . "\n\n"
            . "Codes:\n" . implode("\n", $options);

        try {
            $answer = $this->chat($prompt, 12);
        } catch (Throwable) {
            return null;
        }

        if ($answer === null) {
            return null;
        }

        $code = trim($answer);
        if (preg_match('/\b(\d{5})\b/', $code, $matches) === 1) {
            $code = $matches[1];
        }

        return array_key_exists($code, $categories) ? $code : null;
    }

    /**
     * @return array<string, mixed> the analyzeResult document payload
     */
    private function analyze(string $model, string $fileContent, string $mimeType): array
    {
        $submitUrl = sprintf(
            '%s/documentintelligence/documentModels/%s:analyze?api-version=%s',
            rtrim($this->docIntelEndpoint, '/'),
            rawurlencode($model),
            self::API_VERSION,
        );

        // base64Source keeps this on the JSON client rather than needing a
        // binary-capable transport, and keeps the bytes out of the URL.
        $submitted = $this->http->postJson(
            $submitUrl,
            [
                'Ocp-Apim-Subscription-Key' => $this->docIntelKey,
            ],
            ['base64Source' => base64_encode($fileContent)],
        );

        if ($submitted['status'] < 200 || $submitted['status'] > 299) {
            throw ExpenseOcrFailed::extraction();
        }

        $operationUrl = ($submitted['headers'] ?? [])['operation-location'] ?? null;
        if (!is_string($operationUrl) || $operationUrl === '') {
            throw ExpenseOcrFailed::extraction();
        }

        for ($attempt = 0; $attempt < self::MAX_POLL_ATTEMPTS; $attempt++) {
            if ($attempt > 0 && $this->pauseBetweenPolls) {
                sleep(self::POLL_INTERVAL_SECONDS);
            }

            $polled = $this->http->getJson($operationUrl, [
                'Ocp-Apim-Subscription-Key' => $this->docIntelKey,
            ]);
            if ($polled['status'] < 200 || $polled['status'] > 299) {
                throw ExpenseOcrFailed::extraction();
            }

            $decoded = self::decode($polled['body']);
            $status = is_string($decoded['status'] ?? null) ? strtolower($decoded['status']) : '';

            if ($status === 'succeeded') {
                $result = $decoded['analyzeResult'] ?? null;

                return is_array($result) ? $result : [];
            }
            if ($status === 'failed') {
                throw ExpenseOcrFailed::extraction();
            }
        }

        throw ExpenseOcrFailed::extraction();
    }

    /**
     * @param array<string, mixed> $analyzeResult
     * @return array{fields: array<string, array{value: string|null, confidence: float|null, source: string}>, warnings: list<string>, rawText: string}
     */
    private static function mapAnalysis(array $analyzeResult, string $model): array
    {
        $documents = is_array($analyzeResult['documents'] ?? null) ? $analyzeResult['documents'] : [];
        $document = is_array($documents[0] ?? null) ? $documents[0] : [];
        $docFields = is_array($document['fields'] ?? null) ? $document['fields'] : [];

        // prebuilt-receipt and prebuilt-invoice name the same concepts differently.
        $map = [
            'vendorName' => ['MerchantName', 'VendorName'],
            'receiptDate' => ['TransactionDate', 'InvoiceDate'],
            'totalAmount' => ['Total', 'InvoiceTotal', 'AmountDue'],
            'gstAmount' => ['TotalTax', 'TotalTax:amount'],
            'currency' => ['Total', 'InvoiceTotal'],
        ];

        $fields = [];
        foreach ($map as $target => $candidates) {
            foreach ($candidates as $candidate) {
                $raw = $docFields[$candidate] ?? null;
                if (!is_array($raw)) {
                    continue;
                }
                $value = $target === 'currency'
                    ? self::currencyOf($raw)
                    : self::scalarOf($raw);
                if ($value === null) {
                    continue;
                }
                $fields[$target] = [
                    'value' => $value,
                    'confidence' => self::confidenceOf($raw),
                    'source' => ExpenseOcrSuggestion::SOURCE_DOCUMENT_INTELLIGENCE,
                ];
                break;
            }
        }

        $warnings = [];
        if (!isset($fields['totalAmount'])) {
            $warnings[] = 'total_not_detected';
        }

        return [
            'fields' => $fields,
            'warnings' => $warnings,
            'rawText' => is_string($analyzeResult['content'] ?? null)
                ? mb_substr($analyzeResult['content'], 0, 4000)
                : '',
            'model' => $model,
        ];
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function scalarOf(array $field): ?string
    {
        foreach (['valueString', 'valueDate', 'content'] as $key) {
            $value = $field[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $number = $field['valueNumber'] ?? null;
        if (is_int($number) || is_float($number)) {
            return number_format((float) $number, 2, '.', '');
        }

        $currency = $field['valueCurrency'] ?? null;
        if (is_array($currency)) {
            $amount = $currency['amount'] ?? null;
            if (is_int($amount) || is_float($amount)) {
                return number_format((float) $amount, 2, '.', '');
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function currencyOf(array $field): ?string
    {
        $currency = $field['valueCurrency'] ?? null;
        if (!is_array($currency)) {
            return null;
        }
        $code = $currency['currencyCode'] ?? null;

        return is_string($code) && trim($code) !== '' ? strtoupper(trim($code)) : null;
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function confidenceOf(array $field): ?float
    {
        $confidence = $field['confidence'] ?? null;

        return is_int($confidence) || is_float($confidence) ? (float) $confidence : null;
    }

    /**
     * Optional pass to tidy vendor names ("WOOLWORTHS 1234 MELB" → "Woolworths")
     * and coerce dates to ISO. It may only replace values that are already
     * present; it cannot invent a field the extractor did not find.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeWithOpenAi(array $payload): array
    {
        if (!$this->isOpenAiConfigured()) {
            return $payload;
        }

        /** @var array<string, array{value: string|null, confidence: float|null, source: string}> $fields */
        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
        $current = [];
        foreach ($fields as $name => $field) {
            $current[$name] = $field['value'];
        }

        $prompt = "Normalise these values extracted from an expense receipt.\n"
            . "Rules: vendorName is the trading name without store numbers or suburbs; "
            . "receiptDate is YYYY-MM-DD; totalAmount and gstAmount are plain decimals with no currency symbol; "
            . "currency is a 3-letter ISO code. Keep a value unchanged if you are unsure. "
            . "Reply with a JSON object using exactly these keys and no commentary.\n\n"
            . json_encode($current, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        try {
            $answer = $this->chat($prompt, 400);
        } catch (Throwable) {
            $payload['warnings'] = [...(array) ($payload['warnings'] ?? []), 'openai_normalisation_unavailable'];

            return $payload;
        }

        if ($answer === null) {
            return $payload;
        }

        $normalized = self::decodeLoose($answer);
        foreach ($normalized as $name => $value) {
            if (!isset($fields[$name]) || !is_string($value) || trim($value) === '') {
                continue;
            }
            $fields[$name] = [
                'value' => trim($value),
                'confidence' => $fields[$name]['confidence'],
                'source' => ExpenseOcrSuggestion::SOURCE_OPENAI,
            ];
        }

        $payload['fields'] = $fields;

        return $payload;
    }

    private function isOpenAiConfigured(): bool
    {
        return trim($this->openAiEndpoint) !== ''
            && trim($this->openAiKey) !== ''
            && trim($this->openAiDeployment) !== '';
    }

    private function chat(string $prompt, int $maxTokens): ?string
    {
        $url = sprintf(
            '%s/openai/deployments/%s/chat/completions?api-version=2024-10-21',
            rtrim($this->openAiEndpoint, '/'),
            rawurlencode($this->openAiDeployment),
        );

        $response = $this->http->postJson(
            $url,
            ['api-key' => $this->openAiKey],
            [
                'messages' => [
                    ['role' => 'system', 'content' => 'You normalise expense receipt data. Reply with data only.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0,
                'max_tokens' => $maxTokens,
            ],
        );

        if ($response['status'] < 200 || $response['status'] > 299) {
            return null;
        }

        $decoded = self::decode($response['body']);
        $choices = is_array($decoded['choices'] ?? null) ? $decoded['choices'] : [];
        $message = is_array($choices[0]['message'] ?? null) ? $choices[0]['message'] : [];
        $content = $message['content'] ?? null;

        return is_string($content) && trim($content) !== '' ? trim($content) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $body): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Models sometimes wrap JSON in a fenced block despite being asked not to.
     *
     * @return array<string, mixed>
     */
    private static function decodeLoose(string $answer): array
    {
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }

        return self::decode(substr($answer, $start, $end - $start + 1));
    }
}
