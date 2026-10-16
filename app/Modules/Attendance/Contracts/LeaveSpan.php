<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Contracts;

/**
 * One approved leave — a request that went through approval, or an excuse HR
 * filed directly — as attendance's reports need to see it: whose, from when to
 * when, whether it is an excuse and whether it costs balance, and the details
 * the daily board and the day grid show beside it.
 *
 * Built by whoever implements ApprovedLeave; attendance only reads it.
 */
final readonly class LeaveSpan
{
    public function __construct(
        public int $leaveRequestId,
        public int $employeeId,
        public string $startDate,   // Y-m-d, inclusive
        public string $endDate,     // Y-m-d, inclusive
        public bool $isExcuse,
        public bool $deductsBalance,
        public ?string $typeCode = null,
        public ?string $typeName = null,
        public ?string $reason = null,
        public ?string $recordedBy = null,   // who filed an excuse
        public ?string $approvedBy = null,   // who approved a request
    ) {}

    public function covers(string $date): bool
    {
        return $this->startDate <= $date && $date <= $this->endDate;
    }

    /**
     * The justification block a report shows beside an excused or on-leave
     * day — legacy's leaveDetails(), so the board and the grid describe an
     * excuse identically.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return [
            'leave_request_id' => $this->leaveRequestId,
            'leave_type'       => $this->typeCode,
            'leave_type_name'  => $this->typeName,
            'is_excuse'        => $this->isExcuse,
            'deducts_balance'  => $this->deductsBalance,
            'reason'           => $this->reason,
            'recorded_by'      => $this->recordedBy,
        ];
    }
}
