<?php

declare(strict_types=1);

namespace App\Modules\Leave\Models;

use App\Modules\Approvals\Contracts\Approvable;
use App\Modules\Approvals\Enums\ApprovalStatus;
use App\Modules\Approvals\Enums\ApproverNotice;
use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Support\ApprovalSummary;
use App\Modules\Approvals\Support\ApproverMessage;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\NotificationService;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveOrigin;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Policies\LeaveRequestPolicy;
use App\Modules\Leave\Services\LeaveBalances;
use App\Shared\Models\Attachment;
use App\Shared\Support\NotificationEventCatalogue;
use App\Shared\Traits\HasAuditLog;
use Database\Factories\LeaveRequestFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A leave request or an HR excuse (LEAVE_BUILD.md §4) — legacy's LeaveRequest
 * on employees.
 *
 * The scopes are the vocabulary every rule shares, as legacy's: the balance
 * sums approved deducting rows, the overlap guard reads blocking ones.
 *
 * THE ENGINE'S SUBJECT. An employee's request is approved by the approvals
 * engine on LeaveApprovalRoute; the methods under "Approvable" are leave's
 * answers to it, in legacy's words. `onApprovalDecided()` is the only writer
 * of a request's outcome. An HR excuse has no approval.
 *
 * @property LeaveOrigin $origin
 * @property LeaveStatus $status
 * @property bool $deducts_balance
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property Carbon|null $decided_at
 */
#[UsePolicy(LeaveRequestPolicy::class)]
class LeaveRequest extends Model implements Approvable
{
    use HasAuditLog;

    /** @use HasFactory<LeaveRequestFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'origin',
        'deducts_balance',
        'reason',
        'start_date',
        'end_date',
        'requested_days',
        'status',
        'decided_at',
        'created_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'returned_to_work_on',
        'original_end_date',
        'shortened_at',
        'shortened_by',
        'shortening_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin'              => LeaveOrigin::class,
            'status'              => LeaveStatus::class,
            'deducts_balance'     => 'boolean',
            'start_date'          => 'date',
            'end_date'            => 'date',
            'requested_days'      => 'integer',
            'decided_at'          => 'datetime',
            'cancelled_at'        => 'datetime',
            'returned_to_work_on' => 'date',
            'original_end_date'   => 'date',
            'shortened_at'        => 'datetime',
        ];
    }

    /**
     * The factory lives in database/factories, not beside the model.
     */
    protected static function newFactory(): Factory
    {
        return LeaveRequestFactory::new();
    }

