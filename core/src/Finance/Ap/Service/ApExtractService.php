<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use TriCab\Core\Finance\Ap\ApAiService;
use TriCab\Core\Finance\Ap\ApExtractionCompany;
use TriCab\Core\Finance\Ap\Contract\ApReferenceStore;
use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;
use TriCab\Core\Finance\Ap\Domain\ApReferenceSpec;
use TriCab\Core\Finance\Ap\Domain\ApUpstream;
use TriCab\Core\Finance\Shared\Ai\FinanceAiFailure;

/**
 * `POST /extract` (`app.py` route 48, decision Q8): the capture dialog asks the model to read the file the person just
 * uploaded. The body is `{upload_id}` and nothing else is read: the source let the browser send its own prompt, and no page
 * does, so a `prompt` key is ignored and the model is always given the source's own instructions.
 *
 * The upload must be the person's own, still unattached: the same test and the same refusal as `POST /invoices` with an
 * `upload_id` ({@see ApAttachmentService::claim()}), so a foreign or already-used upload reveals nothing. The companies the
 * model is told about, and the one the answer is matched to, are the active rows of the Settings list.
 */
final class ApExtractService
{
    public const UPLOAD_REQUIRED = 'upload_id is required';

    public function __construct(
        private readonly ApAttachmentService $attachments,
        private readonly ApAiService $ai,
        private readonly ApReferenceStore $references,
    ) {
    }

    /**
     * The extraction fields the capture dialog reads: `invoice_no, vendor, company, vendor_email, tax_id, invoice_date,
     * due_date, po_number, currency, net_amount, tax_amount, total_amount, extraction_confidence, notes, review_reason`.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws ApInvalid when `upload_id` is missing, not text, unknown, someone else's or already on an invoice
     * @throws ApUpstream the model failed (the message is the AI service's own safe text)
     */
    public function extract(ApActor $actor, array $body): array
    {
        $uploadId = $body['upload_id'] ?? null;
        if (!ApRules::truthy($uploadId)) {
            throw new ApInvalid(self::UPLOAD_REQUIRED);
        }
        if (!is_string($uploadId)) {
            throw new ApInvalid('upload_id must be text');
        }
        $upload = $this->attachments->claim($actor, $uploadId);
        $file = $this->attachments->read($actor->userId, $upload->id) ?? throw new ApNotFound(ApAttachmentService::MISSING);

        try {
            $extraction = $this->ai->extract($upload->originalName, $upload->contentType, $file->bytes, $this->companies());
        } catch (FinanceAiFailure $failure) {
            throw new ApUpstream($failure->getMessage(), ApUpstream::AI, $failure);
        }

        return $extraction->toArray();
    }

    /**
     * The newest 200 companies (`_active_company_names()`), inactive ones included: the prompt names the active ones and the
     * answer is matched against them.
     *
     * @return list<ApExtractionCompany>
     */
    private function companies(): array
    {
        $companies = [];
        foreach ($this->references->rows(ApReferenceSpec::for(ApReferenceSpec::COMPANIES), 'created_date', true, 200) as $row) {
            $companies[] = new ApExtractionCompany(
                $row->values['name'] ?? '',
                ($row->values['code'] ?? '') !== '' ? $row->values['code'] : null,
                $row->values['sites'] ?? '',
                $row->values['former_names'] ?? '',
                $row->active,
            );
        }

        return $companies;
    }
}
