<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Ap\Domain\ApVendor;
use TriCab\Core\Finance\Ap\Domain\ApVendorInvoice;
use TriCab\Core\Finance\Ap\Domain\ApVendorMatch;
use TriCab\Core\Finance\Ap\Service\ApVendorMatcher;

/**
 * `vendor_match.py` on `test_vendor_match.py`'s ten MatcherTests, one for one with the same inputs and
 * expectations (the source's names in the docblocks), then the rules the source tests leave unsaid.
 */
final class ApVendorMatcherTest extends TestCase
{
    private function match(
        ?string $vendor = null,
        ?string $company = null,
        ?string $taxId = null,
        ?string $vendorEmail = null,
    ): ApVendorMatch {
        return ApVendorMatcher::autoMatch(
            ApVendorFixtures::invoice(vendor: $vendor, company: $company, taxId: $taxId, vendorEmail: $vendorEmail),
            ApVendorFixtures::vendors(),
            ApVendorFixtures::buyers(),
        );
    }

    /** test_exact_name_ignoring_legal_suffix_and_case */
    public function testExactNameIgnoringLegalSuffixAndCase(): void
    {
        $result = $this->match(vendor: 'JET COURIERS (TASMANIA) PTY. LTD.');
        self::assertSame([ApVendorMatch::MATCHED, 1], [$result->vendorMatch, $result->odooVendorId]);
        self::assertSame('V000001', $result->vendorRef);
        self::assertSame(2, $this->match(vendor: 'Zend Ltd')->odooVendorId);
    }

    /** test_trading_name_on_either_side */
    public function testTradingNameOnEitherSide(): void
    {
        $result = $this->match(vendor: 'Sprint Freight & Logistics');
        self::assertSame(5, $result->odooVendorId);
        self::assertStringContainsString('trading name', $result->vendorMatchNote);
    }

    /** test_tax_id_and_email_beat_a_misread_name */
    public function testTaxIdAndEmailBeatAMisreadName(): void
    {
        self::assertSame(3, $this->match(vendor: 'Go Logistic', taxId: '123456789')->odooVendorId);
        self::assertSame(2, $this->match(vendor: 'Accounts', vendorEmail: 'AR@zend.example')->odooVendorId);
    }

    /** test_near_names_are_never_applied_automatically */
    public function testNearNamesAreNeverAppliedAutomatically(): void
    {
        foreach (['Go Logistics', 'BUNNINGS warehouse', 'Capital Transport Services (VIC) Pty Ltd'] as $name) {
            self::assertSame(ApVendorMatch::UNMATCHED, $this->match(vendor: $name)->vendorMatch, $name);
        }
    }

    /** test_archived_vendor_is_not_matched */
    public function testArchivedVendorIsNotMatched(): void
    {
        self::assertSame(ApVendorMatch::UNMATCHED, $this->match(vendor: 'Old Supplies')->vendorMatch);
    }

    /** test_billed_entity_read_as_vendor_is_flagged */
    public function testBilledEntityReadAsVendorIsFlagged(): void
    {
        $result = $this->match(vendor: 'tricab', company: 'Tricab (New Zealand) Ltd');
        self::assertSame(ApVendorMatch::UNMATCHED, $result->vendorMatch);
        self::assertStringContainsString('TriCab itself', $result->vendorMatchNote);
        $result = $this->match(vendor: 'Tricab (New Zealand) Ltd', company: 'Tricab (New Zealand) Ltd');
        self::assertStringContainsString('TriCab itself', $result->vendorMatchNote);
    }

    /** test_intercompany_vendor_still_matches */
    public function testIntercompanyVendorStillMatches(): void
    {
        $result = $this->match(vendor: 'Tricab (New Zealand) Ltd', company: 'TriCab (Australia) Pty Ltd');
        self::assertSame([6, 'IC0004'], [$result->odooVendorId, $result->vendorRef]);
    }

    /** test_tricab_vendor_with_unknown_entity_is_left_for_a_person */
    public function testTricabVendorWithUnknownEntityIsLeftForAPerson(): void
    {
        $result = $this->match(vendor: 'Tricab (New Zealand) Ltd');
        self::assertSame(ApVendorMatch::UNMATCHED, $result->vendorMatch);
        self::assertStringContainsString('TriCab itself', $result->vendorMatchNote);
    }

