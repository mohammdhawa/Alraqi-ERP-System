<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The approvals engine (work order §4.6; docs/APPROVALS_ENGINE_DESIGN.md §3).
 *
 * ONE ENGINE FOR EVERY CONSUMER. Legacy approves leave and documents in two
 * unrelated places; the ERP keeps both behaviours in one set of tables, the
 * difference carried as data (`rejection_policy`, `step_order`, `decisive`).
 * Leave is the first consumer; documents join in November.
 *
 * ABOUT PEOPLE, PRESSED BY ACCOUNTS. `requested_by` and `approver_id` are
 * employees — units name employees as managers — and `acted_by` /
 * `performed_by` are the accounts that pressed the button. The employee FKs
 * RESTRICT: an approval is a record of who decided what, never cascaded away.
 *
 * NO "CURRENT STEP" COLUMN. It is derived — the lowest `step_order` holding a
 * pending step — so it can never disagree with the steps (P1).
 *
 * `approver_id` IS NEVER NULL (§9 Q2, Q8): a step that resolves to nobody, or
 * to a person with no active account, refuses the submission instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();

            // The subject, by morph alias: `leave_request` now, `document` in
            // November. One approval per subject.
            $table->string('approvable_type', 64);
            $table->unsignedBigInteger('approvable_id');

            $table->foreignId('requested_by')->constrained('employees')->restrictOnDelete();

            $table->unsignedTinyInteger('status');             // ApprovalStatus
            $table->unsignedTinyInteger('rejection_policy');   // RejectionPolicy, fixed at submission
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['approvable_type', 'approvable_id']);
            $table->index(['requested_by', 'status']);
            $table->index('status');
        });

        Schema::create('approval_steps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('approval_id')->constrained('approvals')->cascadeOnDelete();

            // Steps sharing an order are parallel — each must act; ascending
            // orders are sequential.
            $table->unsignedSmallInteger('step_order');

            // How the approver was found, kept so a skip or a refusal is
            // explainable later.
            $table->unsignedTinyInteger('resolver');           // StepResolver
            $table->string('resolver_ref', 64)->nullable();    // the unit code for unit_manager

            $table->foreignId('approver_id')->constrained('employees')->restrictOnDelete();

            // Under the advisory policy, the step whose decision ends the
            // approval (legacy's chief). Ignored under `ends`.
            $table->boolean('decisive')->default(false);

            $table->unsignedTinyInteger('status');                   // StepStatus
            $table->unsignedTinyInteger('skip_reason')->nullable();  // SkipReason

            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Legacy's unique(request, user) is too strict across orders: a
            // step skipped as "same approver" holds the same person at a
            // later order.
            $table->unique(['approval_id', 'step_order', 'approver_id']);

            // The inbox: what is waiting on this person.
            $table->index(['approver_id', 'status']);
            $table->index(['approval_id', 'status', 'step_order']);
        });

        Schema::create('approval_reassignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('approval_id')->constrained('approvals')->cascadeOnDelete();
            $table->foreignId('approval_step_id')->constrained('approval_steps')->cascadeOnDelete();

            // Copied, as legacy argues: the log must read without a join.
            $table->unsignedSmallInteger('step_order');

            $table->foreignId('from_employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('to_employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500);

            $table->timestamps();
            $table->softDeletes();

            $table->index('approval_step_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_reassignments');
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approvals');
    }
};
