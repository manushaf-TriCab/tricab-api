<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use TriCab\Api\Finance\Attachments\InMemoryFinanceAttachmentStore;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentStore;
use TriCab\Core\Finance\Shared\Attachments\FinanceUnattachedAttachmentsJob;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobRegistry;
use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Finance\Task\Service\TaskFileService;
use TriCab\Core\Identity\Contract\UserRepository;

/**
 * Files on tasks through the real app on the in-memory twins (23 Oct 2026): upload, list, download, remove, who may,
 * the count on every task, a file picked before the first save, and what a permanent delete takes with it.
 */
final class FinanceTaskFilesRoutesTest extends TestCase
{
    private const BASE = '/api/v1/finance/task';

    private const OWN = [
        Module::PERMISSION_TASK_TASKS_READ,
        Module::PERMISSION_TASK_TASKS_CREATE,
        Module::PERMISSION_TASK_TASKS_UPDATE_OWN,
        Module::PERMISSION_TASK_TASKS_DELETE_OWN,
    ];

    /** @var App<ContainerInterface> */
    private App $app;

    private InMemoryFinanceAttachmentStore $files;

    protected function setUp(): void
    {
        $this->app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_TASK_ENABLED' => 'true']);
        $directory = $this->app->getContainer()->get(FinanceTaskMemberDirectory::class);
        self::assertInstanceOf(InMemoryFinanceTaskMemberDirectory::class, $directory);
        foreach ([1 => 'Dev User', 9 => 'Una User'] as $id => $name) {
            $directory->add($id, $name, 'm' . $id . '@example.test');
        }
        $store = $this->app->getContainer()->get(FinanceAttachmentStore::class);
        self::assertInstanceOf(InMemoryFinanceAttachmentStore::class, $store);
        $this->files = $store;
        $this->as(self::OWN);
    }

