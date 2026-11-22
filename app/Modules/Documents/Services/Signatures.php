<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Documents\Enums\SignatureSource;
use App\Modules\Documents\Exceptions\NoSignatureException;
use App\Modules\Documents\Exceptions\SignatureExistsException;
use App\Modules\Documents\Models\EmployeeSignature;
use App\Modules\HR\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Employees' signatures (documents design §10.1): drawn once, the first time
 * one is needed (D14, D24); kept encrypted (I.9); never replaced by their
 * owner (D15); deleted only by the system administrator, the version kept.
 *
 * WHO is the caller's: the owner's routes pass the caller's own employee,
 * and the deletion route is the system administrator's ability.
 */
final class Signatures
{
    public const DIRECTORY = 'signatures';

    public function __construct(private readonly SealedImages $sealed) {}

    public function current(Employee $employee): ?EmployeeSignature
    {
        return EmployeeSignature::query()->where('employee_id', $employee->getKey())->latest('id')->first();
    }

    /**
     * @throws SignatureExistsException
     */
    public function draw(Employee $employee, UploadedFile $png, SignatureSource $on, User $by): EmployeeSignature
    {
        $path = $this->sealed->put((string) file_get_contents((string) $png->getRealPath()), self::DIRECTORY);

        try {
            return DB::transaction(function () use ($employee, $on, $path): EmployeeSignature {
                // Two drawings at once would otherwise each find none and
                // both stay.
                Employee::query()->whereKey($employee->getKey())->lockForUpdate()->first();

                if ($this->current($employee) !== null) {
                    throw new SignatureExistsException;
                }

                return EmployeeSignature::create([
                    'employee_id' => $employee->getKey(),
                    'path'        => $path,
                    'drawn_on'    => $on,
                ]);
            });
        } catch (Throwable $e) {
            $this->sealed->delete($path);

            throw $e;
        }
    }

    public function bytes(EmployeeSignature $signature): string
    {
        return $this->sealed->get($signature->path);
    }

    /**
     * Soft-delete the live version, keeping its file: a printed mark may
     * point at it (I.10).
     *
     * @throws NoSignatureException
     */
    public function delete(Employee $employee, User $by, string $reason): EmployeeSignature
    {
        return DB::transaction(function () use ($employee, $by, $reason): EmployeeSignature {
            Employee::query()->whereKey($employee->getKey())->lockForUpdate()->first();

            $signature = $this->current($employee) ?? throw new NoSignatureException;

            $signature->forceFill(['deleted_by' => $by->getKey(), 'delete_reason' => $reason])->save();
            $signature->delete();

            return $signature;
        });
    }
}
