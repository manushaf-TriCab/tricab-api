<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail\Intake;

use TriCab\Core\Mail\Intake\ClassifierFailed;
use TriCab\Core\Mail\Intake\ClassifierInput;
use TriCab\Core\Mail\Intake\ClassifierVerdict;
use TriCab\Core\Mail\Intake\MailClassifier;

class ScriptedClassifier implements MailClassifier
{
    /** @var list<ClassifierInput> */
    public array $inputs = [];

    /** @var list<ClassifierVerdict|ClassifierFailed> */
    public array $answers = [];

    public ?\Throwable $throw = null;

    public function classify(ClassifierInput $input): ClassifierVerdict
    {
        $this->inputs[] = $input;
        if ($this->throw !== null) {
            throw $this->throw;
        }
        $answer = array_shift($this->answers) ?? new ClassifierVerdict('rfq', 0.93, 'Asks for a price on named cable.', false, 'claude-haiku-5-5', 'rfq-mail-classify-v1', 420);
        if ($answer instanceof ClassifierFailed) {
            throw $answer;
        }

        return $answer;
    }

    public function modelName(): string
    {
        return 'claude-haiku-5-5';
    }

    public function promptVersion(): string
    {
        return 'rfq-mail-classify-v1';
    }
}