    /**
     * Whose leave — trashed employees included: a departed person's leave is
     * still their history.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /** The type — a retired one included: the row keeps what it was filed under. */
    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function shortenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shortened_by');
    }

    /** The request's approval; null for an HR excuse. */
    public function approval(): MorphOne
    {
        return $this->morphOne(Approval::class, 'approvable');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('id');
    }

    public function isExcuse(): bool
    {
        return $this->origin === LeaveOrigin::HrExcuse;
    }

    // ── Approvable ───────────────────────────────────────────────────────

    public function approvalRequester(): Employee
    {
        return $this->employee;
    }

    /**
     * Legacy's re-check at the final approval: a deducting request still fits
     * what is left of the balance. Pending requests reserve nothing, so this
     * is what stops two of them from overdrawing it together.
     */
    public function canBeApproved(): ?string
    {
        if (! $this->deducts_balance) {
            return null;
        }

        // The employee's lock first, as legacy's review() took it: two final
        // approvals of one person's requests — or one racing an excuse or a
        // balance cut, which lock the same row — then read the balance one at
        // a time. Inside the engine's transaction, after the approval's own
        // locks; nothing locks the employee and then an approval, so the
        // order cannot deadlock.
        Employee::query()->whereKey($this->employee_id)->lockForUpdate()->first();

        $remaining = app(LeaveBalances::class)->remaining((int) $this->employee_id, $this->start_date->year);

        return $this->requested_days > $remaining ? 'طلب الإجازة يتجاوز رصيد الإجازات المتبقي للموظف.' : null;
    }

    /**
     * The request mirrors its approval's outcome, and the employee is told an
     * approval or a rejection in legacy's words. A withdrawal was their own
     * act: nothing to tell.
     */
    public function onApprovalDecided(Approval $approval): void
    {
        $this->update([
            'status' => match ($approval->status) {
                ApprovalStatus::Approved  => LeaveStatus::Approved,
                ApprovalStatus::Rejected  => LeaveStatus::Rejected,
                ApprovalStatus::Cancelled => LeaveStatus::Cancelled,
                ApprovalStatus::Pending   => LeaveStatus::Pending,
            },
            'decided_at' => $approval->decided_at,
        ]);

        $span = "من {$this->start_date->toDateString()} إلى {$this->end_date->toDateString()}";

        [$type, $title, $body] = match ($approval->status) {
            ApprovalStatus::Approved => [NotificationEventCatalogue::LEAVE_APPROVED, 'تمت الموافقة على طلب الإجازة', "تمت الموافقة على طلب إجازتك {$span}."],
            ApprovalStatus::Rejected => [NotificationEventCatalogue::LEAVE_REJECTED, 'تم رفض طلب الإجازة', "تم رفض طلب إجازتك {$span}."],
            default                  => [null, '', null],
        };

        $account = User::query()->where('employee_id', $this->employee_id)->value('id');

        if ($type !== null && $account !== null) {
            app(NotificationService::class)->sendToUser((int) $account, $title, $body, type: $type, reference: $this);
        }
    }

    public function describeForApprover(ApproverNotice $notice): ApproverMessage
    {
        $name = $this->employee->full_name;

        return match ($notice) {
            ApproverNotice::Submitted       => new ApproverMessage('طلب إجازة جديد', "{$name} قدّم طلب إجازة."),
            ApproverNotice::YourTurn        => new ApproverMessage('دورك لمراجعة طلب إجازة', "يرجى مراجعة طلب إجازة {$name}."),
            ApproverNotice::ReassignedNow   => new ApproverMessage('تم إسنادك لاعتماد طلب إجازة', "طلب إجازة {$name} بانتظار مراجعتك الآن."),
            ApproverNotice::ReassignedAhead => new ApproverMessage('تم إسنادك لاعتماد طلب إجازة', "أصبحت ضمن مسار اعتماد طلب إجازة {$name}."),
        };
    }

    public function approvalSummary(): ApprovalSummary
    {
        return new ApprovalSummary(
            $this->type->name_ar,
            "{$this->requested_days} أيام · {$this->start_date->toDateString()} → {$this->end_date->toDateString()}",
        );
    }

    /**
     * @return array<int, string>
     */
    public static function approvalSummaryRelations(): array
    {
        return ['type', 'employee'];
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    /**
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', LeaveStatus::Approved);
    }

    /**
     * Pending or approved — what a new leave over the same days collides with.
     * A rejected or cancelled one blocks nothing.
     *
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', [LeaveStatus::Pending, LeaveStatus::Approved]);
    }

    /**
     * By the snapshot taken at filing, never by the type as it is now.
     *
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    public function scopeDeducting(Builder $query, bool $deducts = true): Builder
    {
        return $query->where('deducts_balance', $deducts);
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    public function scopeExcuses(Builder $query, bool $isExcuse = true): Builder
    {
        return $query->where('origin', $isExcuse ? LeaveOrigin::HrExcuse : LeaveOrigin::Request);
    }

    /**
     * Leave whose span shares at least one day with [$from, $to] (Y-m-d,
     * inclusive) — legacy's overlapping().
     *
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    public function scopeOverlapping(Builder $query, string $from, string $to): Builder
    {
        return $query->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from);
    }

    /**
     * Leave charged to $year: legacy charges a leave wholly to the year it
     * STARTS in, so one from 30 December to 3 January is all last year's.
     *
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    public function scopeStartingInYear(Builder $query, int $year): Builder
    {
        return $query->whereYear('start_date', $year);
    }
}
