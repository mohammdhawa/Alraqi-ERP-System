<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Modules\HR\Enums\ActivityType;
use App\Modules\HR\Models\JobTitle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * JobTitleRequest
 *
 * Validates creating and updating a catalogue entry.
 *
 * ⚠️ `name` IS CHECKED AGAINST TRASHED ROWS TOO — note the ABSENCE of
 * `->whereNull('deleted_at')`, which is the opposite of what C24 asks for
 * elsewhere and is correct here. C24 exists so a validation rule matches its
 * index; `job_titles.name` has a plain unique index and the table soft-deletes,
 * so the index counts trashed rows. Excluding them would let validation pass
 * and the INSERT then fail, handing the caller a 500 where they had earned a
 * 422. The trade-off — a deleted title's name is unusable until the row is
 * force-deleted — is the same one `users.email` carries, taken for the same
 * reason.
 */
class JobTitleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255',
                Rule::unique('job_titles', 'name')->ignore($this->subject()?->id)],

            // Suggestions for the employee form, not constraints on it — a
            // section head and a division head hold one title at two grades.
            'default_organizational_grade' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'default_activity_type'        => ['sometimes', 'nullable', 'integer', Rule::enum(ActivityType::class)],

            'is_described' => ['sometimes', 'boolean'],
            'description'  => ['sometimes', 'nullable', 'string', 'max:10000'],
            'is_active'    => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم المسمى الوظيفي مطلوب.',
            'name.unique'   => 'هذا المسمى الوظيفي موجود مسبقاً.',
            'name.max'      => 'يجب ألا يتجاوز اسم المسمى الوظيفي 255 حرفاً.',
            'default_organizational_grade.between' => 'السوية التنظيمية يجب أن تكون بين 1 و 5.',
            'default_activity_type.enum'           => 'نوع النشاط المحدد غير معرّف.',
        ];
    }

    /**
     * The title being updated, or null on create.
     */
    protected function subject(): ?JobTitle
    {
        $jobTitle = $this->route('jobTitle');

        return $jobTitle instanceof JobTitle ? $jobTitle : null;
    }
}
