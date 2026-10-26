<?php

declare(strict_types=1);

namespace Tests\Feature\HR\Import;

use App\Modules\HR\Import\WorkbookException;
use App\Modules\HR\Import\WorkbookReader;
use DateTimeImmutable;

/**
 * One person in HR's one file (row 4), the صرح columns merged in; refuses the stored file
 * named in $refuse the way the real reader refuses a sheet it cannot find.
 * $rows, when set, replaces the workbook's rows. Every test class calls
 * reset() in setUp: the statics outlive a test.
 */
class FakeImportReader implements WorkbookReader
{
    public static ?string $refuse = null;

    /** @var array<int, array<string, mixed>>|null sheet row number => header => value */
    public static ?array $rows = null;

    public static function reset(): void
    {
        self::$refuse = null;
        self::$rows = null;
    }

    /**
     * A workbook row for one more person: row 4's unit and title, another
     * Kobo id and name.
     *
     * @return array<string, mixed>
     */
    public static function person(int $kobo, string $name): array
    {
        $parts = explode(' ', $name, 3);

        return [
            'ID_Sarah'      => $kobo,
            'الإسم الثلاثي' => $name,
            'الاسم الأول'   => $parts[0] ?? null,
            'اسم الاب'      => $parts[1] ?? null,
            'الكنية'        => $parts[2] ?? null,
            'اسم الام'      => null,
            'الرقم الوطني في الهوية' => null,
        ] + self::nour();
    }

    public function rows(string $path, string $sheet, int $headerRow, ?array $columns = null): array
    {
        if (self::$refuse !== null && str_ends_with(str_replace('\\', '/', $path), '/'.self::$refuse)) {
            throw new WorkbookException("لا توجد ورقة باسم «{$sheet}». الأوراق الموجودة: Sheet1");
        }

        return self::$rows ?? [4 => self::nour()];
    }

    /** @return array<string, mixed> */
    private static function nour(): array
    {
        return [
            'ID_Sarah'       => 582974643,
            'الإسم الثلاثي'  => 'نور أحمد الشامي',
            'الاسم الأول'    => 'نور',
            'اسم الاب'       => 'أحمد',
            'الكنية'         => 'الشامي',
            'اسم الام'       => 'فاطمة',
            'الرقم الوطني في الهوية' => '01234567890',
            'أسماء الإدارات' => 'الإدارة الهندسية',
            'القسم'          => 'قسم التخطيط والدراسات',
            'المكتب'         => 'المعماري',
            'المسمى الوظيفي' => 'مهندس معماري',
            'الحالة'         => 'متعاقد',
            'الجنس'          => 'ذكر',
            'تاريخ التعيين'  => new DateTimeImmutable('2022-01-01'),
        ];
    }
}