    /** test_suggestions_rank_the_distinctive_word */
    public function testSuggestionsRankTheDistinctiveWord(): void
    {
        $ranked = ApVendorMatcher::suggestions(
            ApVendorFixtures::invoice(vendor: 'Capital Transport Services (VIC) Pty Ltd'),
            ApVendorFixtures::vendors(),
            ApVendorFixtures::buyers(),
        );
        $topTwo = array_map(static fn ($suggestion): int => $suggestion->vendor->odooId, array_slice($ranked, 0, 2));
        sort($topTwo);
        self::assertSame([7, 8], $topTwo);
        self::assertNotSame(9, $ranked[0]->vendor->odooId);
    }

    /** test_suggestions_fall_back_to_sender_when_vendor_is_the_buyer */
    public function testSuggestionsFallBackToSenderWhenVendorIsTheBuyer(): void
    {
        $ranked = ApVendorMatcher::suggestions(
            ApVendorFixtures::invoice(
                vendor: 'tricab',
                company: 'Tricab (New Zealand) Ltd',
                sender: 'accounts@golog.example',
                senderName: 'Go Logistics NZ Accounts',
            ),
            ApVendorFixtures::vendors(),
            ApVendorFixtures::buyers(),
        );
        self::assertSame(3, $ranked[0]->vendor->odooId);
        self::assertSame('Similar sender name', $ranked[0]->reason);
    }

    public function testNormalisationStepsInTheSourcesOrder(): void
    {
        self::assertSame('a and b', ApVendorMatcher::normalizeName('A&B Pty. Ltd.'));
        self::assertSame('smith', ApVendorMatcher::normalizeName('Smith P/L'));
        self::assertSame('xthe', ApVendorMatcher::normalizeName('xthe'), 'a legal word inside a longer word stays');
        self::assertSame('', ApVendorMatcher::normalizeName('The Company Ltd'));
        self::assertSame('', ApVendorMatcher::normalizeName(null));
        self::assertSame('caf 24', ApVendorMatcher::normalizeName("Caf\u{e9} 24"), 'anything outside a to z and 0 to 9 is a separator');
    }

    public function testNameVariantsSplitOnEveryTradingAsForm(): void
    {
        self::assertEqualsCanonicalizing(
            ['lochert bros', 'lochert bros t as sprint freight and logistics', 'sprint freight and logistics'],
            ApVendorMatcher::nameVariants('Lochert Bros t/as Sprint Freight & Logistics'),
        );
        self::assertEqualsCanonicalizing(['a', 'a t a b', 'b'], ApVendorMatcher::nameVariants('A  T/A: B'));
        self::assertEqualsCanonicalizing(['a', 'a trading as b', 'b'], ApVendorMatcher::nameVariants('A Trading   As B'));
        self::assertSame([], ApVendorMatcher::nameVariants(''));
        self::assertSame([], ApVendorMatcher::nameVariants('The Company Ltd'));
    }

    public function testTaxDigitsNeedEightDigits(): void
    {
        self::assertSame('123456789', ApVendorMatcher::taxDigits('ABN 123 456 789'));
        self::assertSame('', ApVendorMatcher::taxDigits('020-820'));
        self::assertSame('12345678', ApVendorMatcher::taxDigits('12345678'));
        self::assertSame('', ApVendorMatcher::taxDigits('1234567'));
        self::assertSame('', ApVendorMatcher::taxDigits(null));
    }

    public function testBuyerNamesIncludeFormerNamesAndAlwaysTricab(): void
    {
        self::assertSame(['tricab'], ApVendorMatcher::buyerNames([]));
        self::assertSame(
            ['tricab', 'tricab australia', 'old name', 'older name'],
            ApVendorMatcher::buyerNames([['name' => 'TriCab (Australia) Pty Ltd', 'former_names' => 'Old Name Pty Ltd; Older Name ;']]),
        );
    }

