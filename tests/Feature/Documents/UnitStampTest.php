<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Auth\Models\Permission;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Models\UnitStamp;
use App\Modules\Documents\Services\UnitStamps;
use App\Shared\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\Pdfs;
use Tests\TestCase;

/**
 * Every unit has its own official stamp (the owner's rules, round §H),
 * uploaded by the system administrator alone (D23.1), kept encrypted with
 * every version (I.9, I.10). The dashboard lists the units still without one
 * (D21.2), so every stamp can be in before the release.
 */
class UnitStampTest extends TestCase
{
    use RefreshDatabase;

    private const SIZE_OR_TYPE = 'الختم يجب أن يكون صورة PNG أو JPG لا يتجاوز حجمها 2 ميغابايت.';

    private Department $root;

    private Department $division;

    private Department $section;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->root = Department::create(['name' => 'الإدارة العامة', 'level' => 1]);
        $this->division = Department::create(['name' => 'إدارة الموارد البشرية', 'level' => 2, 'parent_id' => $this->root->id, 'sector' => 1]);
        $this->section = Department::create(['name' => 'قسم التوظيف', 'level' => 3, 'parent_id' => $this->division->id]);
    }

    private function upload(Department $unit, UploadedFile $file): TestResponse
    {
        return $this->post("/api/documents/stamps/{$unit->id}", ['stamp' => $file], ['Accept' => 'application/json']);
    }

    private function bytesOf(UploadedFile $file): string
    {
        return (string) file_get_contents((string) $file->getRealPath());
    }

    public function test_the_system_administrator_uploads_a_units_stamp(): void
    {
        $admin = $this->actingAsAdmin();

        $this->upload($this->section, UploadedFile::fake()->image('ختم.png', 300, 300))
            ->assertCreated()
            ->assertJsonPath('message', 'تم حفظ ختم الوحدة.')
            ->assertJsonStructure(['data' => ['uploaded_at']]);

        $stamp = UnitStamp::sole();
        $this->assertSame($this->section->id, $stamp->department_id);
        $this->assertNull($stamp->replaced_at);
        $this->assertSame('image/png', $stamp->mime_type);
        $this->assertStringNotContainsString("\x89PNG", Storage::disk('local')->get($stamp->path), 'stored encrypted');

        // Audit failures are swallowed by design: an unregistered model
        // would leave no row at all.
        $this->assertTrue(AuditLog::query()
            ->where('auditable_type', 'unit_stamp')
            ->where('auditable_id', $stamp->id)
            ->where('event', 'created')
            ->where('user_id', $admin->id)
            ->exists(), 'the upload is in the audit trail');
    }

    public function test_replacing_keeps_the_old_version_readable(): void
    {
        $admin = $this->actingAsAdmin();
        $first = UploadedFile::fake()->image('first.png', 300, 300);
        $firstBytes = $this->bytesOf($first);
        $this->upload($this->section, $first)->assertCreated();
        $old = UnitStamp::sole();

        $this->upload($this->section, UploadedFile::fake()->image('second.jpg', 300, 300))->assertCreated();

        $old->refresh();
        $this->assertNotNull($old->replaced_at);
        $this->assertSame($firstBytes, app(UnitStamps::class)->bytes($old), 'a printed card points at the old version (I.10)');
        $this->assertSame(1, UnitStamp::where('department_id', $this->section->id)->whereNull('replaced_at')->count());
        $this->assertSame('image/jpeg', app(UnitStamps::class)->current($this->section)?->mime_type);
        $this->assertSame($admin->id, app(UnitStamps::class)->current($this->section)?->uploaded_by);
    }

    public function test_the_image_is_streamed_to_the_administrator_only(): void
    {
        $this->actingAsAdmin();

        $this->getJson("/api/documents/stamps/{$this->section->id}/image")
            ->assertNotFound()
            ->assertJsonPath('message', 'لم يُرفع ختم لهذه الوحدة بعد.');

        $file = UploadedFile::fake()->image('ختم.png', 300, 300);
        $bytes = $this->bytesOf($file);
        $this->upload($this->section, $file)->assertCreated();

        $response = $this->get("/api/documents/stamps/{$this->section->id}/image")->assertOk();

        $this->assertSame($bytes, $response->streamedContent());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_list_shows_every_unit_and_which_lack_a_stamp(): void
    {
        $this->actingAsAdmin();
        $this->upload($this->division, UploadedFile::fake()->image('ختم.png', 300, 300))->assertCreated();
        $gone = Department::create(['name' => 'مكتب محذوف', 'level' => 4, 'parent_id' => $this->section->id]);
        $gone->delete();

        $all = collect($this->getJson('/api/documents/stamps')->assertOk()->json('data'))->keyBy('id');

        $this->assertEqualsCanonicalizing([$this->root->id, $this->division->id, $this->section->id], $all->keys()->all());
        $this->assertTrue($all[$this->division->id]['has_stamp']);
        $this->assertNotNull($all[$this->division->id]['stamp_uploaded_at']);
        $this->assertFalse($all[$this->section->id]['has_stamp']);
        $this->assertNull($all[$this->section->id]['stamp_uploaded_at']);
        $this->assertSame('قسم التوظيف', $all[$this->section->id]['name']);

        $missing = collect($this->getJson('/api/documents/stamps?missing=1')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$this->root->id, $this->section->id], $missing);
        $this->assertEqualsCanonicalizing([$this->root->id, $this->section->id], app(UnitStamps::class)->unitsWithout()->pluck('id')->all());
    }

    public function test_only_png_or_jpg_within_2_mb(): void
    {
        $this->actingAsAdmin();

        $files = [
            UploadedFile::fake()->createWithContent('ختم.pdf', Pdfs::plain()),
            UploadedFile::fake()->image('big.png', 300, 300)->size(3072),
            UploadedFile::fake()->createWithContent('ختم.png', Pdfs::plain()),
        ];

        foreach ($files as $file) {
            $this->upload($this->section, $file)->assertUnprocessable()->assertJsonValidationErrors(['stamp' => self::SIZE_OR_TYPE]);
        }

        $this->assertSame(0, UnitStamp::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /**
     * Final review, 2026-11-22: a cut-off PNG or JPG keeps a valid header;
     * printed on a card, it would fail or show nothing.
     */
    public function test_a_truncated_image_is_refused(): void
    {
        $this->actingAsAdmin();

        foreach (['ختم.png' => UploadedFile::fake()->image('a.png', 300, 300), 'ختم.jpg' => UploadedFile::fake()->image('a.jpg', 300, 300)] as $name => $image) {
            $cut = Pdfs::truncated($this->bytesOf($image));

            $this->upload($this->section, UploadedFile::fake()->createWithContent($name, $cut))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['stamp' => self::SIZE_OR_TYPE]);
        }

        $this->assertSame(0, UnitStamp::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_deleted_unit_takes_no_stamp(): void
    {
        $this->actingAsAdmin();
        $office = Department::create(['name' => 'مكتب', 'level' => 4, 'parent_id' => $this->section->id]);
        $office->delete();

        $this->upload($office, UploadedFile::fake()->image('ختم.png', 300, 300))->assertNotFound();
        $this->getJson("/api/documents/stamps/{$office->id}/image")->assertNotFound();
        $this->assertSame(0, UnitStamp::count());
    }

    public function test_nobody_but_the_system_administrator(): void
    {
        $this->seedRbac();
        $this->actingAsUserWithPermissions(Permission::pluck('name')->all());

        $this->getJson('/api/documents/stamps')->assertForbidden();
        $this->upload($this->section, UploadedFile::fake()->image('ختم.png', 300, 300))->assertForbidden();
        $this->getJson("/api/documents/stamps/{$this->section->id}/image")->assertForbidden();
        $this->assertSame(0, UnitStamp::count());
    }
}
