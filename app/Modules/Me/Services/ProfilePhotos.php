<?php

declare(strict_types=1);

namespace App\Modules\Me\Services;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeDocumentType;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\Me\Exceptions\PhotoNotFoundException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The employee's personal photo (الصورة الشخصية), an `employee_documents` row
 * of type PersonalPhoto (work order §4.8, decision of 2026-10-14).
 *
 * The owner's rules of 2026-10-19: the private disk, a name the server
 * chose, never a URL; a new upload REPLACES the old one — the old row is
 * soft-deleted, so the trail keeps it, and its file removed; nobody approves
 * it. Other kinds of document on the same person are not touched.
 *
 * A FILE IS NOT IN THE TRANSACTION (as AttachmentStore): the new file is
 * removed if the rows fail, and the old files only once the rows commit.
 */
final class ProfilePhotos
{
    public const DISK = 'local';

    public function replace(Employee $employee, UploadedFile $file, User $by): EmployeeDocument
    {
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'jpg');
        $path = $file->storeAs('employee-documents/'.$employee->getKey(), Str::uuid().'.'.$extension, self::DISK);

        try {
            [$photo, $replaced] = DB::transaction(function () use ($employee, $file, $by, $path): array {
                // Two uploads at once would otherwise each find nothing to
                // replace and both stay.
                Employee::query()->whereKey($employee->getKey())->lockForUpdate()->first();

                $old = $this->photos($employee)->get();
                $old->each->delete();

                $photo = EmployeeDocument::create([
                    'employee_id'   => $employee->getKey(),
                    'type'          => EmployeeDocumentType::PersonalPhoto,
                    'file_path'     => $path,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'mime_type'     => (string) $file->getMimeType(),
                    'size_bytes'    => (int) $file->getSize(),
                    'uploaded_by'   => $by->getKey(),
                ]);

                return [$photo, $old->pluck('file_path')->filter()->all()];
            });
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($path);

            throw $e;
        }

        if ($replaced !== []) {
            Storage::disk(self::DISK)->delete($replaced);
        }

        return $photo;
    }

    /**
     * @throws PhotoNotFoundException no photo, or its file is gone
     */
    public function pathOf(Employee $employee): string
    {
        $path = $this->photos($employee)->orderByDesc('id')->value('file_path');

        if ($path === null || ! Storage::disk(self::DISK)->exists($path)) {
            throw new PhotoNotFoundException;
        }

        return $path;
    }

    /**
     * @return Builder<EmployeeDocument>
     */
    private function photos(Employee $employee): Builder
    {
        return EmployeeDocument::query()
            ->where('employee_id', $employee->getKey())
            ->where('type', EmployeeDocumentType::PersonalPhoto->value);
    }
}
