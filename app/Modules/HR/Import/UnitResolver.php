<?php

declare(strict_types=1);

namespace App\Modules\HR\Import;

use App\Modules\Departments\Models\Department;

/**
 * Places a workbook row — أسماء الإدارات / القسم / المكتب — in the org tree.
 *
 * The rules are the ones `DepartmentSeeder` was built on (decision #6) and the
 * HR review file showed against the October workbook
 * (`docs/hr-review/org-tree-review-2026-10-10.xlsx`):
 *
 * - Names are compared by ArabicText::key(), so spelling variants match.
 * - A row resolves by PATH: an office name like «الحسابات» exists under several
 *   sections, and only its section says which one.
 * - Placeholders are not units: a blank section or office, one repeating its
 *   parent's name, or a «مدير …» / «رئيس …» row stands for the parent itself.
 * - Equipment classes under قسم السائقين (باكر، تركس …) and workshops (ورشة …)
 *   are not units: the row lands on the section, and an `info` issue keeps the
 *   label in the report.
 * - A name the tree does not have falls back ONE level with a `warning`; a
 *   division the tree does not have cannot be placed (`error`). The importer
 *   reports both — it never invents a unit.
 */
final class UnitResolver
{
    /** Division spellings the workbook uses for a seeded division, by code. */
    private const DIVISION_ALIASES = [
        'المدير العام'           => 'GM',
        'إدارة التسويق'          => 'MKT',
        'إدارة التسويق والIT'    => 'MKT',
    ];

    /** Any-level spellings the seeder normalised on the way in (see its docblock). */
    private const NAME_ALIASES = [
        'الكميرات'              => 'الكاميرات',
        'اللوجستك والمستودعات'   => 'قسم اللوجستيك والمستودعات',
    ];

    private const DRIVERS_SECTION = 'HEQ-DRV';

    /**
     * @param  array<string, int>  $divisions  key(name) => id
     * @param  array<int, array<string, int>>  $children  parent id => [key(name) => id]
     * @param  array<int, string>  $codes  id => code
     */
    private function __construct(
        private readonly array $divisions,
        private readonly array $children,
        private readonly array $codes,
    ) {}

    public static function fromDatabase(): self
    {
        $units = Department::query()->get(['id', 'code', 'name', 'parent_id']);
        $root = $units->firstWhere('parent_id', null);

        $children = [];
        $codes = [];
        foreach ($units as $unit) {
            $codes[$unit->id] = (string) $unit->code;
            if ($unit->parent_id !== null) {
                $children[$unit->parent_id][ArabicText::key($unit->name)] = $unit->id;
            }
        }

        $divisions = $root === null ? [] : ($children[$root->id] ?? []);
        $byCode = array_flip($codes);
        foreach (self::DIVISION_ALIASES as $spelling => $code) {
            if (isset($byCode[$code])) {
                $divisions[ArabicText::key($spelling)] = $byCode[$code];
            }
        }

        return new self($divisions, $children, $codes);
    }

    public function resolve(?string $division, ?string $section, ?string $office): UnitResolution
    {
        $divisionKey = $this->key($division);

        if ($divisionKey === '') {
            return new UnitResolution(null, [$this->issue('error', 'الإدارة غير مذكورة — لا يمكن تحديد الوحدة.')]);
        }

        $divisionId = $this->divisions[$divisionKey] ?? null;
        if ($divisionId === null) {
            return new UnitResolution(null, [$this->issue('error', "الإدارة «{$division}» غير موجودة في الهيكل المعتمد.")]);
        }

        $sectionKey = $this->key($section);
        if ($this->isPlaceholder($sectionKey, $divisionKey)) {
            return new UnitResolution($divisionId);
        }

        $sectionId = $this->children[$divisionId][$sectionKey] ?? null;
        if ($sectionId === null) {
            return new UnitResolution($divisionId, [$this->issue('warning', "القسم «{$section}» غير موجود تحت «{$division}» — سُجّل الموظف على الإدارة.")]);
        }

        $officeKey = $this->key($office);
        if ($this->isPlaceholder($officeKey, $sectionKey)) {
            return new UnitResolution($sectionId);
        }

        $officeId = $this->children[$sectionId][$officeKey] ?? null;
        if ($officeId !== null) {
            return new UnitResolution($officeId);
        }

        if ($this->codes[$sectionId] === self::DRIVERS_SECTION) {
            return new UnitResolution($sectionId, [$this->issue('info', "«{$office}» نوع آلية وليس مكتباً — سُجّل الموظف على قسم السائقين.")]);
        }
        if (str_starts_with($officeKey, 'ورشه')) {
            return new UnitResolution($sectionId, [$this->issue('info', "«{$office}» ورشة وليست مكتباً — سُجّل الموظف على القسم.")]);
        }

        return new UnitResolution($sectionId, [$this->issue('warning', "المكتب «{$office}» غير موجود تحت «{$section}» — سُجّل الموظف على القسم.")]);
    }

    /**
     * A blank name, a repeat of the parent's name, or a «مدير …» / «رئيس …» row:
     * the parent itself.
     */
    private function isPlaceholder(string $key, string $parentKey): bool
    {
        return $key === ''
            || $key === $parentKey
            || str_starts_with($key, 'مدير')
            || str_starts_with($key, 'رئيس');
    }

    private function key(?string $name): string
    {
        $key = ArabicText::key($name);

        foreach (self::NAME_ALIASES as $spelling => $canonical) {
            if ($key === ArabicText::key($spelling)) {
                return ArabicText::key($canonical);
            }
        }

        return $key;
    }

    /**
     * @return array{severity: string, message: string}
     */
    private function issue(string $severity, string $message): array
    {
        return ['severity' => $severity, 'message' => $message];
    }
}
