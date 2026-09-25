<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Enums\ActivityType;
use App\Modules\HR\Enums\AssignmentReason;
use App\Modules\HR\Enums\EducationLevel;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\EmploymentType;
use App\Modules\HR\Enums\EngineeringRank;
use App\Modules\HR\Enums\Gender;
use App\Modules\HR\Enums\Governorate;
use App\Modules\HR\Enums\MaritalStatus;
use App\Modules\HR\Enums\SalaryMode;
use App\Modules\HR\Exceptions\EmployeeClassificationException;
use App\Modules\HR\Policies\EmployeePolicy;
use App\Modules\HR\Support\EmployeeClassificationGuard;
use App\Shared\Traits\HasAuditLog;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Employee Model
 *
 * Every person who works at the company, whether or not they ever log in.
 * `users` holds only those with system access, and `User belongsTo Employee`
 * through a nullable-unique `users.employee_id`: every user is an employee, not
 * every employee is a user.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * WHAT THIS ROW HOLDS, AND WHAT IT DELIBERATELY DOES NOT
 * ───────────────────────────────────────────────────────────────────────────
 * It holds the person (identity, contact, family, education), their current
 * posting as a denormalised pointer, and the CRITERIA their pay is computed
 * from. It does not hold their pay.
 *
 * There is no `salary` column. For the large majority — `salary_mode = scale` —
 * the number is not a stored fact but the output of the criteria calculation,
 * and caching a derived value with no invalidation strategy means one change to
 * the company scale factor silently makes every row wrong (P1/C25). Only the
 * إتفاق track has a real negotiated amount, in `agreed_salary`, and
 * EmployeeClassificationGuard refuses to let the two tracks blur.
 *
 * There is no `seniority_years` either: it is `hire_date` and the payroll
 * period. Storing it needs a nightly job to keep hundreds of rows honest, and
 * the day that job fails every payslip is wrong.
 *
 * ⚠️ `organizational_grade` (السوية التنظيمية) IS NOT `departments.level`.
 * Both are integers 1–5; they are different facts. A section head (السوية 3)
 * can sit in a division (level 2) row. Conflating them corrupts every payslip,
 * and nothing complains.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * INVARIANTS — enforced here, where every writer crosses (P2, C4)
 * ───────────────────────────────────────────────────────────────────────────
 * `saving`  runs EmployeeClassificationGuard: axis exclusivity, the pay-track
 *           pairing, grade ranges, the Sham Cash shape, family coherence, date
 *           order. EmployeeRequest runs the SAME guard for a friendly 422; this
 *           hook is what binds the Batch 4 importer, the seeders and tinker.
 *
 * `creating` assigns `employee_number` when the writer omits one.
 *
 * `saved`   keeps the posting history in step with the pointer columns — see
 *           `recordPostingChange()` and the EmployeeAssignment docblock, which
 *           together describe the two-way synchronisation and how its recursion
 *           is broken.
 *
 * WHY SoftDeletes: a person is FK'd to by `users.employee_id`,
 * `departments.manager_id`, their own postings, warnings and documents, and
 * every future module. Their identity must stay resolvable after they leave.
 *
 * ROW-LEVEL AUTHORIZATION (GAP-3) is `EmployeePolicy`, registered by the
 * attribute below so the module needs no provider edit.
 */
#[UsePolicy(EmployeePolicy::class)]
class Employee extends Model
{
    use HasAuditLog;

    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * The prefix and width of a generated staff number.
     */
    private const NUMBER_PREFIX = 'EMP-';

    private const NUMBER_WIDTH = 5;

    /**
     * The manager `ManagementChain::attachTo()` resolved from the tree.
     *
     * A PLAIN PROPERTY, NOT A RELATION. It used to be set with setRelation(),
     * but there is no relation method behind it, and `refresh()` reloads every
     * key in `$relations` by name — so refreshing an employee that had been
     * through the walk threw RelationNotFoundException. Kept out of the
     * attribute bag too, so it can never be saved.
     */
    private ?Employee $managerFromTree = null;

