<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkSite extends Model
{
    use HasAuditLog;

    protected $fillable = ['name', 'archived_at'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WorkSiteVersion::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeWorkSiteAssignment::class);
    }
}
