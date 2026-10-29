<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure;

use TriCab\Api\Assistant\Application\AssistantGrounding;
use TriCab\Api\Assistant\Application\AssistantProviderResult;
use TriCab\Api\Assistant\Application\AssistantResponseProvider;
use TriCab\Api\Assistant\Application\GroundedProduct;

/**
 * Safe fallback used until Azure Foundry is configured or whenever the model
 * provider is unavailable. It only describes the server-authorized catalogue
 * data supplied in the grounding payload, in TriCab's own voice ("we", "our
 * range") rather than as an assistant reporting a search.
 */
final class DeterministicAssistantResponseProvider implements AssistantResponseProvider
{
    private const INSTRUCTION_VERSION = 'product-assistant-fallback-v4';

    public function generate(AssistantGrounding $grounding): AssistantProviderResult
    {
        if ($grounding->products === []) {
            if ($grounding->canonicalIntent !== null) {
                $clarification = $grounding->clarificationQuestion
                    ?? 'What application, voltage and cable construction do you need?';

                return new AssistantProviderResult(
                    text: sprintf(
                        'We don\'t currently carry any %s cable in our %s that matches that, but our sales team can help with anything outside it. %s',
                        $grounding->canonicalIntent,
                        $this->catalogueLabel($grounding),
                        $clarification,
                    ),
                    provider: 'deterministic-search',
                    instructionVersion: self::INSTRUCTION_VERSION,
                );
            }

            return new AssistantProviderResult(
                text: sprintf(
                    'We don\'t currently carry a product matching "%s" in our %s. Tell us the product name, series, code or application and we\'ll point you to the closest match, or contact our sales team for anything outside our range.',
                    $grounding->searchQuery,
                    $this->catalogueLabel($grounding),
                ),
                provider: 'deterministic-search',
                instructionVersion: self::INSTRUCTION_VERSION,
            );
        }

        if (count($grounding->products) === 1) {
            return new AssistantProviderResult(
                text: $this->describeProduct($grounding->products[0]),
                provider: 'deterministic-search',
                instructionVersion: self::INSTRUCTION_VERSION,
            );
        }

        $names = array_map(
            static fn (GroundedProduct $product): string => $product->productName,
            array_slice($grounding->products, 0, 3),
        );

        return new AssistantProviderResult(
            text: sprintf(
                'We have %d %s related to "%s". The closest are %s. Open a product card for its verified details, or tell us the voltage, application, standard or construction you need and we\'ll narrow it down.',
                count($grounding->products),
                $grounding->catalogueVisibility === 'staff'
                    ? 'products in our range, including drafts visible to staff,'
                    : 'products in our published range',
                $grounding->searchQuery,
                $this->naturalList($names),
            ),
            provider: 'deterministic-search',
            instructionVersion: self::INSTRUCTION_VERSION,
        );
    }

    private function describeProduct(GroundedProduct $product): string
    {
        $parts = [sprintf(
            strcasecmp($product->statusCode, 'published') === 0 ? 'We carry %s.' : 'We have %s on file.',
            $product->productName,
        )];

        if (strcasecmp($product->statusCode, 'published') !== 0) {
            $parts[] = sprintf(
                'This product is currently %s and is visible because you have staff catalogue access.',
                strtolower($product->statusCode),
            );
        }

        if ($product->summary !== null && trim($product->summary) !== '') {
            $parts[] = rtrim(trim($product->summary), '.') . '.';
        }

        if ($product->applications !== []) {
            $parts[] = 'Catalogue applications include ' . $this->naturalList(array_slice($product->applications, 0, 3)) . '.';
        }

        if ($product->specifications !== []) {
            $specifications = array_map(
                static fn (array $specification): string => $specification['name'] . ': ' . $specification['value'],
                array_slice($product->specifications, 0, 3),
            );
            $parts[] = 'Key catalogue details: ' . implode('; ', $specifications) . '.';
        }

        $parts[] = strcasecmp($product->statusCode, 'published') === 0
            ? 'The product card has the full published details, and our sales team can help with a quote. Please verify critical cable selection against the applicable datasheet and your engineering requirements.'
            : 'Use the staff product preview for its current catalogue details. Draft information must not be presented to customers as published, and critical cable selection should be verified against the applicable datasheet and engineering requirements.';

        return implode(' ', $parts);
    }

    /** @param list<string> $items */
    private function naturalList(array $items): string
    {
        if (count($items) < 2) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items) . ' and ' . $last;
    }

    private function catalogueLabel(AssistantGrounding $grounding): string
    {
        return $grounding->catalogueVisibility === 'staff'
            ? 'range, including drafts visible to staff,'
            : 'published range';
    }
}
