<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Documents\Enums\LetterheadPart;
use App\Modules\Documents\Models\DocumentLetterhead;
use App\Modules\Documents\Services\Letterhead;
use App\Shared\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\Pdfs;
use Tests\TestCase;

/**
 * The company's letterhead (documents design §8.2): a header and a footer
 * image framing every template page that shows it. Managed with
 * `documents.templates.manage`. Not encrypted: a letterhead signs nothing.
 */
class LetterheadTest extends TestCase
{
    use RefreshDatabase;

    private const SIZE_OR_TYPE = 'الصورة يجب أن تكون PNG أو JPG لا يتجاوز حجمها 2 ميغابايت.';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function upload(string $part, UploadedFile $file): TestResponse
    {
        return $this->post("/api/documents/letterhead/{$part}", ['image' => $file], ['Accept' => 'application/json']);
    }

    public function test_the_header_and_footer_are_uploaded_and_read(): void
    {
        $user = $this->actingAsUserWithPermissions(['documents.templates.manage']);
        $file = UploadedFile::fake()->image('header.png', 1200, 200);
        $bytes = (string) file_get_contents((string) $file->getRealPath());

        $this->upload('header', $file)
            ->assertCreated()
            ->assertJsonPath('message', 'تم حفظ الترويسة.');

        $this->getJson('/api/documents/letterhead')
            ->assertOk()
            ->assertJsonPath('data.footer', null)
            ->assertJsonStructure(['data' => ['header' => ['uploaded_at']]]);

        $response = $this->get('/api/documents/letterhead/header/image')->assertOk();
        $this->assertSame($bytes, $response->streamedContent());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $path = app(Letterhead::class)->pathOf(LetterheadPart::Header);
        $this->assertNotNull($path);
        $this->assertStringStartsWith('documents/letterhead/', $path);
        $this->assertSame($bytes, Storage::disk('local')->get($path), 'a letterhead is not encrypted');
        $this->assertNull(app(Letterhead::class)->pathOf(LetterheadPart::Footer));

        $this->assertSame($user->id, DocumentLetterhead::sole()->updated_by);
        $this->assertTrue(AuditLog::query()->where('auditable_type', 'document_letterhead')->exists(), 'the change is in the audit trail');
    }

    public function test_a_new_image_replaces_the_old_file(): void
    {
        $this->actingAsUserWithPermissions(['documents.templates.manage']);
        $this->upload('header', UploadedFile::fake()->image('a.png', 600, 100))->assertCreated();
        $old = app(Letterhead::class)->pathOf(LetterheadPart::Header);

        $this->upload('header', UploadedFile::fake()->image('b.jpg', 600, 100))->assertCreated();

        Storage::disk('local')->assertMissing((string) $old);
        $this->assertSame(1, DocumentLetterhead::count());
        $this->assertSame(1, count(Storage::disk('local')->allFiles('documents/letterhead')));
        $this->assertSame('image/jpeg', $this->get('/api/documents/letterhead/header/image')->headers->get('Content-Type'));
    }

    public function test_removing_a_part(): void
    {
        $this->actingAsUserWithPermissions(['documents.templates.manage']);
        $this->upload('footer', UploadedFile::fake()->image('f.png', 600, 100))->assertCreated();
        $path = app(Letterhead::class)->pathOf(LetterheadPart::Footer);

        $this->deleteJson('/api/documents/letterhead/footer')
            ->assertOk()
            ->assertJsonPath('message', 'تم حذف الترويسة.');

        Storage::disk('local')->assertMissing((string) $path);
        $this->getJson('/api/documents/letterhead/footer/image')
            ->assertNotFound()
            ->assertJsonPath('message', 'لم تُرفع هذه الصورة بعد.');
        $this->deleteJson('/api/documents/letterhead/footer')->assertNotFound();
        $this->getJson('/api/documents/letterhead')->assertOk()->assertJsonPath('data.footer', null);
    }

    public function test_only_png_or_jpg_within_2_mb(): void
    {
        $this->actingAsUserWithPermissions(['documents.templates.manage']);

        $files = [
            UploadedFile::fake()->createWithContent('header.pdf', Pdfs::plain()),
            UploadedFile::fake()->image('big.png', 300, 300)->size(3072),
            UploadedFile::fake()->createWithContent('header.png', Pdfs::plain()),
        ];

        foreach ($files as $file) {
            $this->upload('header', $file)->assertUnprocessable()->assertJsonValidationErrors(['image' => self::SIZE_OR_TYPE]);
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_truncated_image_is_refused(): void
    {
        $this->actingAsUserWithPermissions(['documents.templates.manage']);
        $image = UploadedFile::fake()->image('h.png', 1200, 200);
        $cut = Pdfs::truncated((string) file_get_contents((string) $image->getRealPath()));

        $this->upload('header', UploadedFile::fake()->createWithContent('header.png', $cut))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image' => self::SIZE_OR_TYPE]);

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_unknown_part_is_404(): void
    {
        $this->actingAsUserWithPermissions(['documents.templates.manage']);

        $this->upload('body', UploadedFile::fake()->image('b.png', 300, 100))->assertNotFound();
        $this->getJson('/api/documents/letterhead/body/image')->assertNotFound();
        $this->deleteJson('/api/documents/letterhead/body')->assertNotFound();
    }

    public function test_without_the_permission_it_is_403(): void
    {
        $this->actingAsUserWithPermissions(['documents.view']);

        $this->getJson('/api/documents/letterhead')->assertForbidden();
        $this->upload('header', UploadedFile::fake()->image('h.png', 300, 100))->assertForbidden();
        $this->getJson('/api/documents/letterhead/header/image')->assertForbidden();
        $this->deleteJson('/api/documents/letterhead/header')->assertForbidden();
    }
}
