<?php

declare(strict_types=1);

namespace App\Modules\Auth\Resources;

use App\Shared\Support\AuditEventCatalogue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AuditLogResource
 *
 * One row of سجل التدقيق as a LIST item.
 *
 * WHAT IS DELIBERATELY NOT HERE: `old_values`, `new_values` and `user_agent`.
 * A `deleted` employee row holds around fifty keys, and a hundred of those per
 * page is a response nobody asked for — so the blobs live on the detail
 * endpoint only, behind their own permission. See AuditLogDetailResource.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * THE THREE FACTS THIS SHAPE IS CAREFUL ABOUT
 * ──────────────────────────────────────────────────────────────────────────
 * 1. `created_at` is ISO-8601 CARRYING THE +03:00 OFFSET. The app timezone is
 *    Asia/Damascus and `now()` writes local time, so serialising without the
 *    offset would move every event three hours the instant a browser parsed it.
 *
 * 2. THERE ARE THREE KINDS OF ACTOR, not two, and they are three different
 *    sentences:
 *      actor_type "system"                  → a seeder, console command or job
 *      actor_type "user" + user object      → that person
 *      actor_type "user" + user: null       → the account has been deleted
 *    The third is precisely the case an audit is most often opened to answer,
 *    so it must be distinguishable rather than collapsed into "unknown".
 *
 * 3. `auditable_type` is a stable alias, never a PHP class name (C12). That is
 *    guaranteed by the model's own accessor rather than restated here — see
 *    AuditLog::$auditableType for the pre-morph-map rows that make this more
 *    than a straight column read, and for why the eager load depends on it.
 *
 * NAMES ARE UNGATED, VALUES ARE GATED. `changed_fields` says WHICH fields moved
 * and ships to anyone holding `auth.audit.view`; what they moved from and to
 * needs `auth.audit.viewValues`. That split is the point of having two
 * permissions: "someone changed this employee's grade at 14:03" is the audit
 * question, and answering it does not require reading the grade.
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Already a public alias or null — the model's accessor translated it.
        $type = $this->auditable_type;

        return [
            'id'              => $this->id,

            // ---- What happened ----
            'event'           => $this->event,
            'event_label'     => AuditEventCatalogue::labelOf($this->event),
            'event_group'     => AuditEventCatalogue::groupOf($this->event),
            'description'     => $this->description,

            // ---- Who did it ----
            'actor_type'      => $this->user_id === null ? 'system' : 'user',
            'user_id'         => $this->user_id,
            // NEVER OMITTED (C31). Null means either "no actor" (paired with
            // actor_type "system") or "the account is gone" (paired with
            // actor_type "user"); the pair is what tells them apart.
            'user'            => $this->actor(),

            // ---- What it was done to ----
            'auditable_type'  => $type,
            'auditable_label' => AuditTargetStub::typeLabel($type),
            'auditable_id'    => $this->auditable_id,
            'auditable'       => AuditTargetStub::from($this->auditable, $type),
            'changed_fields'  => $this->changedFields(),

            // ---- From where ----
            'ip_address'      => $this->ip_address,
            'request_id'      => $this->request_id,

            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * The actor as an object, or null when there is no live account to name.
     *
     * Reads `user_id` before touching the relation, so the system-event case
     * costs no relation access at all. `name` is the linked employee's name —
     * an account stores none of its own (users.employee_id -> employees), and
     * AuditTrailService eager-loads `user.employee` for exactly this line.
     *
     * @return array{id: int, name: string|null, email: string}|null
     */
    private function actor(): ?array
    {
        if ($this->user_id === null || $this->user === null) {
            return null;
        }

        return [
            'id'    => (int) $this->user->getKey(),
            'name'  => $this->user->employee?->full_name,
            'email' => $this->user->email,
        ];
    }

    /**
     * The field names this event touched.
     *
     * ONLY MODEL EVENTS HAVE CHANGED FIELDS. An `api_request` row stores
     * {method, path, status_code, route_name} in `new_values` and an auth event
     * stores its own metadata there; listing those as "changed fields" would
     * present request metadata as a record edit. Those events get `[]`, which is
     * the truth — they changed no model field.
     *
     * A `deleted` event reads from `old_values` (its `new_values` is null by
     * design), so the columns that vanished are still named. A `created` event
     * legitimately lists every attribute it set; the client shows the first few
     * and counts the rest.
     *
     * Always an array, never null — a client iterates it.
     *
     * @return array<int, string>
     */
    private function changedFields(): array
    {
        if (AuditEventCatalogue::groupOf($this->event) !== AuditEventCatalogue::GROUP_MODEL) {
            return [];
        }

        $values = $this->event === 'deleted' ? $this->old_values : $this->new_values;

        return array_keys($values ?? []);
    }
}
