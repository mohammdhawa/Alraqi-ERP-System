<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use App\Modules\Documents\Enums\FieldType;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Templates\Placeholders;
use App\Modules\Documents\Templates\TemplateBody;
use App\Modules\Documents\Templates\TemplateFields;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST and PUT /api/documents/templates (documents design §6, §8). The page
 * is checked by its byte length first, then sanitised, and the chips are
 * read from the sanitised page, which is what is stored.
 *
 * The preview (a later task) reuses these rules except the name's
 * uniqueness: `nameRules()` is the one thing it replaces.
 */
class SaveTemplateRequest extends FormRequest
{
    private const BOTH = 'اكتب صفحة القالب أو اختر تخطيطاً جاهزاً، لا كليهما.';

    private const NEITHER = 'اكتب صفحة القالب أو اختر تخطيطاً جاهزاً.';

    private const TOO_LONG = 'صفحة القالب لا تزيد على 200 كيلوبايت.';

    private ?string $sanitisedBody = null;

    public function authorize(): bool
    {
        return true;
    }

    /** The name's rules; the preview drops the uniqueness. */
    protected function nameRules(): array
    {
        /** @var DocumentTemplate|null $current */
        $current = $this->route('template');

        return [
            'required', 'string', 'max:150',
            Rule::unique('document_templates', 'name')->whereNull('deleted_at')->ignore($current?->id),
        ];
    }

    public function rules(): array
    {
        return [
            'name'             => $this->nameRules(),
            'category_id'      => ['required', 'integer', Rule::exists('document_template_categories', 'id')->whereNull('deleted_at')],
            'description'      => ['nullable', 'string', 'max:1000'],
            'is_active'        => ['sometimes', 'boolean'],
            'for_employees'    => ['sometimes', 'boolean'],
            'show_letterhead'  => ['sometimes', 'boolean'],
            'reference_prefix' => ['required', 'string', 'regex:/^[A-Z]{2,10}$/'],
            'fields'           => ['sometimes', 'nullable', 'array'],
            'body'             => [
                'nullable', 'string',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && strlen($value) > TemplateBody::MAX_BYTES) {
                        $fail(self::TOO_LONG);
                    }
                },
            ],
            'layout_key' => ['nullable', 'string', Rule::in(array_keys((array) config('documents.layouts', [])))],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'             => 'اسم القالب مطلوب.',
            'name.string'               => 'اسم القالب مطلوب.',
            'name.max'                  => 'اسم القالب لا يزيد على 150 حرفاً.',
            'name.unique'               => 'يوجد قالب بهذا الاسم.',
            'category_id.required'      => 'التصنيف مطلوب.',
            'category_id.integer'       => 'التصنيف المختار غير موجود.',
            'category_id.exists'        => 'التصنيف المختار غير موجود.',
            'description.string'        => 'وصف القالب غير صالح.',
            'description.max'           => 'وصف القالب لا يزيد على 1000 حرف.',
            'is_active.boolean'         => 'حالة القالب غير صالحة.',
            'for_employees.boolean'     => 'نوع القالب غير صالح.',
            'show_letterhead.boolean'   => 'خيار الترويسة غير صالح.',
            'reference_prefix.required' => 'بادئة المرجع من 2 إلى 10 أحرف إنجليزية كبيرة.',
            'reference_prefix.string'   => 'بادئة المرجع من 2 إلى 10 أحرف إنجليزية كبيرة.',
            'reference_prefix.regex'    => 'بادئة المرجع من 2 إلى 10 أحرف إنجليزية كبيرة.',
            'fields.array'              => 'حقول القالب غير صالحة.',
            'body.string'               => self::NEITHER,
            'layout_key.string'         => 'التخطيط المختار غير موجود.',
            'layout_key.in'             => 'التخطيط المختار غير موجود.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            // A rule failed on the page (its size included): it is not read,
            // so the sanitiser never sees an oversized one.
            $pageRefused = $validator->errors()->has('body') || $validator->errors()->has('layout_key');

            $body      = $this->input('body');
            $layout    = $this->input('layout_key');
            $hasBody   = is_string($body) && trim($body) !== '';
            $hasLayout = is_string($layout) && $layout !== '';

            if (! $pageRefused && $hasBody && $hasLayout) {
                $validator->errors()->add('body', self::BOTH);
                $pageRefused = true;
            }

            if (! $pageRefused && ! $hasBody && ! $hasLayout) {
                $validator->errors()->add('body', self::NEITHER);
            }

            $fields = (array) ($this->input('fields') ?? []);
            $problems = TemplateFields::problems($fields);

            foreach ($problems as $problem) {
                $validator->errors()->add('fields', $problem);
            }

            if ($pageRefused || ! $hasBody) {
                return;
            }

            $this->sanitisedBody = (new TemplateBody)->sanitize((string) $body);

            if ($problems !== []) {
                return;
            }

            $unknown = Placeholders::unknown(
                (new TemplateBody)->placeholders($this->sanitisedBody),
                TemplateFields::fromArray($fields),
                $this->boolean('for_employees'),
            );

            foreach ($unknown as $key) {
                $validator->errors()->add('body', "العنصر «{$key}» في صفحة القالب غير معرّف.");
            }
        }];
    }

    /**
     * The values to store: the sanitised page (or the layout), the fields in
     * their canonical shape, and the flags with their defaults.
     *
     * @return array<string, mixed>
     */
    public function templateData(): array
    {
        $layout = $this->input('layout_key');
        $hasLayout = is_string($layout) && $layout !== '';

        return [
            'name'             => (string) $this->validated('name'),
            'category_id'      => (int) $this->validated('category_id'),
            'description'      => $this->validated('description'),
            'is_active'        => $this->boolean('is_active', true),
            'for_employees'    => $this->boolean('for_employees'),
            'show_letterhead'  => $this->boolean('show_letterhead', true),
            'reference_prefix' => (string) $this->validated('reference_prefix'),
            'fields'           => $this->canonicalFields(),
            'body'             => $hasLayout ? null : $this->sanitisedBody,
            'layout_key'       => $hasLayout ? $layout : null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function canonicalFields(): array
    {
        $out = [];

        foreach ((array) ($this->input('fields') ?? []) as $field) {
            $type = FieldType::from((string) $field['type']);

            $item = [
                'key'      => (string) $field['key'],
                'label'    => (string) $field['label'],
                'type'     => $type->value,
                'required' => filter_var($field['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];

            if ($type === FieldType::Select) {
                $item['options'] = array_values((array) $field['options']);
            }

            if ($type === FieldType::Table) {
                $item['columns'] = array_map(
                    static fn (array $c): array => ['key' => (string) $c['key'], 'label' => (string) ($c['label'] ?? '')],
                    array_values((array) $field['columns']),
                );
            }

            $out[] = $item;
        }

        return $out;
    }
}
