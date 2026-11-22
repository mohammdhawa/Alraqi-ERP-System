<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Documents\Services\SealedImages;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Signatures and stamps are kept on the private disk, encrypted with the app
 * key (I.9): a copy of the storage folder alone prints nobody's signature.
 */
class SealedImagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function pngBytes(): string
    {
        // Held in a variable: the fake's temp file goes with the object.
        $image = UploadedFile::fake()->image('s.png', 60, 20);

        return (string) file_get_contents((string) $image->getRealPath());
    }

    public function test_an_image_is_stored_encrypted_and_reads_back(): void
    {
        $bytes = $this->pngBytes();
        $sealed = app(SealedImages::class);

        $path = $sealed->put($bytes, 'signatures');

        $this->assertStringStartsWith('sealed/signatures/', $path);
        $this->assertStringEndsWith('.bin', $path);
        Storage::disk('local')->assertExists($path);
        $this->assertStringNotContainsString("\x89PNG", Storage::disk('local')->get($path), 'the image is not stored in the clear');
        $this->assertSame($bytes, $sealed->get($path));
    }

    public function test_a_tampered_file_does_not_decrypt(): void
    {
        $sealed = app(SealedImages::class);
        $path = $sealed->put($this->pngBytes(), 'stamps');

        $stored = Storage::disk('local')->get($path);
        $payload = json_decode(base64_decode($stored), true);
        $payload['value'] = base64_encode(strrev(base64_decode($payload['value'])));
        Storage::disk('local')->put($path, base64_encode((string) json_encode($payload)));

        $this->expectException(DecryptException::class);

        $sealed->get($path);
    }

    public function test_delete_removes_the_file(): void
    {
        $sealed = app(SealedImages::class);
        $path = $sealed->put($this->pngBytes(), 'stamps');

        $sealed->delete($path);

        Storage::disk('local')->assertMissing($path);
    }
}
