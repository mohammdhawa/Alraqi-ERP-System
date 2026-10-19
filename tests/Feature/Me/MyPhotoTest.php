<?php

declare(strict_types=1);

namespace Tests\Feature\Me;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeDocumentType;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST and GET /api/me/photo — the employee's own personal photo (work order
 * §4.8; no photo is imported, decision of 2026-10-14). The owner's answers of
 * 2026-10-19: JPG or PNG up to 5 MB, on the private disk, a new upload
 * replaces the old one, and HR approves nothing.
 */
class MyPhotoTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->employee = Employee::factory()->create();
        $this->user = User::factory()->create(['employee_id' => $this->employee->id, 'must_change_password' => false]);
        Sanctum::actingAs($this->user);
    }

    private function jpg(string $name, int $kilobytes = 100): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'image/jpeg');
    }

    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 100, 'image/png');
    }

    private function upload(?UploadedFile $file): TestResponse
    {
        return $this->post('/api/me/photo', $file === null ? [] : ['photo' => $file], ['Accept' => 'application/json']);
    }

    public function test_an_upload_is_filed_as_the_personal_photo_on_the_private_disk(): void
    {
        $this->upload($this->jpg('أنا.jpg'))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['uploaded_at']]);

        $photo = EmployeeDocument::sole();
        $this->assertSame($this->employee->id, $photo->employee_id);
        $this->assertSame(EmployeeDocumentType::PersonalPhoto, $photo->type);
        $this->assertSame($this->user->id, $photo->uploaded_by);
        $this->assertSame('أنا.jpg', $photo->original_name);
        $this->assertSame('image/jpeg', $photo->mime_type);
        $this->assertGreaterThan(0, $photo->size_bytes);
        Storage::disk('local')->assertExists($photo->file_path);
        $this->assertStringNotContainsString('أنا', $photo->file_path, 'the server names the file');

        $this->getJson('/api/me/profile')->assertOk()
            ->assertJsonPath('data.photo.uploaded_at', $photo->created_at->toIso8601String());
    }

    public function test_the_photo_is_streamed_never_a_url(): void
    {
        $this->upload($this->png('me.png'))->assertCreated();
        $path = EmployeeDocument::sole()->file_path;

        $response = $this->get('/api/me/photo')->assertOk();

        $this->assertSame(Storage::disk('local')->get($path), $response->streamedContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_new_upload_replaces_the_old_one(): void
    {
        $this->upload($this->jpg('old.jpg'))->assertCreated();
        $old = EmployeeDocument::sole();

        $this->upload($this->png('new.png'))->assertCreated();

        $this->assertSoftDeleted($old);
        Storage::disk('local')->assertMissing($old->file_path);

        $new = EmployeeDocument::sole();
        $this->assertSame('new.png', $new->original_name);
        $this->assertSame(Storage::disk('local')->get($new->file_path), $this->get('/api/me/photo')->streamedContent());
    }

    public function test_a_failed_write_leaves_no_file_and_the_old_photo_in_place(): void
    {
        $this->upload($this->jpg('old.jpg'))->assertCreated();
        $old = EmployeeDocument::sole();

        EmployeeDocument::creating(static function (): never {
            throw new \RuntimeException('the row could not be written');
        });

        $this->withoutExceptionHandling();
        try {
            $this->upload($this->png('new.png'));
            $this->fail('the write should have failed');
        } catch (\RuntimeException $e) {
            $this->assertSame('the row could not be written', $e->getMessage());
        }

        $this->assertNotSoftDeleted($old);
        $this->assertSame([$old->file_path], Storage::disk('local')->allFiles("employee-documents/{$this->employee->id}"));
    }

    public function test_another_kind_of_document_is_left_alone_by_a_replacement(): void
    {
        $cv = EmployeeDocument::create([
            'employee_id' => $this->employee->id,
            'type'        => EmployeeDocumentType::Cv,
            'file_path'   => 'employee-documents/cv.pdf',
        ]);

        $this->upload($this->jpg('me.jpg'))->assertCreated();

        $this->assertNotSoftDeleted($cv);
    }

    public function test_no_photo_is_a_404(): void
    {
        $this->getJson('/api/me/photo')->assertNotFound()->assertJsonPath('success', false);
        $this->getJson('/api/me/profile')->assertOk()->assertJsonPath('data.photo', null);
    }

    public function test_a_colleagues_photo_is_never_the_callers(): void
    {
        $colleague = Employee::factory()->create();
        Storage::disk('local')->put('employee-documents/x.jpg', 'colleague');
        EmployeeDocument::create([
            'employee_id' => $colleague->id,
            'type'        => EmployeeDocumentType::PersonalPhoto,
            'file_path'   => 'employee-documents/x.jpg',
        ]);

        $this->getJson('/api/me/photo')->assertNotFound();

        $this->upload($this->jpg('me.jpg'))->assertCreated();
        $this->assertNotSoftDeleted(EmployeeDocument::where('employee_id', $colleague->id)->sole());
    }

    public function test_only_jpg_and_png_are_taken(): void
    {
        $this->upload(UploadedFile::fake()->create('me.pdf', 100, 'application/pdf'))
            ->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->upload(UploadedFile::fake()->create('me.gif', 100, 'image/gif'))
            ->assertUnprocessable()->assertJsonValidationErrors('photo');

        $this->assertSame(0, EmployeeDocument::count());
    }

    public function test_five_megabytes_is_the_limit(): void
    {
        $this->upload($this->jpg('big.jpg', 5121))
            ->assertUnprocessable()->assertJsonValidationErrors('photo');

        $this->upload($this->jpg('fits.jpg', 5120))->assertCreated();
    }

    public function test_the_file_is_required(): void
    {
        $this->upload(null)->assertUnprocessable()->assertJsonValidationErrors('photo');
    }

    public function test_an_account_with_no_employee_is_refused(): void
    {
        $this->actingAsRolelessUser();

        $this->upload($this->jpg('me.jpg'))->assertForbidden();
        $this->getJson('/api/me/photo')->assertForbidden();
        $this->assertSame(0, EmployeeDocument::count());
    }
}
