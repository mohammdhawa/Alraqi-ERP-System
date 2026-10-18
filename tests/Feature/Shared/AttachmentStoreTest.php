<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Auth\Models\User;
use App\Modules\Leave\Models\LeaveRequest;
use App\Shared\Models\Attachment;
use App\Shared\Services\AttachmentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Files on a record — a leave's medical report now, a document in November
 * (LEAVE_BUILD.md §5). On the PRIVATE disk under a name the client did not
 * choose, never a public URL; who may read one is the subject's policy, not
 * the store's.
 */
class AttachmentStoreTest extends TestCase
{
    use RefreshDatabase;

    private LeaveRequest $leave;

    private User $uploader;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->leave = LeaveRequest::factory()->forType('sick')->create();
        $this->uploader = User::factory()->create();
    }

    private function store(string $name = 'تقرير طبي.pdf', int $kb = 120, string $mime = 'application/pdf'): Attachment
    {
        return app(AttachmentStore::class)->store(UploadedFile::fake()->create($name, $kb, $mime), $this->leave, $this->uploader);
    }

    public function test_a_file_is_stored_privately_under_a_name_of_its_own(): void
    {
        $attachment = $this->store();

        $this->assertMatchesRegularExpression(
            '#^attachments/leave_request/'.$this->leave->id.'/[0-9a-f-]{36}\.pdf$#',
            $attachment->path,
            'never the client\'s file name on disk',
        );
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame('local', $attachment->disk);
    }

    public function test_the_row_records_what_the_client_sent_and_who(): void
    {
        $attachment = $this->store('تقرير طبي.pdf', 120);

        $this->assertSame('تقرير طبي.pdf', $attachment->original_name);
        $this->assertSame('application/pdf', $attachment->mime_type);
        $this->assertSame(120 * 1024, $attachment->size);
        $this->assertSame($this->uploader->id, (int) $attachment->uploaded_by);
        $this->assertTrue($attachment->attachable->is($this->leave));
        $this->assertSame('leave_request', $attachment->attachable_type);
    }

    public function test_it_is_streamed_never_cached_under_its_original_name(): void
    {
        $attachment = $this->store('report.pdf');

        $response = TestResponse::fromBaseResponse(app(AttachmentStore::class)->stream($attachment));

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('report.pdf', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_files_can_be_deleted_by_path(): void
    {
        $first = $this->store('a.pdf');
        $second = $this->store('b.png', 50, 'image/png');

        app(AttachmentStore::class)->deleteFiles([$first->path, $second->path]);

        Storage::disk('local')->assertMissing($first->path);
        Storage::disk('local')->assertMissing($second->path);
    }

    public function test_an_overlong_client_name_is_cut_to_fit(): void
    {
        $attachment = $this->store(str_repeat('ط', 300).'.pdf');

        $this->assertSame(255, mb_strlen($attachment->original_name));
    }
}