    public function testBuyerLikeRules(): void
    {
        $buyers = ApVendorFixtures::buyers();
        self::assertFalse(ApVendorMatcher::looksLikeBuyer(null, $buyers));
        self::assertFalse(ApVendorMatcher::looksLikeBuyer('Pty Ltd', $buyers), 'only legal words normalise to nothing');
        self::assertTrue(ApVendorMatcher::looksLikeBuyer('Tricab Ltd', $buyers, 'Somebody Else'), 'plain "tricab" is always a misread');
        self::assertFalse(ApVendorMatcher::looksLikeBuyer('Tricab Freight', $buyers, 'Somebody Else'), 'a billed entity decides when known');
        self::assertTrue(ApVendorMatcher::looksLikeBuyer('Tricab Freight', $buyers), 'unknown entity: first word tricab');
        self::assertTrue(ApVendorMatcher::looksLikeBuyer('TriCab (Australia) Pty Ltd', $buyers));
        self::assertFalse(ApVendorMatcher::looksLikeBuyer('Zend', $buyers));
    }

    public function testOrderOfRulesTaxIdThenEmailThenName(): void
    {
        // Tax id points at vendor 3, the email at vendor 2, the name at vendor 11: the tax id wins.
        $result = ApVendorMatcher::autoMatch(
            ApVendorFixtures::invoice(vendor: 'Bunnings Group Ltd', taxId: '123-456-789', vendorEmail: 'ar@zend.example'),
            ApVendorFixtures::vendors(),
            ApVendorFixtures::buyers(),
        );
        self::assertSame([3, 'Matched automatically by tax id'], [$result->odooVendorId, $result->vendorMatchNote]);
        $result = ApVendorMatcher::autoMatch(
            ApVendorFixtures::invoice(vendor: 'Bunnings Group Ltd', vendorEmail: ' AR@Zend.example '),
            ApVendorFixtures::vendors(),
            ApVendorFixtures::buyers(),
        );
        self::assertSame([2, 'Matched automatically by email'], [$result->odooVendorId, $result->vendorMatchNote]);
    }

    public function testAmbiguousEvidenceIsNeverApplied(): void
    {
        $twins = [
            ApVendorFixtures::vendor(1, 'Twin Pty Ltd', vat: '11111111'),
            ApVendorFixtures::vendor(2, 'Twin Limited', vat: '11111111', email: 'a@twin.example'),
        ];
        $match = static fn (ApVendorInvoice $invoice): ApVendorMatch => ApVendorMatcher::autoMatch($invoice, $twins, ['tricab']);
        self::assertSame(
            ApVendorMatch::UNMATCHED,
            $match(ApVendorFixtures::invoice(vendor: 'Twin', taxId: '11111111'))->vendorMatch,
            'two vendors share the tax id and the name',
        );
        self::assertSame(2, $match(ApVendorFixtures::invoice(vendor: 'Other', vendorEmail: 'A@twin.example'))->odooVendorId, 'but one has the email');
    }

    public function testNoNameReadGivesItsOwnNote(): void
    {
        self::assertSame('No vendor name was read from the invoice', $this->match(vendor: 'Pty Ltd')->vendorMatchNote);
        self::assertSame('No vendor name was read from the invoice', $this->match()->vendorMatchNote);
        self::assertSame('No certain match in Odoo; pick a vendor or mark it as new', $this->match(vendor: 'Nobody')->vendorMatchNote);
        self::assertSame(
            'The vendor read from the invoice looks like TriCab itself; pick the real vendor',
            $this->match(vendor: 'TriCab')->vendorMatchNote,
        );
    }

    public function testAnUnmatchedResultClearsTheVendorColumns(): void
    {
        $result = $this->match(vendor: 'Nobody');
        self::assertSame(
            ['odoo_vendor_id' => null, 'vendor_ref' => null, 'odoo_vendor_name' => null, 'vendor_match' => 'unmatched', 'vendor_match_note' => 'No certain match in Odoo; pick a vendor or mark it as new'],
            $result->toArray(),
        );
    }

