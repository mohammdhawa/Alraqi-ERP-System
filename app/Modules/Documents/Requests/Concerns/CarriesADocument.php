<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests\Concerns;

use App\Modules\Documents\Pdf\PdfIntake;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * What every document a request sends carries, in pieces 2–3's rules and
 * words (documents design §7.1, §8.3): a title, an optional description, and
 * its original — a file uploaded, or a template with its values and,
 * optionally, a typed صادر number, never both. Between managers and to an
 * employee alike; each request adds its own fields around these.
 *
 * The file's TYPE is not a rule here: the intake reads the content and
 * refuses anything but a PDF or a photo, in its own words. The template's
 * standing, its values (checked field by field against the template as it
 * is at sending) and the صادر number's (a cold register, a number already
 * used) are the services'.
 */
trait CarriesADocument
{
    private const FILE_MESSAGE = 'يُرجى رفع الملف بصيغة PDF أو صورة لا يتجاوز حجمه 20 ميغابايت.';

    private const EXPORT_RANGE_MESSAGE = 'رقم الصادر يجب أن يكون بين 1 و 999999999.';

    private const FIELD_VALUES_MESSAGE = 'بيانات المستند غير صالحة.';

    /**
     * @return array<string, mixed>
     */
    protected function headingRules(): array
    {
        return [
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function originalRules(): array
    {
        return [
            'file'          => ['nullable', 'required_without:template_id', 'file', 'max:'.PdfIntake::MAX_KB],
            'template_id'   => ['nullable', 'integer', 'prohibits:file'],
            'export_number' => [
                'nullable', 'integer', 'min:1', 'max:999999999',
                Rule::prohibitedIf(fn (): bool => ! $this->filled('template_id')),
            ],
            // An object keyed by field key (ruling R15): null, absent and an
            // empty one are no values; a string, a number or a list is refused.
            // Each field's own value is the service's, against the template.
            'field_values'  => [
                'nullable',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_array($value) || ($value !== [] && array_is_list($value))) {
                        $fail(self::FIELD_VALUES_MESSAGE);
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function documentMessages(): array
    {
        return [
            'title.required'           => 'عنوان المستند مطلوب.',
            'title.string'             => 'عنوان المستند غير صالح.',
            'title.max'                => 'عنوان المستند لا يزيد على 255 حرفاً.',
            'description.string'       => 'وصف المستند غير صالح.',
            'description.max'          => 'وصف المستند لا يزيد على 2000 حرف.',
            'file.required_without'    => 'الملف مطلوب.',
            'file.file'                => self::FILE_MESSAGE,
            'file.max'                 => self::FILE_MESSAGE,
            'file.uploaded'            => self::FILE_MESSAGE,
            'template_id.integer'      => 'القالب المختار غير موجود أو غير مفعّل.',
            'template_id.prohibits'    => 'أرفق ملفاً أو اختر قالباً، لا كليهما.',
            'export_number.integer'    => self::EXPORT_RANGE_MESSAGE,
            'export_number.min'        => self::EXPORT_RANGE_MESSAGE,
            'export_number.max'        => self::EXPORT_RANGE_MESSAGE,
            'export_number.prohibited' => 'رقم الصادر للمستندات المنشأة من قالب فقط.',
        ];
    }

    protected function titleInput(): string
    {
        return trim((string) $this->input('title'));
    }

    protected function descriptionInput(): ?string
    {
        return $this->filled('description') ? trim((string) $this->input('description')) : null;
    }

    /** The template chosen, or null for an upload. */
    protected function templateIdInput(): ?int
    {
        return $this->filled('template_id') ? (int) $this->input('template_id') : null;
    }

    /** The file, for an upload only. */
    protected function fileInput(): ?UploadedFile
    {
        $file = $this->templateIdInput() === null ? $this->file('file') : null;

        return $file instanceof UploadedFile ? $file : null;
    }

    /**
     * The values as typed, for a template only; the rule above admits only an
     * object or none.
     *
     * @return array<string, mixed>
     */
    protected function fieldValuesInput(): array
    {
        $values = $this->input('field_values');

        return $this->templateIdInput() !== null && is_array($values) ? $values : [];
    }

    /** The typed صادر number, for a template only. */
    protected function exportNumberInput(): ?int
    {
        return $this->templateIdInput() !== null && $this->filled('export_number') ? (int) $this->input('export_number') : null;
    }
}
