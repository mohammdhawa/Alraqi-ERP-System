<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Modules\Approvals\Contracts\Approvable;
use App\Modules\Approvals\Contracts\RestrictsApprovalVisibility;
use App\Modules\Approvals\Enums\ApprovalStatus;
use App\Modules\Approvals\Enums\ApproverNotice;
use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Support\ApprovalSummary;
use App\Modules\Approvals\Support\ApproverMessage;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\NotificationService;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Enums\DocumentKind;
use App\Modules\Documents\Enums\DocumentOrigin;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Policies\DocumentPolicy;
use App\Modules\Documents\Services\UnitManagers;
use App\Modules\Documents\Services\UnitStamps;
use App\Modules\HR\Enums\EmployeeDocumentType;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocument;
use App\Shared\Models\Attachment;
use App\Shared\Support\NotificationEventCatalogue;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A document (documents design §6.1): a PDF that managers approve in order,
 * or (later pieces) one made from a template or sent to an employee. Its
 * files live on the private disk under `documents/{folder}/`.
 *
 * @property int $id
 * @property DocumentKind $kind
 * @property DocumentOrigin $origin
 * @property string $title
 * @property string|null $description
 * @property int $creator_id
 * @property int $creator_unit_id
 * @property int $register_unit_id
 * @property int|null $created_by
 * @property int|null $template_id
 * @property int|null $template_version
 * @property array<string, mixed>|null $field_values
 * @property int|null $export_number
 * @property int|null $import_number
 * @property string|null $reference
 * @property DocumentStatus $status
 * @property Carbon|null $decided_at
 * @property int|null $closed_by
 * @property string|null $closed_reason
 * @property string $folder
 * @property string|null $original_path
 * @property string|null $uploaded_path
 * @property string|null $printed_path
 * @property int|null $card_floor where the cards stop above the footer, in mm; null is piece 2's 6 mm
 * @property float|null $content_bottom where the original's last page's text ends, in mm from its top; null for an upload
 * @property int|null $recipient_id
 * @property EmployeeDocumentType|null $filing_type
 * @property Carbon|null $sent_at
 * @property Carbon|null $signed_at
 * @property Carbon|null $refused_at
 * @property string|null $refusal_reason
 * @property int|null $deleted_by
 */
#[UsePolicy(DocumentPolicy::class)]
class Document extends Model implements Approvable, RestrictsApprovalVisibility
{
    use HasAuditLog;
    use SoftDeletes;

    protected $fillable = [
        'kind',
        'origin',
        'title',
        'description',
        'creator_id',
        'creator_unit_id',
        'register_unit_id',
        'created_by',
        'template_id',
        'template_version',
        'field_values',
        'export_number',
        'import_number',
        'reference',
        'status',
        'recipient_id',
        'filing_type',
        'sent_at',
        'signed_at',
        'refused_at',
        'refusal_reason',
        'decided_at',
        'closed_by',
        'closed_reason',
        'folder',
        'original_path',
        'uploaded_path',
        'printed_path',
        'card_floor',
        'content_bottom',
        'deleted_by',
        'legacy_id',
        'legacy',
    ];

    protected function casts(): array
    {
        return [
            'kind'             => DocumentKind::class,
            'origin'           => DocumentOrigin::class,
            'status'           => DocumentStatus::class,
            'template_version' => 'integer',
            'export_number'    => 'integer',
            'card_floor'       => 'integer',
            'filing_type'      => EmployeeDocumentType::class,
            'content_bottom'   => 'float',
            'field_values'     => 'array',
            'legacy'       => 'array',
            'sent_at'      => 'datetime',
            'signed_at'    => 'datetime',
            'refused_at'   => 'datetime',
            'decided_at'   => 'datetime',
        ];
    }

    /**
     * The lists' search: the title or reference in part; digits are also the
     * وارد or the صادر number, exactly (§7.7, §8.4).
     *
     * @param  Builder<Document>  $query
     */
    public function scopeMatching(Builder $query, string $search): void
    {
        $query->where(static function (Builder $match) use ($search): void {
            // `!` escapes the wildcards, on every database alike.
            $like = '%'.strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';

            $match->whereRaw("documents.title like ? escape '!'", [$like])
                ->orWhereRaw("documents.reference like ? escape '!'", [$like]);

            if (ctype_digit($search)) {
                $match->orWhere('documents.import_number', (int) $search)
                    ->orWhere('documents.export_number', (int) $search);
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'creator_id')->withTrashed();
    }

    public function creatorUnit(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'creator_unit_id')->withTrashed();
    }

    public function registerUnit(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'register_unit_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The template it was made from; a deleted template still shows. */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id')->withTrashed();
    }