    public function testAVendorWithoutARefMatchesWithANullRef(): void
    {
        $vendors = [ApVendorFixtures::vendor(1, 'Plain Co', ref: '')];
        $result = ApVendorMatcher::autoMatch(ApVendorFixtures::invoice(vendor: 'Plain'), $vendors, ['tricab']);
        self::assertSame([1, null, 'Plain Co'], [$result->odooVendorId, $result->vendorRef, $result->odooVendorName]);
    }

    public function testSuggestionsReasonsAndScoresForEvidenceBeyondTheName(): void
    {
        $vendors = [
            ApVendorFixtures::vendor(1, 'Zzz One', vat: '99999999'),
            ApVendorFixtures::vendor(2, 'Zzz Two', email: 'Billing@Acme.example'),
            ApVendorFixtures::vendor(3, 'Zzz Three', email: 'someone@acme.example'),
            ApVendorFixtures::vendor(4, 'Zzz Four', email: 'x@gmail.com'),
        ];
        $ranked = ApVendorMatcher::suggestions(
            ApVendorFixtures::invoice(vendor: 'Qqq', taxId: '99999999', vendorEmail: 'billing@acme.example', sender: 'Acme <ar@acme.example>'),
            $vendors,
            ['tricab'],
        );
        $summary = array_map(static fn ($s): array => [$s->vendor->odooId, $s->score, $s->reason], $ranked);
        self::assertSame([[1, 1.0, 'Same tax id'], [2, 1.0, 'Same email'], [3, 0.75, 'Email domain acme.example']], $summary);
    }

    public function testSharedMailDomainsAreNeverEvidence(): void
    {
        $vendors = [ApVendorFixtures::vendor(1, 'Zzz One', email: 'x@gmail.com')];
        self::assertSame([], ApVendorMatcher::suggestions(
            ApVendorFixtures::invoice(vendor: 'Qqq', vendorEmail: 'y@gmail.com', sender: 'z@gmail.com'),
            $vendors,
            ['tricab'],
        ));
    }

    public function testSuggestionOrderLiveBeforeArchivedOnATieThenByName(): void
    {
        $vendors = [
            ApVendorFixtures::vendor(1, 'Beta', email: 'a@same.example', active: false),
            ApVendorFixtures::vendor(2, 'alpha', email: 'a@same.example'),
            ApVendorFixtures::vendor(3, 'Gamma', email: 'a@same.example'),
        ];
        $ranked = ApVendorMatcher::suggestions(ApVendorFixtures::invoice(vendorEmail: 'q@same.example'), $vendors, ['tricab']);
        self::assertSame([2, 3, 1], array_map(static fn ($s): int => $s->vendor->odooId, $ranked));
    }

    public function testSuggestionsStopAtTheLimitAndSkipRemovedVendors(): void
    {
        $vendors = [];
        for ($id = 1; $id <= 8; $id++) {
            $vendors[] = ApVendorFixtures::vendor($id, 'Widget Works ' . $id);
        }
        $removed = new ApVendor(99, 'Widget Works', 'V99', '', '', '', true, true);
        $ranked = ApVendorMatcher::suggestions(ApVendorFixtures::invoice(vendor: 'Widget Works'), [...$vendors, $removed], ['tricab']);
        self::assertCount(5, $ranked);
        self::assertNotContains(99, array_map(static fn ($s): int => $s->vendor->odooId, $ranked));
        self::assertCount(2, ApVendorMatcher::suggestions(ApVendorFixtures::invoice(vendor: 'Widget Works'), $vendors, ['tricab'], 2));
    }

    /** Expected values are the source's `_score`, run on a copy of `vendor_match.py` (difflib included). */
    public function testScoreWeightsTheFirstWord(): void
    {
        self::assertSame(0.0, ApVendorMatcher::score('', 'a'));
        self::assertSame(0.0, ApVendorMatcher::score('a', ''));
        self::assertSame(1.0, ApVendorMatcher::score('acme', 'acme'));
        self::assertEqualsWithDelta(0.95, ApVendorMatcher::score('acme', 'acme freight'), 1e-12);
        self::assertEqualsWithDelta(0.6, ApVendorMatcher::score('capital transport', 'civic transport'), 1e-12);
        self::assertEqualsWithDelta(0.7214285714285714, ApVendorMatcher::score('abc def', 'abc xyz'), 1e-12);
    }
}