    /**
     * Defaults that mirror the column defaults in the migration.
     *
     * These are NOT decoration and NOT a second copy of the schema's defaults
     * in any harmful sense — they exist because a database default is applied
     * by the INSERT, which is far too late for the `saving` hook. Without them,
     * `Employee::create(['agreed_salary' => 500])` reaches
     * EmployeeClassificationGuard with no `salary_mode` at all, the pay-track
     * rule finds nothing to check, and the row lands as نظام رواتب carrying a
     * stored salary — exactly the state that rule exists to forbid.
     *
     * Stated once here so the guard always sees the row as it will actually be.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status'            => 1,   // نشط
        'salary_mode'       => 1,   // نظام رواتب
        'nationality'       => 'سوري',
        'wives_count'       => 0,
        'sons_count'        => 0,
        'daughters_count'   => 0,
        'has_hazard_pay'    => false,
        'has_scarcity_pay'  => false,
        'has_dual_role_pay' => false,
    ];

    /**
     * Point Eloquent at the factory explicitly.
     *
     * Required because this model lives in a module rather than under
     * App\Models, so Laravel's convention-based discovery would look for
     * Database\Factories\Modules\HR\Models\EmployeeFactory and find nothing.
     * The same reason User declares one.
     */
    protected static function newFactory(): Factory
    {
        return EmployeeFactory::new();
    }

