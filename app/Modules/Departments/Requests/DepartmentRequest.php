<?php

declare(strict_types=1);

namespace App\Modules\Departments\Requests;

use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\Departments\Support\DepartmentHierarchyGuard;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * DepartmentRequest (abstract base)
 *
 * Shared field rules for StoreDepartmentRequest and UpdateDepartmentRequest,
 * plus the front-door invocation of DepartmentHierarchyGuard.
 *
 * WHY the tier rules live in the application layer at all:
 * - The FK on parent_id guarantees only that the parent ROW EXISTS. It says
 *   nothing about whether the tier maths is right: the database will happily
 *   file a division under a section, or a section under a section, or a row at
 *   a level no tier is defined for. Every such rule is a thing the schema
 *   cannot express.
 *
 * WHY the rules are NOT implemented here anymore:
 * - They are owned by DepartmentHierarchyGuard, a single stateless class the
 *   Department model's saving hook ALSO calls. If the tier/root/singleton/cycle
 *   logic lived in this request it would only guard HTTP — the seeder, tinker,
 *   jobs and factories would write freely (which is how four roots got made).
 *   This request is now the friendly front door over the guard: it keeps the
 *   Arabic field-level messages and 422 semantics, and adds each guard
 *   violation to the validator.
 *
 * WHY a base class rather than one class branching on isMethod():
 * - The subject differs (null on create, the bound row on update), and that is
 *   the one input the guard needs to tell "create" from "move an existing row".
 *   A base with two tiny subclasses expresses that without isMethod() guards a
 *   reader has to mentally evaluate on every line.
 *
 * AUTHORIZATION:
 * - Route-level access is enforced by the `permission:departments.{action}`
 *   middleware (wired in routes.php). These requests authorize field-level only.
 */
abstract class DepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Coarse-grained access is handled by route middleware (auth:sanctum +
        // permission). Field-level validation lives here.
        return true;
    }

    /**
     * 'required' on create, 'sometimes' on update (PATCH-style partials).
     */
    abstract protected function presence(): string;

    /**
     * The row being validated, or null on create.
     */
    abstract protected function subject(): ?Department;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [$this->presence(), 'string', 'max:255'],

            // Optional short code, unique among live units (ignoring this row on
            // update). whereNull keeps a trashed unit's code from blocking reuse.
            'code' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('departments', 'code')
                    ->ignore($this->subject()?->id)
                    ->whereNull('deleted_at'),
            ],

            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],

            'is_active' => ['sometimes', 'boolean'],

            // Rule 4 (parent validity): must exist AND not be soft-deleted.
            // A plain `exists:departments,id` would happily accept a trashed
            // parent, re-parenting a live unit under a deleted one.
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('departments', 'id')->whereNull('deleted_at'),
            ],

            // Rule 3 (defined-level): Rule::enum rejects any level with no
            // DepartmentLevel case — the enum IS the registry of valid depth,
            // so this needs no separate max-depth constant.
            'level' => [$this->presence(), 'integer', Rule::enum(DepartmentLevel::class)],

            // manager_id references an employee. The employees table now exists,
            // so the previously deferred exists rule lands here: the manager must
            // be a real, non-soft-deleted employee. The FK guarantees only that
            // the row exists; this turns a bad id into a 422 instead of a DB error.
            'manager_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required'      => 'اسم الوحدة التنظيمية مطلوب.',
            'name.max'           => 'يجب ألا يتجاوز اسم الوحدة التنظيمية 255 حرفًا.',
            'code.unique'        => 'رمز الوحدة التنظيمية مستخدم بالفعل.',
            'parent_id.integer'  => 'معرّف الوحدة التنظيمية الأصل يجب أن يكون رقمًا.',
            'parent_id.exists'   => 'الوحدة التنظيمية الأصل غير موجودة أو تم حذفها.',
            'level.required'     => 'مستوى الوحدة التنظيمية مطلوب.',
            'level.integer'      => 'مستوى الوحدة التنظيمية يجب أن يكون رقمًا.',
            'level.enum'         => 'المستوى المحدد غير معرّف في هيكل الشركة.',
            'manager_id.integer' => 'معرّف المدير يجب أن يكون رقمًا.',
            'manager_id.exists'  => 'المدير المحدد غير موجود.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->applyHierarchyGuard($validator),
        ];
    }

    /**
     * Front door over DepartmentHierarchyGuard: run every structural invariant
     * (root tier, singleton root, child tier, self-reference, cycle) and surface
     * each violation as a field-level 422 error. Passing the subject id is what
     * tells the guard "create" (null) from "move an existing row".
     */
    protected function applyHierarchyGuard(Validator $validator): void
    {
        // If the fields the guard reads are already invalid, its output would
        // only pile a confusing second error on top.
        if ($validator->errors()->hasAny(['parent_id', 'level'])) {
            return;
        }

        $violations = (new DepartmentHierarchyGuard())->violations(
            $this->effectiveLevel(),
            $this->effectiveParentId(),
            $this->subject()?->id,
        );

        foreach ($violations as $violation) {
            $validator->errors()->add($violation->field, $violation->message);
        }
    }

    /**
     * The level this row will END UP at: the submitted one, or — on a partial
     * update that omits it — the one it already has.
     */
    protected function effectiveLevel(): ?int
    {
        if ($this->has('level')) {
            $level = $this->input('level');

            return is_numeric($level) ? (int) $level : null;
        }

        return $this->subject()?->level?->value;
    }

    /**
     * The parent this row will END UP under. Distinguishes "not submitted"
     * (keep current) from "submitted as null" (make it a root).
     */
    protected function effectiveParentId(): ?int
    {
        if ($this->has('parent_id')) {
            $parentId = $this->input('parent_id');

            return is_numeric($parentId) ? (int) $parentId : null;
        }

        return $this->subject()?->parent_id;
    }
}
