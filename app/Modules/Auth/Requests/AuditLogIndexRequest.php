<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use App\Modules\Auth\Resources\AuditTargetStub;
use App\Modules\Auth\Services\AuditTrailService;
use App\Shared\Support\AuditEventCatalogue;
use App\Shared\Support\CsvList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * AuditLogIndexRequest
 *
 * Validates the filters and sort of `GET /api/auth/audit-logs`.
 *
 * WHY AN INDEX HAS A FORM REQUEST: an unknown filter or sort value must be a
 * 422, never a silent fallback (C30, P3). On this screen that rule has teeth —
 * silently ignoring `?event=deleted` returns a page of routine traffic to
 * someone asking "what was deleted", and they have no way to tell the answer is
 * "nothing shown" rather than "nothing happened". An audit tool that quietly
 * answers a different question than the one asked is worse than one that
 * refuses.
 *
 * `per_page` is deliberately NOT validated here — it is clamped in
 * ApiRespond::perPage() (C28), because a page size is a client hint that still
 * returns correct data when it is out of range, while a wrong filter returns the
 * wrong data.
 *
 * THE ALLOW-LISTS ARE READ FROM THEIR OWNERS, never restated: sortable columns
 * from AuditTrailService, event names from AuditEventCatalogue, target types
 * from AuditTargetStub. Copying any of them here is how a documented parameter
 * silently stops working after someone edits only one copy.
 */
class AuditLogIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route middleware owns coarse access
    }

    /**
     * `event` and `event_group` arrive as CSV — `?event=created,updated` — and
     * are split here so each ITEM can be checked against the catalogue. Sending
     * one unknown key in a list of five is still a 422; accepting the other four
     * and dropping the fifth would be the silent fallback this class exists to
     * prevent.
     *
     * AN ENTIRELY EMPTY LIST SURVIVES THE SPLIT AS `[]` AND IS CAUGHT BY `min:1`
     * BELOW, not dropped here. `?event=` and `?event=,,,` used to collapse to an
     * array that `sometimes|array` happily accepted and the service then ignored
     * — a full page of unfiltered rows, 200, no hint that the filter had been
     * discarded. On `event_group` that was worse than generic: the discarded
     * filter is the one the UI sends by default to hide the `api_request`
     * firehose, so a client asking for real changes silently got the firehose.
     */
    protected function prepareForValidation(): void
    {
        foreach (['event', 'event_group'] as $key) {
            $value = $this->input($key);

            if (! is_string($value)) {
                continue;
            }

            $this->merge([$key => CsvList::parse($value)]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['sometimes', 'integer', 'min:1'],

            // "Only what a person did" / "only what ran with nobody signed in".
            // The resource has always published `actor_type`; until 2026-09-20
            // nothing could filter on it, so the system-actor case — the one
            // where nobody is accountable — was the only one unreachable.
            'actor_type' => ['sometimes', 'string', Rule::in(array_keys(AuditTrailService::ACTOR_TYPES))],

            // `min:1` is the rule that makes `?event=` a 422 rather than a
            // filter that silently does nothing — see prepareForValidation().
            //
            // Capped at the catalogue's own size: a longer list is either a
            // mistake or someone probing, and either way it cannot match more
            // than every event there is.
            'event'         => ['sometimes', 'array', 'min:1', 'max:'.count(AuditEventCatalogue::eventNames())],
            'event.*'       => ['string', Rule::in(AuditEventCatalogue::eventNames())],
            'event_group'   => ['sometimes', 'array', 'min:1', 'max:'.count(AuditEventCatalogue::groupNames())],
            'event_group.*' => ['string', Rule::in(AuditEventCatalogue::groupNames())],

            // The public alias, never a PHP class name — a class name does not
            // belong in a URL, and accepting one would let a client discover the
            // namespace layout by probing (C12).
            //
            // The allow-list is the MORPH MAP (via typeKeys()), not the label
            // map it used to read: the map is what `auditable_type` actually
            // holds, so a newly audited model is filterable the moment its rows
            // exist. Validating against the labels made the newest rows in the
            // table 422 on the drill-down meant to reach them.
            //
            // No `sometimes`: it must become REQUIRED the moment auditable_id
            // appears, and `sometimes` would skip that rule on the exact request
            // it is there to catch. Laravel runs no other rule on an absent
            // attribute, so the plain list is correct when both are omitted.
            'auditable_type' => ['required_with:auditable_id', 'string',
                Rule::in(AuditTargetStub::typeKeys())],
            'auditable_id'   => ['sometimes', 'integer', 'min:1'],

            'request_id' => ['sometimes', 'string', 'max:64'],
            // Matched as a string rather than validated as an `ip`: the column
            // is nullable and historical rows may hold whatever a proxy sent.
            // 45 is the longest an IPv6 address with an embedded IPv4 can be.
            'ip_address' => ['sometimes', 'string', 'max:45'],

            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to'   => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],

            'search' => ['sometimes', 'string', 'max:255'],
            'sort'   => ['sometimes', 'string', Rule::in($this->sortKeys())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.integer'               => 'معرّف المستخدم يجب أن يكون رقماً.',
            'actor_type.in'                 => 'نوع المنفّذ المحدد غير معرّف. القيم المتاحة: user أو system.',
            'event.*.in'                    => 'أحد أنواع الأحداث المحددة غير معرّف.',
            'event.min'                     => 'قائمة الأحداث فارغة. احذف المُرشّح بدلاً من إرساله فارغاً.',
            'event_group.*.in'              => 'إحدى فئات الأحداث المحددة غير معرّفة.',
            'event_group.min'               => 'قائمة فئات الأحداث فارغة. احذف المُرشّح بدلاً من إرساله فارغاً.',
            'auditable_type.in'             => 'نوع السجل المحدد غير معرّف.',
            'auditable_type.required_with'  => 'يجب تحديد نوع السجل عند البحث برقم سجل.',
            'auditable_id.integer'          => 'رقم السجل يجب أن يكون رقماً.',
            'date_from.date_format'         => 'صيغة تاريخ البداية يجب أن تكون YYYY-MM-DD.',
            'date_to.date_format'           => 'صيغة تاريخ النهاية يجب أن تكون YYYY-MM-DD.',
            'date_to.after_or_equal'        => 'تاريخ النهاية يجب ألا يسبق تاريخ البداية.',
            'sort.in'                       => 'حقل الترتيب المحدد غير مدعوم. الترتيب متاح حسب التوقيت فقط.',
        ];
    }

    /**
     * Every accepted sort value: each allow-listed key plus its `-` descending
     * form. One key today, so two values — and the sort message says so, because
     * "غير مدعوم" without naming the alternative leaves a client guessing.
     *
     * @return array<int, string>
     */
    private function sortKeys(): array
    {
        $keys = array_keys(AuditTrailService::SORTABLE);

        return array_merge($keys, array_map(static fn (string $key): string => '-'.$key, $keys));
    }
}
