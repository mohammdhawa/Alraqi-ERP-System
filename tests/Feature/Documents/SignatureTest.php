<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\User;
use App\Modules\Documents\Enums\SignatureSource;
use App\Modules\Documents\Models\EmployeeSignature;
use App\Modules\Documents\Services\SealedImages;
use App\Modules\HR\Models\Employee;
use App\Shared\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Pdfs;
use Tests\TestCase;

/**
 * An employee's signature (documents design §10.1; D11, D14, D15, D24, I.9):
 * drawn once, the first time it is needed, kept encrypted with the account;
 * its owner sees it and can neither delete, replace nor redraw it; only the
 * system administrator deletes one, and every deleted version is kept.
 */
class SignatureTest extends TestCase
{
    use RefreshDatabase;

    private const SIZE_OR_TYPE = 'التوقيع يجب أن يكون صورة PNG لا يتجاوز حجمها 512 كيلوبايت.';

    private Employee $employee;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->employee = Employee::factory()->create();
        $this->owner = User::factory()->create(['employee_id' => $this->employee->id, 'must_change_password' => false]);
    }

    private function asOwner(): void
    {
        Sanctum::actingAs($this->owner);
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->image('signature.png', 600, 200);
    }

    /**
     * A real PNG over 512 KB: random pixels do not compress.
     */
    private function bigPng(): UploadedFile
    {
        $image = imagecreatetruecolor(700, 700);

        for ($x = 0; $x < 700; $x++) {
            for ($y = 0; $y < 700; $y++) {
                imagesetpixel($image, $x, $y, random_int(0, 0xFFFFFF));
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'sig').'.png';
        imagepng($image, $path);

        $this->assertGreaterThan(512 * 1024, filesize($path), 'the fixture must exceed the limit');

        return new UploadedFile($path, 'big.png', 'image/png', null, true);
    }

    private function draw(?UploadedFile $file, int $drawnOn = 1): TestResponse
    {
        return $this->post('/api/me/signature', array_filter(['signature' => $file, 'drawn_on' => $drawnOn]), ['Accept' => 'application/json']);
    }

    private function deleteAs(string $reason = 'توقيع غير صالح'): TestResponse
    {
        return $this->deleteJson("/api/hr/employees/{$this->employee->id}/signature", ['reason' => $reason]);
    }

    public function test_an_employee_draws_their_signature(): void
    {
        $this->asOwner();

        $this->draw($this->png())
            ->assertCreated()
            ->assertJsonPath('message', 'تم حفظ توقيعك.')
            ->assertJsonStructure(['data' => ['drawn_at']]);

        $signature = EmployeeSignature::sole();
        $this->assertSame($this->employee->id, $signature->employee_id);
        $this->assertSame(SignatureSource::App, $signature->drawn_on);
        Storage::disk('local')->assertExists($signature->path);
        $this->assertStringNotContainsString("\x89PNG", Storage::disk('local')->get($signature->path));
    }

    public function test_the_owner_sees_their_own_signature(): void
    {
        $this->asOwner();

        $this->getJson('/api/me/signature')->assertNotFound()->assertJsonPath('message', 'لا يوجد توقيع محفوظ.');

        $png = $this->png();
        $bytes = (string) file_get_contents($png->getRealPath());
        $this->draw($png)->assertCreated();

        $response = $this->get('/api/me/signature')->assertOk();

        $this->assertSame($bytes, $response->streamedContent());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_signature_cannot_be_replaced_by_its_owner(): void
    {
        $this->asOwner();
        $this->draw($this->png())->assertCreated();
        $files = Storage::disk('local')->allFiles();

        $this->draw($this->png(), 2)
            ->assertStatus(409)
            ->assertJsonPath('message', 'لديك توقيع محفوظ، ولا يمكن تغييره. لحذفه راجع مدير النظام.');

        $this->assertSame(1, EmployeeSignature::count());
        $this->assertSame($files, Storage::disk('local')->allFiles(), 'the refused drawing leaves no file');

        $this->deleteJson('/api/me/signature')->assertStatus(405);
    }

    public function test_only_a_real_png_within_512_kb_is_taken(): void
    {
        $this->asOwner();

        $files = [
            UploadedFile::fake()->image('signature.jpg', 600, 200),
            $this->bigPng(),
            UploadedFile::fake()->createWithContent('signature.png', Pdfs::plain()),
        ];

        foreach ($files as $file) {
            $this->draw($file)->assertUnprocessable()->assertJsonValidationErrors(['signature' => self::SIZE_OR_TYPE]);
        }

        $this->draw(null)->assertUnprocessable()->assertJsonValidationErrors('signature');
        $this->draw($this->png(), 3)->assertUnprocessable()->assertJsonValidationErrors('drawn_on');

        $this->assertSame(0, EmployeeSignature::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /**
     * Final review, 2026-11-22: a cut-off upload still has a valid header. A
     * broken signature could never be replaced by its owner (D15) and would
     * break every print, so it is refused at the door.
     */
    public function test_a_truncated_png_is_refused(): void
    {
        $this->asOwner();
        $png = $this->png();
        $cut = Pdfs::truncated((string) file_get_contents((string) $png->getRealPath()));

        $this->draw(UploadedFile::fake()->createWithContent('signature.png', $cut))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['signature' => self::SIZE_OR_TYPE]);

        $this->assertSame(0, EmployeeSignature::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_system_administrator_deletes_a_signature_with_a_reason(): void
    {
        $this->asOwner();
        $png = $this->png();
        $bytes = (string) file_get_contents($png->getRealPath());
        $this->draw($png)->assertCreated();
        $first = EmployeeSignature::sole();

        $admin = $this->actingAsAdmin();
        $this->deleteAs('توقيع مرسوم على عجل')->assertOk()->assertJsonPath('message', 'تم حذف التوقيع.');

        $deleted = EmployeeSignature::withTrashed()->findOrFail($first->id);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertSame($admin->id, $deleted->deleted_by);
        $this->assertSame('توقيع مرسوم على عجل', $deleted->delete_reason);
        Storage::disk('local')->assertExists($deleted->path);
        $this->assertSame($bytes, app(SealedImages::class)->get($deleted->path), 'the deleted version is kept, and still readable');

        // The trail says who deleted it. Audit failures are swallowed by
        // design, so an unregistered model would leave no row at all.
        $this->assertTrue(
            AuditLog::query()
                ->where('auditable_type', 'employee_signature')
                ->where('auditable_id', $first->id)
                ->where('event', 'deleted')
                ->where('user_id', $admin->id)
                ->exists(),
            'the deletion is in the audit trail, with the administrator as actor',
        );

        $this->asOwner();
        $this->getJson('/api/me/signature')->assertNotFound();
        $this->draw($this->png())->assertCreated();

        $this->assertSame(2, EmployeeSignature::withTrashed()->count());
        $this->assertSame(1, EmployeeSignature::count());
    }

    public function test_a_reason_is_required(): void
    {
        $this->asOwner();
        $this->draw($this->png())->assertCreated();
        $this->actingAsAdmin();

        foreach (['', 'لا', str_repeat('س', 501)] as $reason) {
            $this->deleteAs($reason)->assertUnprocessable()->assertJsonValidationErrors(['reason' => 'سبب الحذف مطلوب، من 3 إلى 500 حرف.']);
        }

        $this->assertSame(1, EmployeeSignature::count());
    }

    public function test_deleting_when_there_is_none_is_404(): void
    {
        $this->actingAsAdmin();

        $this->deleteAs()->assertNotFound()->assertJsonPath('message', 'لا يوجد توقيع محفوظ.');
    }

    public function test_nobody_else_deletes_or_reads_a_signature(): void
    {
        $this->asOwner();
        $this->draw($this->png())->assertCreated();

        $this->seedRbac();
        $this->actingAsUserWithPermissions(Permission::pluck('name')->all());

        $this->getJson("/api/hr/employees/{$this->employee->id}/signature")->assertForbidden();
        $this->deleteAs()->assertForbidden();
        $this->assertSame(1, EmployeeSignature::count());

        // There is no route that streams another person's signature.
        $this->get("/api/hr/employees/{$this->employee->id}/signature/image")->assertNotFound();
    }

    public function test_the_administrator_sees_the_history_without_the_image(): void
    {
        $this->asOwner();
        $this->draw($this->png())->assertCreated();
        $this->actingAsAdmin();
        $this->deleteAs('توقيع غير واضح')->assertOk();
        $this->asOwner();
        $this->draw($this->png(), 2)->assertCreated();
        $this->actingAsAdmin();

        $response = $this->getJson("/api/hr/employees/{$this->employee->id}/signature")->assertOk();

        $response->assertJsonPath('data.current.drawn_on', 2)
            ->assertJsonCount(1, 'data.deleted')
            ->assertJsonPath('data.deleted.0.drawn_on', 1)
            ->assertJsonPath('data.deleted.0.delete_reason', 'توقيع غير واضح');
        $this->assertNotNull($response->json('data.current.drawn_at'));
        $this->assertNotNull($response->json('data.deleted.0.deleted_at'));
        $this->assertStringNotContainsString('sealed/', (string) $response->getContent(), 'no path is exposed');
    }

    public function test_an_account_without_an_employee_cannot_draw(): void
    {
        Sanctum::actingAs(User::factory()->create(['employee_id' => null, 'must_change_password' => false]));

        $this->draw($this->png())->assertForbidden()->assertJsonPath('message', 'لا يوجد موظف مرتبط بهذا الحساب.');
        $this->assertSame(0, EmployeeSignature::count());
    }
}
