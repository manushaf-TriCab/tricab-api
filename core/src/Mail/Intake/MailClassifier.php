<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Intake;

/** Purpose `rfq_mail_classify`. */
interface MailClassifier
{
    /** @throws ClassifierFailed */
    public function classify(ClassifierInput $input): ClassifierVerdict;

    /** The model's name as recorded on the assessment, even when a call fails. */
    public function modelName(): string;

    /** The prompt version as recorded on the assessment. */
    public function promptVersion(): string;
}
