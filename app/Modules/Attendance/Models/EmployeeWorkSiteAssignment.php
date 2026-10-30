<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Modules\HR\Models\Employee;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeWorkSiteAssignment extends Model
{
    use HasAuditLog;

    protected $fillable = ['employee_id', 'work_site_id', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return ['effective_from' => 'datetime', 'effective_to' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(WorkSite::class, 'work_site_id');
    }
}
