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
 * data supplied in the grounding payload.
 */
final class DeterministicAssistantResponseProvider implements AssistantResponseProvider
{
    private const INSTRUCTION_VERSION = 'product-assistant-fallback-v3';

    public function generate(AssistantGrounding $grounding): AssistantProviderResult
    {
        if ($grounding->products === []) {
            if ($grounding->canonicalIntent !== null) {
                $clarification = $grounding->clarificationQuestion
                    ?? 'What application, voltage and cable construction do you need?';

                return new AssistantProviderResult(
                    text: sprintf(
                        'I understood your request as %s, but I could not find a matching product in the %s. %s',
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
                    'I could not find a TriCab product matching "%s" in the %s. Try a product name, category, series or code.',
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
                'I found %d %s related to "%s". The closest matches are %s. Open a product card for its verified details, or tell me the voltage, application, standard, or construction you need.',
                count($grounding->products),
                $grounding->catalogueVisibility === 'staff'
                    ? 'TriCab products visible to staff'
                    : 'published TriCab products',
                $grounding->searchQuery,
                $this->naturalList($names),
            ),
            provider: 'deterministic-search',
            instructionVersion: self::INSTRUCTION_VERSION,
        );
    }

    private function describeProduct(GroundedProduct $product): string
    {
        $parts = [sprintf('I found %s.', $product->productName)];

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
            ? 'Use the product card for the full published details. Critical cable selection should be verified against the applicable datasheet and engineering requirements.'
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
            ? 'staff TriCab catalogue, including drafts'
            : 'currently published TriCab catalogue';
    }
}