    protected $fillable = [
        // Identity
        'employee_number',
        'first_name',
        'middle_name',
        'last_name',
        'mother_name',
        'national_id',
        'gender',
        'birth_date',
        'birth_place',
        'origin_governorate',
        'residence_governorate',
        'nationality',
        'second_nationality',
        'marital_status',
        'wives_count',
        'sons_count',
        'daughters_count',
        // Contact
        'phone',
        'phone_secondary',
        'email',
        'address',
        // Military service
        'did_military_service',
        'military_discharge_date',
        // Education
        'education_level',
        'qualification',
        'specialization',
        'is_graduated',
        'graduation_date',
        // Classification — pay-determining
        'activity_type',
        'organizational_grade',
        'experience_level',
        'engineering_rank',
        'industrial_experience_years',
        'has_hazard_pay',
        'has_scarcity_pay',
        'has_dual_role_pay',
        'distinction_grade',
        // Employment
        'department_id',
        'job_title_id',
        'employment_type',
        'workplace',
        'hire_date',
        'termination_date',
        'status',
        // Pay reference
        'sham_cash_account',
        'salary_mode',
        'agreed_salary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date'                  => 'date',
            'military_discharge_date'     => 'date',
            'graduation_date'             => 'date',
            'hire_date'                   => 'date',
            'termination_date'            => 'date',
            'gender'                      => Gender::class,
            'marital_status'              => MaritalStatus::class,
            'origin_governorate'          => Governorate::class,
            'residence_governorate'       => Governorate::class,
            'education_level'             => EducationLevel::class,
            'activity_type'               => ActivityType::class,
            'engineering_rank'            => EngineeringRank::class,
            'employment_type'             => EmploymentType::class,
            'status'                      => EmployeeStatus::class,
            'salary_mode'                 => SalaryMode::class,
            'is_graduated'                => 'boolean',
            'did_military_service'        => 'boolean',
            'has_hazard_pay'              => 'boolean',
            'has_scarcity_pay'            => 'boolean',
            'has_dual_role_pay'           => 'boolean',
            'wives_count'                 => 'integer',
            'sons_count'                  => 'integer',
            'daughters_count'             => 'integer',
            'organizational_grade'        => 'integer',
            'experience_level'            => 'integer',
            'industrial_experience_years' => 'integer',
            'distinction_grade'           => 'integer',
            'agreed_salary'               => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $employee): void {
            if (blank($employee->employee_number)) {
                $employee->employee_number = self::nextEmployeeNumber();
            }
        });

        static::saving(function (self $employee): void {
            // Raw attributes, so the guard judges what this row would STORE.
            $violations = (new EmployeeClassificationGuard)->violations($employee->getAttributes());

            if ($violations !== []) {
                throw new EmployeeClassificationException($violations[0]->message);
            }
        });

        static::saved(function (self $employee): void {
            $employee->recordPostingChange();
        });
    }

    /**
     * The next free staff number, e.g. "EMP-00001".
     *
     * ⚙️ A SUPPLIED NUMBER IS ALWAYS RESPECTED (project owner, 2026-09-13): HR
     * may type a staff number by hand — to continue the company's own M024333
     * series, or to correct one — and only uniqueness is enforced, by the index
     * and by EmployeeRequest.
     *
     * That decision is what makes the loop below necessary rather than
     * decorative. The generator derives its candidate from `max(id)`, so the
     * moment someone manually assigns "EMP-00006" to employee #3, the row that
     * eventually lands at id 6 would generate a number that is already taken and
     * the insert would fail with a raw integrity error. The loop steps past any
     * number already in use — including soft-deleted rows, whose numbers are
     * never reissued, because a departed employee's identifier must keep meaning
     * what it meant in the audit trail.
     *
     * The UNIQUE index remains the final backstop for a concurrent collision:
     * the loser's insert fails rather than duplicating a number.
     */
    private static function nextEmployeeNumber(): string
    {
        $candidate = (int) (self::withTrashed()->max('id') ?? 0) + 1;

        while (self::withTrashed()->where('employee_number', self::formatNumber($candidate))->exists()) {
            $candidate++;
        }

        return self::formatNumber($candidate);
    }

    private static function formatNumber(int $sequence): string
    {
        return self::NUMBER_PREFIX.str_pad((string) $sequence, self::NUMBER_WIDTH, '0', STR_PAD_LEFT);
    }

    /**
     * Keep the posting history in step with the pointer columns.
     *
     * This is the employee -> assignment half of the synchronisation pair
     * described in EmployeeAssignment's docblock. It fires only when one of the
     * three mirrored columns actually changed, so an ordinary edit to a phone
     * number writes no history.
     *
     * Three cases, and the third is the one worth reading twice:
     *
     *   no open posting      -> open one, reason تعيين, dated from `hire_date`.
     *                           This is the row that replaces the workbook's
     *                           three frozen `بداية التعيين` columns, and it is
     *                           created automatically so the Batch 4 importer
     *                           gets it by writing the employee alone.
     *
     *   open posting that    -> nothing but an amendment in place. Keeps the
     *   has not yet elapsed     hook idempotent, which is what lets the
     *                           assignment hook write back without a ping-pong,
     *                           and what makes a same-day correction a
     *                           correction rather than a transfer with a
     *                           zero-day posting in front of it. "Not yet
     *                           elapsed" rather than "started today" so that a
     *                           future `hire_date` — which EmployeeRequest
     *                           permits — is amended too, instead of being
     *                           closed on a date before it began and refused by
     *                           EmployeeAssignment's own date guard.
     *
     *   open posting, older  -> close it YESTERDAY and open a new one today. A
     *                           change of unit, title or grade IS a transfer,
     *                           and the previous posting stays readable with the
     *                           dates it really had.
     *
     * ⚠️ THE OUTGOING POSTING CLOSES THE DAY BEFORE THE NEW ONE OPENS, exactly
     * as EmployeeAssignmentService::record() does it, and for the same reason.
     * `scopeOnDate()` matches `from_date <= D AND (to_date IS NULL OR
     * to_date >= D)`, so closing the old row on the new row's start date makes
     * BOTH match on that date and "which unit were they in on D?" has two
     * answers. Payroll asks that question for every employee every month, and
     * this hook is the path the Batch 4 importer and every plain employee edit
     * take — so a discrepancy here would be the common case, not the rare one.
     *
     * WHY reason = نقل داخلي for the automatic case: it is the truthful default
     * for "something about this posting changed". A caller who knows it was a
     * ترقية says so through the assignments endpoint, which writes the history
     * row directly and leaves this hook nothing to do.
     */
    private function recordPostingChange(): void
    {
        $changed = $this->wasRecentlyCreated
            ? array_filter(EmployeeAssignment::SYNCED_COLUMNS, fn (string $column): bool => $this->{$column} !== null)
            : array_filter(EmployeeAssignment::SYNCED_COLUMNS, fn (string $column): bool => $this->wasChanged($column));

        if ($changed === []) {
            return;
        }

        $open = $this->currentAssignment()->first();

        $posting = [
            'department_id'        => $this->department_id,
            'job_title_id'         => $this->job_title_id,
            'organizational_grade' => $this->organizational_grade,
        ];

        if (! $open instanceof EmployeeAssignment) {
            $this->assignments()->create($posting + [
                'from_date' => $this->hire_date ?? now()->toDateString(),
                'reason'    => AssignmentReason::Hire,
            ]);

            return;
        }

        $effective = now()->startOfDay();

        // The open posting never covered an elapsed period, so there is no
        // history to preserve: amend it instead of stacking a zero-length one
        // in front of it.
        if ($open->from_date !== null && $open->from_date->gte($effective)) {
            $open->update($posting);

            return;
        }

        $open->update(['to_date' => $effective->copy()->subDay()->toDateString()]);

        $this->assignments()->create($posting + [
            'from_date' => $effective->toDateString(),
            'reason'    => AssignmentReason::Transfer,
        ]);
    }

    /**
     * The three name parts joined — الاسم الثلاثي.
     *
     * An ACCESSOR, never a column. Storing the parts and the joined string is
     * two columns for one fact, and the workbook shows where that ends: it
     * carries الإسم الثلاثي, الاسم القديم *and* a مقارنة الأسماء column whose
     * only job is to flag where the two disagree (P1).
     *
     * ⚠️ NOT SEARCHABLE IN SQL. `WHERE full_name LIKE ?` does not exist, because
     * the column does not. Search the parts — EmployeeService::paginate() does.
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ]))));
    }

    /**
     * Total children — عدد الأبناء.
     *
     * Derived, because the intake form counts sons and daughters separately and
     * those two are the facts. A third stored column would be the same number
     * twice, and the first edit that updated one without the other would make
     * the row disagree with itself.
     */
    protected function childrenCount(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->sons_count + (int) $this->daughters_count);
    }

    /**
     * Set by `ManagementChain::attachTo()` only; nothing else knows the rules.
     */
    public function setResolvedManager(?Employee $manager): void
    {
        $this->managerFromTree = $manager;
    }

    /**
     * This employee's manager as the tree gives it, or null — at the top of
     * the tree, with no unit, or when nobody called `ManagementChain` first.
     *
     * A method, not a property: `$employee->resolvedManager` would be read by
     * Eloquent as a relation and fail.
     */
    public function resolvedManager(): ?Employee
    {
        return $this->managerFromTree;
    }

    /**
     * The unit this employee currently belongs to — the denormalised pointer,
     * kept in step with the open assignment by the hook pair.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The catalogue title this employee currently holds.
     */
    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }

    /**
     * The login account linked to this employee, if any. The FK lives on
     * `users`, so this is a hasOne.
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /**
     * Every posting this person has held, newest first.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class)->orderByDesc('from_date')->orderByDesc('id');
    }

    /**
     * The posting in force now — the row with `to_date IS NULL`.
     */
    public function currentAssignment(): HasOne
    {
        return $this->hasOne(EmployeeAssignment::class)->whereNull('to_date');
    }

    /**
     * Files held on this person.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    /**
     * Disciplinary warnings, most recent first.
     */
    public function warnings(): HasMany
    {
        return $this->hasMany(EmployeeWarning::class)->orderByDesc('issued_on')->orderByDesc('id');
    }

    /**
     * Only staff who still work here — نشط or موقوف, but not منتهي الخدمة.
     *
     * Reads the enum's own `isEmployed()` rather than listing cases, so a future
     * state is classified in one place (P4).
     *
     * @param  Builder<self>  $query
     */
    public function scopeEmployed(Builder $query): void
    {
        $query->whereIn('status', array_map(
            static fn (EmployeeStatus $status): int => $status->value,
            array_filter(EmployeeStatus::cases(), static fn (EmployeeStatus $status): bool => $status->isEmployed()),
        ));
    }

    /**
     * Only staff at a given status.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithStatus(Builder $query, EmployeeStatus $status): void
    {
        $query->where('status', $status->value);
    }
}
