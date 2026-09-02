<?php

declare(strict_types=1);

namespace TriCab\Core\ProductFinder\Application\DTO;

/**
 * The finder's answer, as the API hands it to a caller.
 *
 * The finder is a Python service with a settled response shape that
 * `product-finder/preview.html` already drives, so this deliberately carries
 * that shape rather than re-modelling it: re-typing eleven nested structures in
 * PHP would buy nothing but a second place to update whenever the finder learns
 * to say something new. What is modelled is the part PHP reasons about — did we
 * get an answer, and can we size at all.
 *
 * `unavailable()` is the degraded case. The finder being down is not an error
 * the customer caused, and a 500 would take the whole enquiry with it, so the
 * request still succeeds and says sizing could not run.
 */
final readonly class ProductFinderResult
{
    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        public bool $available,
        public array $data,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromFinder(array $data): self
    {
        return new self(true, $data);
    }

    public static function unavailable(string $detail): self
    {
        return new self(false, [
            'status' => 'unavailable',
            'productCode' => null,
            'stocked' => null,
            'confidence' => 0.0,
            'style' => null,
            'codeSegments' => [],
            'description' => null,
            'sizing' => ['available' => false, 'detail' => $detail],
            'understood' => [],
            'inferredFields' => [],
            'warnings' => [$detail],
            'alternatives' => [],
            'detail' => $detail,
        ]);
    }

    public function productCode(): ?string
    {
        $code = $this->data['productCode'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    public function status(): string
    {
        $status = $this->data['status'] ?? null;

        return is_string($status) && $status !== '' ? $status : 'unresolved';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
