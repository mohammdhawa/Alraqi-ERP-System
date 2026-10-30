<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkSiteVersion extends Model
{
    use HasAuditLog;

    protected $fillable = ['work_site_id', 'name', 'latitude', 'longitude', 'radius_m', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'radius_m' => 'float',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(WorkSite::class, 'work_site_id');
    }
}
