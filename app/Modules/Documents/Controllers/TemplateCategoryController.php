<?php

declare(strict_types=1);

namespace App\Modules\Documents\Controllers;

use App\Modules\Documents\Models\DocumentTemplateCategory;
use App\Modules\Documents\Requests\SaveTemplateCategoryRequest;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Template categories (documents design §6, §16). Reading is for any signed-in
 * account; writing is behind `permission:documents.templates.manage`.
 */
class TemplateCategoryController extends Controller
{
    use ApiRespond;

    /**
     * GET /api/documents/template-categories — every live category with the
     * number of its active templates.
     */
    public function index(): JsonResponse
    {
        $categories = DocumentTemplateCategory::query()
            ->withCount(['templates as templates_count' => static fn ($templates) => $templates->where('is_active', true)])
            ->orderBy('sort')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return $this->success($categories->map(fn (DocumentTemplateCategory $c) => $this->present($c))->all());
    }

    /** POST /api/documents/template-categories */
    public function store(SaveTemplateCategoryRequest $request): JsonResponse
    {
        $category = DocumentTemplateCategory::create($request->categoryData());

        return $this->created($this->present($category->loadCount('templates')), 'تم إنشاء التصنيف.');
    }

    /** PUT /api/documents/template-categories/{category} */
    public function update(SaveTemplateCategoryRequest $request, DocumentTemplateCategory $category): JsonResponse
    {
        $category->update($request->categoryData());

        return $this->success($this->present($category), 'تم تحديث التصنيف.');
    }

    /**
     * DELETE /api/documents/template-categories/{category} — refused while a
     * template, active or not, is filed under it.
     */
    public function destroy(DocumentTemplateCategory $category): JsonResponse
    {
        if ($category->templates()->exists()) {
            return $this->error('لا يُحذف تصنيف فيه قوالب.', 422);
        }

        $category->delete();

        return $this->success(message: 'تم حذف التصنيف.');
    }

    /** @return array<string, mixed> */
    private function present(DocumentTemplateCategory $category): array
    {
        return [
            'id'              => $category->id,
            'name'            => $category->name,
            'sort'            => $category->sort,
            'templates_count' => (int) ($category->getAttribute('templates_count') ?? $category->templates()->where('is_active', true)->count()),
        ];
    }
}