    /** @param list<string> $permissions */
    private function as(array $permissions): void
    {
        $users = $this->app->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, Module::PERMISSION_TASK_ACCESS, ...$permissions], ['EMPLOYEE']);
    }

    /**
     * @param array<array-key, mixed>|null $body
     * @param array<string, string> $query
     */
    private function send(string $method, string $path, ?array $body = null, array $query = []): ResponseInterface
    {
        $request = TestAppFactory::request($method, self::BASE . $path)->withQueryParams($query);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $this->app->handle($request);
    }

    /**
     * @param array<array-key, mixed>|null $body
     * @param array<string, string> $query
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function call(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $response = $this->send($method, $path, $body, $query);

        return [$response->getStatusCode(), TestAppFactory::json($response)];
    }

    private function upload(string $path, string $name, string $bytes, string $type = 'application/pdf', string $field = 'file'): ResponseInterface
    {
        $file = new UploadedFile((new StreamFactory())->createStream($bytes), $name, $type, strlen($bytes), UPLOAD_ERR_OK);

        return $this->app->handle(TestAppFactory::request('POST', self::BASE . $path)->withUploadedFiles([$field => $file]));
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private static function row(array $json): array
    {
        self::assertTrue($json['success'], json_encode($json, JSON_THROW_ON_ERROR));
        $data = $json['data'];
        self::assertIsArray($data);
        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $json
     * @return list<array<string, mixed>>
     */
    private static function rows(array $json): array
    {
        self::assertTrue($json['success'], json_encode($json, JSON_THROW_ON_ERROR));
        $data = $json['data'];
        self::assertIsArray($data);
        $out = [];
        foreach ($data as $row) {
            self::assertIsArray($row);
            $one = [];
            foreach ($row as $key => $value) {
                $one[(string) $key] = $value;
            }
            $out[] = $one;
        }

        return $out;
    }

    private static function errorCode(ResponseInterface $response): mixed
    {
        $error = TestAppFactory::json($response)['error'] ?? null;

        return is_array($error) ? ($error['code'] ?? null) : null;
    }

    private function task(string $name = 'Bank rec', string $preparer = '1'): string
    {
        $row = self::row($this->call('POST', '/tasks', ['name' => $name, 'preparer_id' => $preparer])[1]);
        self::assertIsString($row['id']);

        return $row['id'];
    }

    private function fileCount(string $taskId): int
    {
        foreach (self::rows($this->call('GET', '/tasks')[1]) as $row) {
            if ($row['id'] === $taskId) {
                self::assertIsInt($row['file_count']);

                return $row['file_count'];
            }
        }
        self::fail('The task is not in the list.');
    }

    public function test_a_file_is_uploaded_listed_downloaded_and_removed(): void
    {
        $id = $this->task();

        $response = $this->upload('/tasks/' . $id . '/files', 'Bank statement.pdf', '%PDF-1.4 invented');
        self::assertSame(201, $response->getStatusCode());
        $file = self::row(TestAppFactory::json($response));
        self::assertSame('Bank statement.pdf', $file['name']);
        self::assertSame(17, $file['size']);
        self::assertSame('application/pdf', $file['content_type']);
        self::assertSame('1', $file['uploaded_by_id']);
        self::assertSame('Dev User', $file['uploaded_by_name']);
        self::assertIsString($file['created_at']);
        self::assertIsString($file['id']);

        $listed = self::rows($this->call('GET', '/tasks/' . $id . '/files')[1]);
        self::assertSame([$file['id']], array_column($listed, 'id'));

        $download = $this->send('GET', '/tasks/' . $id . '/files/' . $file['id'] . '/file');
        self::assertSame(200, $download->getStatusCode());
        self::assertSame('%PDF-1.4 invented', (string) $download->getBody());
        self::assertSame('application/pdf', $download->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('attachment; filename="Bank statement.pdf"', $download->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $download->getHeaderLine('X-Content-Type-Options'));
        self::assertSame("default-src 'none'; sandbox", $download->getHeaderLine('Content-Security-Policy'));
        self::assertSame('private, no-store', $download->getHeaderLine('Cache-Control'));
        $inline = $this->send('GET', '/tasks/' . $id . '/files/' . $file['id'] . '/file', query: ['inline' => '1']);
        self::assertStringStartsWith('inline;', $inline->getHeaderLine('Content-Disposition'));

        [$status] = $this->call('DELETE', '/tasks/' . $id . '/files/' . $file['id']);
        self::assertSame(200, $status);
        self::assertSame([], self::rows($this->call('GET', '/tasks/' . $id . '/files')[1]));
        self::assertSame([], $this->files->blobPaths(), 'the bytes go with the row');
        self::assertSame(404, $this->send('GET', '/tasks/' . $id . '/files/' . $file['id'] . '/file')->getStatusCode());
    }

    public function test_the_bytes_land_under_task_year_month_and_the_files_row_is_for_the_task_app(): void
    {
        $id = $this->task();
        $file = self::row(TestAppFactory::json($this->upload('/tasks/' . $id . '/files', 'a.xlsx', 'bytes', 'application/vnd.ms-excel')));

        self::assertIsString($file['id']);
        $row = $this->files->find('TASK', $file['id']);
        self::assertNotNull($row);
        self::assertSame('task', $row->recordType);
        self::assertSame($id, $row->recordId);
        self::assertMatchesRegularExpression('~^task/\d{4}/\d{2}/' . $file['id'] . '$~', $row->blobPath);
    }

    public function test_every_task_carries_its_file_count_on_the_list_and_the_single_read(): void
    {
        $a = $this->task('Bank rec');
        $b = $this->task('Payroll');
        $this->upload('/tasks/' . $a . '/files', 'one.pdf', 'one');
        $this->upload('/tasks/' . $a . '/files', 'two.pdf', 'two');

        self::assertSame(2, $this->fileCount($a));
        self::assertSame(0, $this->fileCount($b));
        self::assertSame(2, self::row($this->call('GET', '/tasks/' . $a)[1])['file_count']);
        self::assertSame(0, self::row($this->call('GET', '/tasks/' . $b)[1])['file_count']);
        // A save answers the count too, so a screen that swaps its row for the answer keeps the paperclip.
        self::assertSame(2, self::row($this->call('PATCH', '/tasks/' . $a, ['notes' => 'x'])[1])['file_count']);
    }

    public function test_the_same_name_twice_is_two_files(): void
    {
        $id = $this->task();
        $this->upload('/tasks/' . $id . '/files', 'scan.pdf', 'first');
        $this->upload('/tasks/' . $id . '/files', 'scan.pdf', 'second');

        self::assertSame(2, $this->fileCount($id));
        self::assertCount(2, array_unique($this->files->blobPaths()));
    }

    public function test_bad_uploads_are_refused_and_leave_nothing(): void
    {
        $id = $this->task();

        self::assertSame(422, $this->send('POST', '/tasks/' . $id . '/files')->getStatusCode(), 'no file');
        self::assertSame(422, $this->upload('/tasks/' . $id . '/files', 'a.pdf', 'x', field: 'wrong')->getStatusCode(), 'the part must be named file');
        self::assertSame(422, $this->upload('/tasks/' . $id . '/files', 'a.pdf', '')->getStatusCode(), 'empty');
        $tooBig = $this->upload('/tasks/' . $id . '/files', 'big.bin', str_repeat('x', TaskFileService::MAX_BYTES + 1));
        self::assertSame(422, $tooBig->getStatusCode());
        self::assertSame('FINANCE_TASK_INPUT_INVALID', self::errorCode($tooBig));
        $error = TestAppFactory::json($tooBig)['error'];
        self::assertIsArray($error);
        self::assertSame('The file is larger than 20 MB.', $error['message']);
        self::assertSame([], $this->files->blobPaths());
        self::assertSame(0, $this->fileCount($id));
        $exact = $this->upload('/tasks/' . $id . '/files', 'exact.bin', str_repeat('x', TaskFileService::MAX_BYTES));
        self::assertSame(201, $exact->getStatusCode(), 'exactly 20 MB is allowed');
        $exactId = self::row(TestAppFactory::json($exact))['id'];
        self::assertIsString($exactId);
        $this->send('DELETE', '/tasks/' . $id . '/files/' . $exactId);
        $limit = $this->upload('/tasks/' . $id . '/files', 'ok.bin', str_repeat('x', 1000), 'not a type');
        self::assertSame(201, $limit->getStatusCode(), 'a type we cannot read is stored as a plain download');
        self::assertSame('application/octet-stream', self::row(TestAppFactory::json($limit))['content_type']);
    }

    public function test_anyone_who_sees_a_task_reads_its_files_but_only_an_editor_adds_or_removes(): void
    {
        $id = $this->task('Held by Una', '9');
        // The creator neither prepares nor reviews it, and has no READ_ALL: the task, and so its files, do not exist for them.
        self::assertSame(404, $this->send('GET', '/tasks/' . $id . '/files')->getStatusCode());
        self::assertSame(404, $this->upload('/tasks/' . $id . '/files', 'a.pdf', 'x')->getStatusCode());

        $this->as([Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_TASKS_UPDATE_OWN, Module::PERMISSION_TASK_TASKS_UPDATE]);
        $file = self::row(TestAppFactory::json($this->upload('/tasks/' . $id . '/files', 'a.pdf', 'bytes')));
        self::assertIsString($file['id']);

        // Read-all with only UPDATE_OWN on a task that is not theirs: reads and downloads, cannot add or remove (the edit rule).
        $this->as([Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_TASKS_UPDATE_OWN]);
        self::assertSame(200, $this->send('GET', '/tasks/' . $id . '/files')->getStatusCode());
        self::assertSame('bytes', (string) $this->send('GET', '/tasks/' . $id . '/files/' . $file['id'] . '/file')->getBody());
        self::assertSame(403, $this->upload('/tasks/' . $id . '/files', 'b.pdf', 'x')->getStatusCode());
        self::assertSame(403, $this->send('DELETE', '/tasks/' . $id . '/files/' . $file['id'])->getStatusCode());

        // Read only: the routes themselves refuse.
        $this->as([Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_READ_ALL]);
        self::assertSame(200, $this->send('GET', '/tasks/' . $id . '/files')->getStatusCode());
        self::assertSame(403, $this->upload('/tasks/' . $id . '/files', 'b.pdf', 'x')->getStatusCode());
        self::assertSame(403, $this->send('DELETE', '/tasks/' . $id . '/files/' . $file['id'])->getStatusCode());
        $this->as([]);
        self::assertSame(403, $this->send('GET', '/tasks/' . $id . '/files')->getStatusCode());
        self::assertSame(403, $this->send('GET', '/tasks/' . $id . '/files/' . $file['id'] . '/file')->getStatusCode());
        self::assertSame(403, $this->send('POST', '/task-uploads')->getStatusCode());
        self::assertSame(1, count($this->files->blobPaths()));
    }

    public function test_a_file_is_found_only_on_its_own_task(): void
    {
        $a = $this->task('A');
        $b = $this->task('B');
        $file = self::row(TestAppFactory::json($this->upload('/tasks/' . $a . '/files', 'a.pdf', 'bytes')));
        self::assertIsString($file['id']);

        self::assertSame(404, $this->send('GET', '/tasks/' . $b . '/files/' . $file['id'] . '/file')->getStatusCode());
        self::assertSame(404, $this->send('DELETE', '/tasks/' . $b . '/files/' . $file['id'])->getStatusCode());
        self::assertSame(404, $this->send('GET', '/tasks/' . $a . '/files/nope/file')->getStatusCode());
        self::assertSame(1, $this->fileCount($a));
    }

    public function test_a_file_picked_before_the_first_save_goes_on_the_task_when_it_is_created(): void
    {
        $loose = self::row(TestAppFactory::json($this->upload('/task-uploads', 'brief.pdf', 'pdf bytes')));
        self::assertIsString($loose['id']);
        self::assertSame(1, count($this->files->blobPaths()));

        [$status, $json] = $this->call('POST', '/tasks', ['name' => 'New', 'preparer_id' => '1', 'upload_ids' => [$loose['id'], $loose['id']]]);
        self::assertSame(201, $status);
        $task = self::row($json);
        self::assertSame(1, $task['file_count']);
        self::assertIsString($task['id']);
        self::assertSame(['brief.pdf'], array_column(self::rows($this->call('GET', '/tasks/' . $task['id'] . '/files')[1]), 'name'));
        self::assertSame('pdf bytes', (string) $this->send('GET', '/tasks/' . $task['id'] . '/files/' . $loose['id'] . '/file')->getBody());
    }

    public function test_an_upload_that_is_not_the_callers_or_already_used_creates_no_task(): void
    {
        $loose = self::row(TestAppFactory::json($this->upload('/task-uploads', 'brief.pdf', 'x')));
        self::assertIsString($loose['id']);
        $first = $this->call('POST', '/tasks', ['name' => 'First', 'preparer_id' => '1', 'upload_ids' => [$loose['id']]]);
        self::assertSame(201, $first[0]);

        // Already on a task, unknown, or not a list: refused before anything is created.
        foreach ([[$loose['id']], ['0123456789abcdef0123456789abcdef'], 'nope', [7]] as $bad) {
            [$status, $json] = $this->call('POST', '/tasks', ['name' => 'Second', 'preparer_id' => '1', 'upload_ids' => $bad]);
            self::assertSame(422, $status, json_encode($bad, JSON_THROW_ON_ERROR));
            self::assertSame('FINANCE_TASK_INPUT_INVALID', (is_array($json['error']) ? $json['error'] : [])['code'] ?? null);
        }
        self::assertSame(['First'], array_column(self::rows($this->call('GET', '/tasks')[1]), 'name'));

        // Someone else's upload cannot be claimed: put one in the store as another person.
        $other = $this->app->getContainer()->get(\TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService::class);
        self::assertInstanceOf(\TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService::class, $other);
        $theirs = $other->upload('TASK', 'theirs.pdf', 'application/pdf', 'x', 9);
        self::assertSame(422, $this->call('POST', '/tasks', ['name' => 'Third', 'preparer_id' => '1', 'upload_ids' => [$theirs->id]])[0]);
        self::assertSame(1, $this->fileCountOfAll());
    }

    private function fileCountOfAll(): int
    {
        return count(array_filter(self::rows($this->call('GET', '/tasks')[1]), static fn (array $row): bool => $row['file_count'] === 1));
    }

    public function test_binning_and_restoring_a_task_keep_its_files(): void
    {
        $id = $this->task();
        $file = self::row(TestAppFactory::json($this->upload('/tasks/' . $id . '/files', 'a.pdf', 'bytes')));
        self::assertIsString($file['id']);

        self::assertSame(200, $this->call('DELETE', '/tasks/' . $id)[0]);
        self::assertCount(1, $this->files->blobPaths());
        $binned = self::rows($this->call('GET', '/tasks', query: ['deleted' => '1'])[1]);
        self::assertSame(1, $binned[0]['file_count']);
        self::assertSame('bytes', (string) $this->send('GET', '/tasks/' . $id . '/files/' . $file['id'] . '/file')->getBody());

        self::assertSame(200, $this->call('POST', '/tasks/' . $id . '/restore')[0]);
        self::assertSame(1, $this->fileCount($id));
    }

    public function test_deleting_a_task_for_good_removes_its_files_and_only_its_files(): void
    {
        $a = $this->task('A');
        $b = $this->task('B');
        $this->upload('/tasks/' . $a . '/files', 'a1.pdf', 'a1');
        $this->upload('/tasks/' . $a . '/files', 'a2.pdf', 'a2');
        $this->upload('/tasks/' . $b . '/files', 'b1.pdf', 'b1');
        self::assertCount(3, $this->files->blobPaths());

        $this->call('DELETE', '/tasks/' . $a);
        [$status, $json] = $this->call('DELETE', '/tasks/' . $a, query: ['permanent' => '1']);
        self::assertSame(200, $status);
        self::assertSame(['deleted' => true, 'permanent' => true], self::row($json));

        self::assertCount(1, $this->files->blobPaths());
        self::assertSame([], $this->files->forRecord('TASK', 'task', $a));
        self::assertCount(1, $this->files->forRecord('TASK', 'task', $b));
        self::assertSame(1, $this->fileCount($b));
    }

    public function test_a_project_deleted_for_good_removes_the_files_of_its_binned_tasks(): void
    {
        $this->as([...self::OWN, Module::PERMISSION_TASK_SETUP_MANAGE]);
        $project = self::row($this->call('POST', '/projects', ['name' => 'Close', 'status' => 'active'])[1]);
        self::assertIsString($project['id']);
        $in = self::row($this->call('POST', '/tasks', ['name' => 'In project', 'preparer_id' => '1', 'project_id' => $project['id']])[1]);
        $out = $this->task('Outside');
        self::assertIsString($in['id']);
        $this->upload('/tasks/' . $in['id'] . '/files', 'in.pdf', 'in');
        $this->upload('/tasks/' . $out . '/files', 'out.pdf', 'out');
        self::assertCount(2, $this->files->blobPaths());

        self::assertSame(200, $this->call('DELETE', '/projects/' . $project['id'])[0]);
        self::assertCount(2, $this->files->blobPaths(), 'the Bin keeps files');
        self::assertSame(200, $this->call('DELETE', '/projects/' . $project['id'], query: ['permanent' => '1'])[0]);

        self::assertSame([], $this->files->forRecord('TASK', 'task', $in['id']));
        self::assertCount(1, $this->files->blobPaths());
        self::assertSame(1, $this->fileCount($out));
    }

    public function test_the_nightly_sweep_of_unattached_uploads_runs_when_tri_task_is_on(): void
    {
        $names = static function (App $app): array {
            $registry = $app->getContainer()?->get(FinanceJobRegistry::class);
            self::assertInstanceOf(FinanceJobRegistry::class, $registry);

            return array_map(static fn ($job): string => $job->name(), $registry->all());
        };

        self::assertContains(FinanceUnattachedAttachmentsJob::NAME, $names($this->app));
        $off = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true']);
        self::assertNotContains(FinanceUnattachedAttachmentsJob::NAME, $names($off));
    }
}
