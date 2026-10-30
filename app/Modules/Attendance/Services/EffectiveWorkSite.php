<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\EmployeeWorkSiteAssignment;
use App\Modules\Attendance\Models\WorkSite;
use App\Modules\Attendance\Models\WorkSiteVersion;
use Carbon\CarbonInterface;

final readonly class EffectiveWorkSite
{
    public function __construct(
        public EmployeeWorkSiteAssignment $assignment,
        public WorkSite $site,
        public ?WorkSiteVersion $version,
    ) {}

    public function isActiveAt(CarbonInterface $at): bool
    {
        return $this->version !== null && ($this->site->archived_at === null || $at->lt($this->site->archived_at));
    }
}
