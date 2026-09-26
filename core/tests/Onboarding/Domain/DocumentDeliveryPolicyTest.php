<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Domain;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Service\DocumentDeliveryPolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * Owner decision, 21 September 2026, open question 7: payroll and restricted-HR
 * documents are streamed through an authenticated endpoint and audited; the rest
 * may be handed out as a short-lived signed URL.
 *
 * The property worth holding is the one that is easy to lose later, when
 * somebody is optimising bandwidth: a SAS URL is a bearer capability, so
 * "somebody with this link opened it" is the best answer it can ever give. That
 * is fine for a policy declaration and not fine for a medical questionnaire.
 */
final class DocumentDeliveryPolicyTest extends TestCase
{
    public function test_payroll_documents_are_streamed_and_audited(): void
    {
        $class = DataClass::payroll();

        self::assertSame(DocumentDeliveryPolicy::STREAM, DocumentDeliveryPolicy::modeFor($class));
        self::assertTrue(DocumentDeliveryPolicy::mustBeAudited($class));
        self::assertFalse(DocumentDeliveryPolicy::maySignUrl($class));
    }

    public function test_restricted_hr_documents_are_streamed_and_audited(): void
    {
        $class = DataClass::restrictedHr();

        self::assertSame(DocumentDeliveryPolicy::STREAM, DocumentDeliveryPolicy::modeFor($class));
        self::assertTrue(DocumentDeliveryPolicy::mustBeAudited($class));
        self::assertFalse(DocumentDeliveryPolicy::maySignUrl($class));
    }

    public function test_ordinary_documents_may_be_signed_but_are_streamed(): void
    {
        // Streamed since 26 Sep 2026: a signed URL is fetched straight from blob
        // storage, which has no CORS rule for tricab.ai, so the trainee's download
        // failed. Signing stays permitted; the audit rule is unchanged.
        foreach ([DataClass::content(), DataClass::workflow(), DataClass::ordinaryHr()] as $class) {
            self::assertSame(DocumentDeliveryPolicy::STREAM, DocumentDeliveryPolicy::modeFor($class), $class->code());
            self::assertTrue(DocumentDeliveryPolicy::maySignUrl($class));
            self::assertFalse(DocumentDeliveryPolicy::mustBeAudited($class));
        }
    }

    /**
     * A document's class is its content's class, so a payroll PDF arrives as C3.
     * A row still carrying C5 means nobody classified it — and an unclassified
     * document is more likely to be the sensitive one, because the harmless ones
     * are the ones people remember to classify.
     */
    public function test_an_unclassified_generated_document_fails_closed(): void
    {
        $class = DataClass::generatedDocument();

        self::assertSame(DocumentDeliveryPolicy::STREAM, DocumentDeliveryPolicy::modeFor($class));
        self::assertTrue(DocumentDeliveryPolicy::mustBeAudited($class));
        self::assertFalse(DocumentDeliveryPolicy::maySignUrl($class));
    }

    public function test_every_class_resolves_to_exactly_one_mode(): void
    {
        foreach (DataClass::ALL as $value) {
            $mode = DocumentDeliveryPolicy::modeFor(DataClass::fromInt($value));

            self::assertContains(
                $mode,
                [DocumentDeliveryPolicy::STREAM, DocumentDeliveryPolicy::SIGNED_URL],
            );
        }
    }

    public function test_signing_and_streaming_are_never_both_allowed(): void
    {
        foreach (DataClass::ALL as $value) {
            $class = DataClass::fromInt($value);

            self::assertNotSame(
                DocumentDeliveryPolicy::maySignUrl($class),
                DocumentDeliveryPolicy::mustBeStreamed($class),
            );
        }
    }

    // ----------------------------------------------------------- the class itself

    public function test_the_class_numbers_are_the_databases(): void
    {
        self::assertSame([0, 1, 2, 3, 4, 5], DataClass::ALL);
        self::assertSame('C3', DataClass::payroll()->code());
        self::assertSame('C4', DataClass::restrictedHr()->code());
    }

    public function test_only_payroll_and_restricted_hr_are_restricted(): void
    {
        self::assertTrue(DataClass::payroll()->isRestricted());
        self::assertTrue(DataClass::restrictedHr()->isRestricted());

        foreach ([
            DataClass::content(),
            DataClass::workflow(),
            DataClass::ordinaryHr(),
            DataClass::generatedDocument(),
        ] as $class) {
            self::assertFalse($class->isRestricted(), $class->code() . ' does not live in onboarding_hr.');
        }
    }

    public function test_an_invented_class_is_refused(): void
    {
        $this->expectException(InvalidOnboardingValue::class);

        DataClass::fromInt(6);
    }
}
