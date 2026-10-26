<?php

declare(strict_types=1);

namespace App\Modules\HR\Exceptions;

use App\Modules\HR\Import\WorkbookException;
use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The upload import's refusals (EMPLOYEE_IMPORT_UPLOAD_DESIGN.md §8). Each
 * carries its Arabic message and its status as data; ApiExceptionRenderer
 * renders it (C17).
 */
class EmployeeImportException extends \RuntimeException implements DomainException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY,
    ) {
        parent::__construct($message);
    }

    /** The hour passed: the files are gone or about to be (U5). */
    public static function expired(): self
    {
        return new self('انتهت صلاحية هذا الفحص، ارفع الملف مرة أخرى.');
    }

    /** A run imports once — a second press of the button must create nobody. */
    public static function alreadyExecuted(): self
    {
        return new self('تم تنفيذ هذا الاستيراد من قبل.');
    }

    /**
     * Another check or import holds the lock: two real runs together could
     * create the same person twice, and a dry run beside one would report a
     * half-written state (§4.4).
     */
    public static function busy(): self
    {
        return new self('يجري استيراد آخر الآن، حاول بعد قليل.', Response::HTTP_CONFLICT);
    }

    /**
     * A stored file no longer matches the hash taken at the check: the real
     * run would not import what was reviewed (U5).
     */
    public static function filesChanged(): self
    {
        return new self('تغيّر الملف المخزّن منذ الفحص؛ ارفع الملف مرة أخرى.');
    }

    /**
     * The screen asking to import showed another exclusion list than the one
     * saved — a lost apply response, a second tab (design §12.2).
     */
    public static function staleExclusions(): self
    {
        return new self('تغيّرت قائمة المستبعدين منذ عُرضت هذه الصفحة؛ راجع الفحص ثم استورد.');
    }

    /** A row number the uploaded file does not have (design §12.5). */
    public static function rowNotInFile(int $row): self
    {
        return new self("السطر {$row} غير موجود في الملف.");
    }

    /** A row the first dry run would not import: there is nothing to leave out. */
    public static function rowNotImportable(int $row): self
    {
        return new self("السطر {$row} لا يمكن استبعاده: لن يُستورد أصلاً.");
    }

    /** The reader's message names no file (C12), so say it was HR's. */
    public static function unreadable(WorkbookException $e): self
    {
        return new self("ملف الموارد: {$e->getMessage()}");
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