    /** The employee a sent document is addressed to; a deleted one still shows. */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recipient_id')->withTrashed();
    }

    /** Whether the account is the employee a sent document is addressed to: the one rule, kept in one place. */
    public function isRecipient(User $user): bool
    {
        return $user->employee_id !== null
            && $this->kind === DocumentKind::ToEmployee
            && $this->recipient_id !== null
            && (int) $user->employee_id === (int) $this->recipient_id;
    }

    /** The row it files on the recipient's record once answered. */
    public function filedRecord(): HasOne
    {
        return $this->hasOne(EmployeeDocument::class);
    }

    public function approval(): MorphOne
    {
        return $this->morphOne(Approval::class, 'approvable');
    }

    public function marks(): HasMany
    {
        return $this->hasMany(DocumentMark::class)->orderBy('position');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(DocumentComment::class)->orderBy('id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('id');
    }

    // ── Approvable (documents design §7.4) ───────────────────────────────

    /** HR's `approvals.view` does not open a document's chain; `documents.view` does. */
    public static function mayReadApprovalsWithoutBeingParty(User $user): bool
    {
        return $user->hasPermission('documents.view');
    }

    public function approvalRequester(): Employee
    {
        return $this->creator;
    }

    /** The checks of a decision are the decision endpoint's (§7.2). */
    public function canBeApproved(): ?string
    {
        return null;
    }

    /**
     * The document mirrors its approval's outcome and its creator is told in
     * legacy's words. A withdrawal through the general `/withdraw` is only
     * possible before any decision, so it is a deletion (§7.6). The other
     * cancellation is the administrator's closing (§7.8): `DocumentClosing`
     * has already recorded it «لم يُستكمل» and told the creator, and the
     * document stays on record.
     */
    public function onApprovalDecided(Approval $approval): void
    {
        if ($approval->status === ApprovalStatus::Cancelled) {
            if ($this->status === DocumentStatus::NotCompleted) {
                return;
            }

            $this->update(['deleted_by' => auth()->id() ?? $this->creatorAccountId()]);
            $this->delete();

            return;
        }

        [$status, $type, $title] = match ($approval->status) {
            ApprovalStatus::Approved => [DocumentStatus::Approved, NotificationEventCatalogue::DOCUMENT_APPROVED, 'تمت الموافقة على مستندك'],
            ApprovalStatus::Rejected => [DocumentStatus::Rejected, NotificationEventCatalogue::DOCUMENT_REJECTED, 'تم رفض مستندك'],
            default                  => [DocumentStatus::Pending, null, ''],
        };

        $this->update(['status' => $status, 'decided_at' => $approval->decided_at]);

        $account = $this->creatorAccountId();

        if ($type !== null && $account !== null) {
            app(NotificationService::class)->sendToUser($account, $title, $this->title, type: $type, reference: $this);
        }
    }

    public function describeForApprover(ApproverNotice $notice): ApproverMessage
    {
        $review = "يرجى مراجعة: {$this->title}";

        return match ($notice) {
            ApproverNotice::Submitted       => new ApproverMessage('مستند جديد بانتظار موافقتك', $review),
            ApproverNotice::YourTurn        => $this->currentStepIsDecisive()
                ? new ApproverMessage('جميع المدراء وافقوا — قرارك مطلوب', $review)
                : new ApproverMessage('دورك للمراجعة', $review),
            ApproverNotice::ReassignedNow   => new ApproverMessage('تم إسنادك لاعتماد مستند', "المستند «{$this->title}» بانتظار مراجعتك الآن."),
            ApproverNotice::ReassignedAhead => new ApproverMessage('تم إسنادك لاعتماد مستند', "أصبحت ضمن مسار اعتماد المستند «{$this->title}»."),
        };
    }

    public function approvalSummary(): ApprovalSummary
    {
        return new ApprovalSummary($this->title, "{$this->creator->full_name} · {$this->creatorUnit->name}");
    }

    /**
     * @return array<int, string>
     */
    public static function approvalSummaryRelations(): array
    {
        return ['creator', 'creatorUnit'];
    }

    /**
     * Whether the viewer's own unit has no stamp, so the manager knows before
     * tapping that a signature would be refused. The viewer is the
     * authenticated account: the contract passes none.
     */
    public static function approverRowDetails(Collection $subjects, bool $decided): array
    {
        $missing = ! $decided && self::viewerUnitLacksAStamp();

        return $subjects->mapWithKeys(static fn (self $document): array => [
            $document->id => [
                'stamp_missing'         => $missing,
                'document_status'       => $document->status->value,
                'document_status_label' => $document->status->label(),
            ],
        ])->all();
    }

    private static function viewerUnitLacksAStamp(): bool
    {
        $employeeId = auth()->user()?->employee_id;
        $employee = $employeeId === null ? null : Employee::query()->find($employeeId);
        $unit = $employee === null ? null : app(UnitManagers::class)->unitOf($employee);

        return $unit !== null && app(UnitStamps::class)->current($unit) === null;
    }

    private function creatorAccountId(): ?int
    {
        $id = User::query()->where('employee_id', $this->creator_id)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Whether the step now asked is the decisive one. Read from the database,
     * not the loaded relation: the engine asks while it moves the approval on.
     */
    private function currentStepIsDecisive(): bool
    {
        $approval = $this->approval()->first();
        $step = $approval?->steps()->where('status', StepStatus::Pending)->first();

        return $step !== null && $step->decisive;
    }
}
