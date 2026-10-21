<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

use App\Modules\Approvals\Contracts\Approvable;
use App\Modules\Approvals\Contracts\FiltersApprovals;
use App\Modules\Approvals\Models\Approval;
use App\Modules\Departments\Models\Department;
use App\Shared\Support\SearchTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\Rule;

/**
 * The filters both approval lists take — the approver's inbox and HR's list
 * of every approval (owner, 2026-10-20, parity audit C-4) — after legacy's
 * leave queue (`LeaveApprovalsRequest`): who filed it, their unit, the kind
 * of request, when it was filed, and each subject's own fields
 * (FiltersApprovals).
 *
 * - `search` — a part of the requester's name, or their staff number;
 * - `department_id` / `department_ids` — the requester's CURRENT unit, each
 *   with its subtree (D6, as the attendance lists);
 * - `subject_type` — the subject's morph alias (`leave_request`);
 * - `submitted_from` / `submitted_to` — the day the request was filed;
 * - the subjects' own filters, e.g. leave's `leave_type_id(s)` and its
 *   `date_from` / `date_to` overlap.
 */
final class ApprovalFilters
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $rules = [
            'search'           => ['sometimes', 'nullable', 'string', 'max:100'],
            'department_id'    => ['sometimes', 'integer', Rule::exists('departments', 'id')],
            'department_ids'   => ['sometimes', 'array'],
            'department_ids.*' => ['integer', Rule::exists('departments', 'id')],
            'subject_type'     => ['sometimes', 'string', Rule::in(array_keys(self::subjectTypes()))],
            'submitted_from'   => ['sometimes', 'date_format:Y-m-d'],
            'submitted_to'     => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:submitted_from'],
        ];

        foreach (self::filteringTypes() as $class) {
            $rules += $class::approvalFilterRules();
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        $messages = [
            'search.string'                => 'نص البحث غير صالح.',
            'search.max'                   => 'نص البحث طويل جداً.',
            'department_id.integer'        => 'الوحدة غير صالحة.',
            'department_id.exists'         => 'الوحدة غير موجودة.',
            'department_ids.array'         => 'قائمة الوحدات غير صالحة.',
            'department_ids.*.integer'     => 'الوحدة غير صالحة.',
            'department_ids.*.exists'      => 'إحدى الوحدات غير موجودة.',
            'subject_type.in'              => 'نوع الطلب غير صالح.',
            'submitted_from.date_format'   => 'تاريخ التقديم (من) يجب أن يكون بالصيغة YYYY-MM-DD.',
            'submitted_to.date_format'     => 'تاريخ التقديم (إلى) يجب أن يكون بالصيغة YYYY-MM-DD.',
            'submitted_to.after_or_equal'  => 'تاريخ التقديم (إلى) يجب أن يكون بعد تاريخ البداية أو مساوياً له.',
        ];

        foreach (self::filteringTypes() as $class) {
            $messages += $class::approvalFilterMessages();
        }

        return $messages;
    }

    /**
     * Narrow a query of APPROVALS by the validated filters.
     *
     * @param  Builder<Approval>  $approvals
     * @param  array<string, mixed>  $filters
     * @return Builder<Approval>
     */
    public static function apply(Builder $approvals, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $units = array_values(array_unique(array_map('intval', [
            ...($filters['department_ids'] ?? []),
            ...(isset($filters['department_id']) ? [$filters['department_id']] : []),
        ])));

        if ($search !== '' || $units !== []) {
            $approvals->whereHas('requester', static function (Builder $requester) use ($search, $units): void {
                if ($units !== []) {
                    $requester->whereIn('department_id', Department::subtreeIds($units));
                }

                if ($search !== '') {
                    $requester->where(static function (Builder $inner) use ($search): void {
                        foreach (['first_name', 'middle_name', 'last_name', 'employee_number'] as $column) {
                            SearchTerm::orWhere($inner, $column, $search);
                        }
                    });
                }
            });
        }

        if (isset($filters['subject_type'])) {
            $approvals->where('approvable_type', $filters['subject_type']);
        }

        if (isset($filters['submitted_from'])) {
            $approvals->whereDate($approvals->qualifyColumn('created_at'), '>=', $filters['submitted_from']);
        }

        if (isset($filters['submitted_to'])) {
            $approvals->whereDate($approvals->qualifyColumn('created_at'), '<=', $filters['submitted_to']);
        }

        // A subject's own filter narrows the list to the types that read it.
        $given = array_keys(array_filter($filters, static fn (mixed $value): bool => $value !== null && $value !== []));
        $readers = array_filter(
            self::filteringTypes(),
            static fn (string $class): bool => array_intersect($given, self::keysOf($class)) !== [],
        );

        if ($readers !== []) {
            $approvals->whereHasMorph(
                'approvable',
                array_values($readers),
                static function (Builder $subjects, string $class) use ($filters): void {
                    /** @var class-string<FiltersApprovals> $class */
                    $class::applyApprovalFilters($subjects, $filters);
                },
            );
        }

        return $approvals;
    }

    /**
     * Every approvable type, alias → class.
     *
     * @return array<string, class-string<Approvable>>
     */
    private static function subjectTypes(): array
    {
        return array_filter(
            Relation::morphMap(),
            static fn (string $class): bool => is_subclass_of($class, Approvable::class),
        );
    }

    /**
     * @return array<string, class-string<FiltersApprovals>>
     */
    private static function filteringTypes(): array
    {
        return array_filter(
            self::subjectTypes(),
            static fn (string $class): bool => is_subclass_of($class, FiltersApprovals::class),
        );
    }

    /**
     * The top-level parameters a type's rules name (`leave_type_ids.*` is
     * `leave_type_ids`).
     *
     * @param  class-string<FiltersApprovals>  $class
     * @return array<int, string>
     */
    private static function keysOf(string $class): array
    {
        return array_values(array_unique(array_map(
            static fn (string $key): string => explode('.', $key)[0],
            array_keys($class::approvalFilterRules()),
        )));
    }
}
