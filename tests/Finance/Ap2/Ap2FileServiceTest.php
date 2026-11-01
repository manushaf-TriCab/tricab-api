<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Attachments\InMemoryFinanceAttachmentStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2NotFound;
use TriCab\Core\Finance\Ap2\Service\Ap2FileService;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService;

final class Ap2FileServiceTest extends TestCase
{
    private FinanceAttachmentService $attachments;

    private Ap2FileService $files;

    protected function setUp(): void
    {
        $this->attachments = new FinanceAttachmentService(new InMemoryFinanceAttachmentStore());
        $this->files = new Ap2FileService($this->attachments);
    }

    public function test_an_ap2_mail_file_is_read(): void
    {
        $file = $this->files->store('a.pdf', 'application/pdf', '%PDF-1 a', 1);
        $this->files->attachToMail($file->id, 'mail1', 1);

        self::assertSame('%PDF-1 a', $this->files->read($file->id, 5)->bytes);
    }

    public function test_other_apps_files_and_ap2_files_not_on_a_mail_are_not_found(): void
    {
        $ar = $this->attachments->upload(Module::APP_AR, 'x.pdf', 'application/pdf', '%PDF-1 ar', 1);
        $this->attachments->attach(Module::APP_AR, $ar->id, 'email_record', 'e1', 1);
        $loose = $this->attachments->upload(Module::APP_AP2, 'y.pdf', 'application/pdf', '%PDF-1 loose', 1);
        $other = $this->attachments->upload(Module::APP_AP2, 'z.pdf', 'application/pdf', '%PDF-1 z', 1);
        $this->attachments->attach(Module::APP_AP2, $other->id, 'invoice', 'e2', 1);

        foreach ([$ar->id, $loose->id, $other->id, 'nope'] as $id) {
            try {
                $this->files->read($id, 5);
                self::fail('expected not found for ' . $id);
            } catch (Ap2NotFound) {
                self::addToAssertionCount(1);
            }
        }
    }
}
