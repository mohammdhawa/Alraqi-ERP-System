<?php

declare(strict_types=1);

namespace App\Modules\Documents\Resources;

use App\Modules\Documents\Models\DocumentTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A template. The page and what is printed from it are for those who manage
 * templates; the rest see what a picker needs.
 *
 * @mixin DocumentTemplate
 */
class DocumentTemplateResource extends JsonResource
{
    public const MANAGE = 'documents.templates.manage';

    public const RELATIONS = ['category:id,name'];

    public function toArray(Request $request): array
    {
        $data = [
            'id'            => $this->id,
            'name'          => $this->name,
            'description'   => $this->description,
            'category'      => $this->category === null ? null : ['id' => $this->category->id, 'name' => $this->category->name],
            'is_active'     => $this->is_active,
            'for_employees' => $this->for_employees,
            'version'       => $this->version,
            'fields'        => $this->fields,
        ];

        if ($request->user()?->hasPermission(self::MANAGE)) {
            $data += [
                'body'             => $this->body,
                'layout_key'       => $this->layout_key,
                'show_letterhead'  => $this->show_letterhead,
                'reference_prefix' => $this->reference_prefix,
                // The list reads this in one query; one template asks for itself.
                'in_use' => $this->resource->getAttribute('in_use') ?? $this->resource->isInUse(),
            ];
        }

        return $data;
    }
}
