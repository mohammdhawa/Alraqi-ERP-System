<?php

declare(strict_types=1);

namespace App\Modules\Documents\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\Documents\Enums\FieldType;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Pdf\PdfSession;
use App\Modules\Documents\Pdf\PdfWorkspace;
use App\Modules\Documents\Pdf\TemplatePrinter;
use App\Modules\Documents\Requests\PreviewTemplateRequest;
use App\Modules\Documents\Requests\SaveTemplateRequest;
use App\Modules\Documents\Requests\TemplatesIndexRequest;
use App\Modules\Documents\Resources\DocumentTemplateResource;
use App\Modules\Documents\Services\UnitManagers;
use App\Modules\Documents\Support\RecipientValues;
use App\Modules\Documents\Support\SystemValues;
use App\Modules\Documents\Templates\TemplateFields;
use App\Shared\Traits\ApiRespond;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use RuntimeException;

/**
 * Document templates (documents design §6, §16). Reading is for any signed-in
 * account (a picker's fields, active templates); writing, previewing, the
 * page and the inactive ones are behind `documents.templates.manage`.
 */
class DocumentTemplateController extends Controller
{
    use ApiRespond;

    /** GET /api/documents/templates */
    public function index(TemplatesIndexRequest $request): JsonResponse
    {
        $manages = $this->manages($request);
        $status  = $manages ? $request->status() : 'active';

        $templates = DocumentTemplate::query();

        match ($status) {
            'active'   => $templates->where('is_active', true),
            'inactive' => $templates->where('is_active', false),
            default    => null,
        };

        if (($categoryId = $request->categoryId()) !== null) {
            $templates->where('category_id', $categoryId);
        }

        if (($forEmployees = $request->forEmployees()) !== null) {
            $templates->where('for_employees', $forEmployees);
        }

        if (($search = $request->search()) !== null) {
            // `!` escapes the wildcards, on every database alike.
            $templates->whereRaw("document_templates.name like ? escape '!'", ['%'.strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%']);
        }

        $page = $templates
            ->with(DocumentTemplateResource::RELATIONS)
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($this->perPage($request));

        if ($manages) {
            $used = Document::withTrashed()
                ->whereIn('template_id', $page->getCollection()->pluck('id'))
                ->distinct()
                ->pluck('template_id')
                ->all();

            $page->getCollection()->each(
                static fn (DocumentTemplate $t) => $t->setAttribute('in_use', in_array($t->id, $used, true)),
            );
        }

        return $this->paginated(DocumentTemplateResource::collection($page));
    }

    /** GET /api/documents/templates/{template} */
    public function show(Request $request, DocumentTemplate $template): JsonResponse
    {
        abort_unless($template->is_active || $this->manages($request), 404);

        return $this->success($this->present($request, $template));
    }

    /** POST /api/documents/templates */
    public function store(SaveTemplateRequest $request): JsonResponse
    {
        $template = DocumentTemplate::create($request->templateData() + ['version' => 1]);

        return $this->created($this->present($request, $template), 'تم إنشاء القالب.');
    }

    /** PUT /api/documents/templates/{template} — the version rises on every update. */
    public function update(SaveTemplateRequest $request, DocumentTemplate $template): JsonResponse
    {
        $template->update($request->templateData() + ['version' => $template->version + 1]);

        return $this->success($this->present($request, $template), 'تم تحديث القالب.');
    }

    /**
     * POST /api/documents/templates/preview — the page being edited, printed
     * with sample values (documents design §8.2). Nothing is saved: an unsaved
     * template is filled from the request, and the print's folder goes when
     * it ends.
     */
    public function preview(PreviewTemplateRequest $request, PdfWorkspace $workspace, TemplatePrinter $printer, UnitManagers $managers): Response
    {
        $template = new DocumentTemplate($request->templateData());
        $values = $this->sampleValues(TemplateFields::fromArray($template->fields));
        $system = $this->sampleSystem($request->user(), $template, $managers);
        $recipient = $template->for_employees ? new RecipientValues('اسم الموظف', 'المسمى الوظيفي', 'الوحدة', '0000') : null;

        $pdf = $workspace->run(static function (PdfSession $session) use ($printer, $template, $values, $system, $recipient): string {
            $bytes = file_get_contents($printer->print($template, $values, $system, $recipient, $session)->path);

            if ($bytes === false) {
                throw new RuntimeException('The preview could not be read.');
            }

            return $bytes;
        });

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="preview.pdf"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    /** DELETE /api/documents/templates/{template} — a used template stays. */
    public function destroy(DocumentTemplate $template): JsonResponse
    {
        if ($template->isInUse()) {
            return $this->error('استُخدم هذا القالب في مستندات، فلا يُحذف. يمكنك إيقافه بدلاً من ذلك.', 422);
        }

        $template->delete();

        return $this->success(message: 'تم حذف القالب.');
    }

    /**
     * One sample per field, by its type.
     *
     * @return array<string, mixed>
     */
    private function sampleValues(TemplateFields $fields): array
    {
        $values = [];

        foreach ($fields->all() as $field) {
            $values[(string) $field['key']] = match (FieldType::from((string) $field['type'])) {
                FieldType::Text     => 'نص تجريبي',
                FieldType::Textarea => "سطر أول\nسطر ثانٍ",
                FieldType::Number   => '1250',
                FieldType::Date     => CarbonImmutable::now()->format('Y-m-d'),
                FieldType::Select   => (string) ($field['options'][0] ?? ''),
                FieldType::Table    => array_fill(0, 2, array_fill_keys(
                    array_map(static fn (array $column): string => (string) $column['key'], $field['columns'] ?? []),
                    'قيمة',
                )),
            };
        }

        return $values;
    }

    /** Sample numbers and today; the caller as the creator when they are an employee. */
    private function sampleSystem(User $user, DocumentTemplate $template, UnitManagers $managers): SystemValues
    {
        $now = CarbonImmutable::now();
        $employee = $user->employee;
        $name = trim((string) $employee?->full_name);
        $unit = $employee === null ? null : ($managers->unitOf($employee) ?? $employee->department)?->name;

        return new SystemValues(
            exportNumber: 123,
            importNumber: 45,
            reference: sprintf('%s-%d-%04d', $template->reference_prefix, $now->year, 1),
            date: $now,
            creatorName: $name !== '' ? $name : 'اسم المنشئ',
            creatorUnit: is_string($unit) && trim($unit) !== '' ? $unit : 'اسم الوحدة',
        );
    }

    private function manages(Request $request): bool
    {
        return (bool) $request->user()?->hasPermission(DocumentTemplateResource::MANAGE);
    }

    /** @return array<string, mixed> */
    private function present(Request $request, DocumentTemplate $template): array
    {
        return DocumentTemplateResource::make($template->load(DocumentTemplateResource::RELATIONS))->resolve($request);
    }
}
