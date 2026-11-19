# Attendance system — end-to-end manual test scenarios

Use this pack against matching builds of the Laravel API, Flutter employee app, and ERP dashboard. These are **planned checks, not executed results**. Record the build/commit of each component and the attendance timezone for every run. Use test employees and test dates in an isolated environment; a check-in, rejection, correction, or schedule change can affect reports and notifications.

**Priority:** P1 = core flow, authorization, or data integrity; P2 = important behavior; P3 = presentation. Mark each ID Pass / Fail / Blocked and attach a screenshot or API request ID for failures. For every mutation, check the app, dashboard, API response, and subsequent report where applicable.

## Test data and setup

Prepare: an active office employee; an overnight employee; a multi-day guard; an employee with approved leave; an inactive employee; an unlinked technical account; an HR reviewer with company-wide `attendance.records.view/correct/reject`; a view-only reviewer; a reviewer with limited employee reach; and a user without attendance grants. Give the employees schedules, a shift with dated rule versions, and a rest day. Have two phones or emulators, one capable of going offline. Keep sample selfie images under and over 10 MB for API validation. Set the clock only in an isolated test environment; use the existing test clock tooling or fixed-clock automated tests for boundary scenarios.

The separate [work-shifts app device pack](../../alraqi-erp-app/docs/manual-test-scenarios/work-shifts-app.md) adds detailed overnight, 72-hour, stale offline card, swap, and shift-change walkthroughs (`WS-*`). Run it alongside this pack. Shift management is included here because schedules change attendance results; swap and leave workflows get cross-checks rather than a full separate suite.

## A. Employee recording and capture

| ID | Priority | Steps | Expected result |
|---|---|---|---|
| ATT-001 | P1 | Sign in as an active employee, open Home and `دوامي`, and refresh. | Both show the same current duty and attendance state; action and date match the employee's schedule. |
| ATT-002 | P1 | Check in during a scheduled duty with location and selfie permissions granted. | One check-in is saved for the duty's `shift_date`; app shows checked in, dashboard shows the record, and a private selfie is viewable only through an authorized endpoint. |
| ATT-003 | P1 | Check out after the minimum gap (default 60 minutes). | One check-out pairs with the check-in; worked hours and day state update on app, daily report, monthly report, and employee grid. |
| ATT-004 | P1 | Try to check out before checking in, then before the minimum gap, then at the exact boundary. | First two are refused; the app blocks the early action and the API independently enforces it. The boundary is accepted. |
| ATT-005 | P1 | Double tap check-in or send the same `client_uuid` twice. | Only one event and one selfie file exist; a retry returns the saved record rather than creating another. |
| ATT-006 | P1 | After a completed duty, attempt a second check-in in that same duty. | Refused; no second accepted check-in or hours are counted. |
| ATT-007 | P1 | While a check-in remains open, attempt another check-in, including across midnight. | Refused until the open duty is checked out or closed under its shift rules. |
| ATT-008 | P1 | Check in on a rest day, before a shift window, and after a shift. Cancel the warning once and confirm it once. | The warning appears where applicable; cancel opens no camera or record. Confirmed capture is marked outside the schedule and remains distinct in reports. |
| ATT-009 | P1 | Check in to an overnight shift, then check out the next morning. | Both events belong to the starting shift date; the action remains check-out after midnight. |
| ATT-010 | P1 | Keep a multi-day guard duty open through days 2–3, then check out during its permitted close window. | The app keeps the active duty, and the checkout attaches to its starting shift date. |
| ATT-011 | P1 | Test approved leave on a scheduled day, with `ATTENDANCE_ALLOW_DURING_LEAVE=false`. | Check-in is refused and the day remains leave in reports. Repeat with the setting enabled in a disposable environment to confirm the configured exception. |
| ATT-012 | P1 | Try to record as an inactive employee and as an account with no linked employee. | No event is saved; each receives the appropriate authorization/business-rule response. |
| ATT-013 | P1 | Deny camera, cancel camera, deny location, then retry with permissions restored. | No partial attendance event is sent or counted on failed capture; the employee can retry successfully. |
| ATT-014 | P2 | Capture a selfie, background/kill the app during camera return, reopen it, and retry. | The draft/recovery flow does not lose or duplicate the event; any pending item is visible and syncable. |
| ATT-015 | P1 | Submit missing/invalid type, coordinates outside latitude/longitude bounds, invalid time, non-image selfie, oversized selfie, or malformed UUID directly to the API. | Validation fails; no attendance row or orphan selfie file is created. |
| ATT-016 | P1 | Send `recorded_at` beyond the configured future tolerance (default 10 minutes), then within tolerance. | Far-future event is refused; allowed clock drift is accepted. |

## B. Offline queue and synchronization

| ID | Priority | Steps | Expected result |
|---|---|---|---|
| ATT-017 | P1 | Turn network off, capture a check-in with selfie, and reopen the app. | It remains in the local queue, the hero/calendar show the pending state, and the selfie is retained for retry. |
| ATT-018 | P1 | Restore network and let sync run; repeat after app restart. | One server record is created; queue clears only after success; later retries do not duplicate it. |
| ATT-019 | P1 | Check in online, capture check-out offline before close, and sync after close. | The server accepts the original capture time, clears any missing-checkout flag, and reports a complete day. |
| ATT-020 | P1 | Queue check-in and check-out offline, then sync together. | They are processed in order; one valid pair is saved with correct hours. |
| ATT-021 | P1 | Make one queued item invalid while another is valid; sync the batch. | Successful item is saved once; failed item remains identifiable with an actionable message and does not block the other. |
| ATT-022 | P1 | Interrupt network after upload or response, then retry the same queued UUID. | Server and app converge on one saved event and one selfie. |
| ATT-023 | P1 | Sign out with pending captures; sign in as a different employee. | The second employee never sees or uploads the first employee's queue, selfie, or kept duty card. Verify the first account's recovery according to the app's sign-out behavior. |
| ATT-024 | P2 | Start offline with a recently kept duty card, then with no card or a card older than its coverage. | Current cached duty guides the hero; stale/missing duty uses the app's fallback and states that shift attribution is decided on send. |
| ATT-025 | P1 | Change server schedule while an offline capture waits, then sync. | The server applies its scheduling rules at the recorded time and the app refreshes to the authoritative result; no silent duplicate is created. |

## C. Employee calendar, history, and notices

| ID | Priority | Steps | Expected result |
|---|---|---|---|
| ATT-026 | P2 | Open `دوامي`, navigate previous months, return to current, and tap days with present, absent, rest, leave, missing checkout, and rejected states. | Calendar counts and day sheet agree with server state; future cells do not fabricate attendance. |
| ATT-027 | P2 | Open a duty whose start date differs from today from the hero. | The day sheet opens the duty's actual date, including a missed checkout from a prior day. |
| ATT-028 | P2 | Open full history, filter a date range and rejected-only, clear filters, and scroll through multiple pages. | Correct records, order, grouping, empty states, and pagination; no duplicates across pages. |
| ATT-029 | P1 | Have HR reject a record, then open the employee notice and full history. | Employee can see the rejected row, reason, and relevant day; it no longer contributes to accepted attendance. |
| ATT-030 | P1 | Request another employee's record and selfie using `/api/me/attendance/{id}` and `/selfie`. | Both are inaccessible (404); no private image path or URL is disclosed. |
| ATT-031 | P2 | Pull to refresh after a check-in, checkout, correction, rejection, leave change, or schedule change. | Hero, calendar, day sheet, history, and summaries converge on the server state. |

## D. Dashboard attendance review and actions

| ID | Priority | Steps | Expected result |
|---|---|---|---|
| ATT-032 | P1 | Open attendance records, daily report, monthly report, employee profile attendance, absent today, and on-leave today with a company-wide viewer. | Each shows only the expected employees and the same underlying day states; permitted actions are visible. |
| ATT-033 | P1 | Repeat as view-only, limited-reach, and no-grant users; call the corresponding API routes directly. | Action controls follow permissions; API enforces the same grants and employee reach even if UI is bypassed. |
| ATT-034 | P2 | Filter records by date/range, employee, type, source, rejection, and missing checkout; sort and paginate. | Results, counts, URL state, and displayed page follow selected filters; reset restores defaults. |
| ATT-035 | P2 | Change daily date, filters, sort, bucket cards, and page; issue rapid consecutive filter changes. | Card totals and list sections agree; only the latest request appears; invalid date does not show data under the wrong heading. |
| ATT-036 | P2 | Change monthly period, department/employee filters, sorts, and problem-only filters; open a row's employee attendance. | Monthly totals recompute for the shown population; link preserves the viewed date range. |
| ATT-037 | P2 | Filter employee profile attendance by range and day state, including a long/invalid range. | Grid and summary match the server's counted window; invalid range is blocked or shown as a validation error. |
| ATT-038 | P2 | Test absent-today and on-leave-today filters, pagination, and navigation to an employee. | Only expected employees are listed for that date; pagination recovers if a changed record empties the current page. |
| ATT-039 | P1 | Open check-in and checkout selfies in records/daily/profile, then try absent, purged, rejected, and out-of-reach images. | Authorized images load; unavailable images show a clear state; no public storage URL or cross-scope image is exposed. |
| ATT-040 | P1 | Correct a missing checkout with a valid time and note. | A new HR-correction checkout appears with corrector, time, note, and hours; missing flag clears everywhere. |
| ATT-041 | P1 | Try correction on a checkout, rejected check-in, already completed day, before/equal check-in, after duty close, and in the future. | Each invalid correction is refused without changing the day. HR correction is exempt from the employee minimum-gap rule. |
| ATT-042 | P1 | Reject a check-in that has a checkout; use each reason, including `Other` with/without note. | Reason validation works; check-in and paired checkout are rejected as one decision; report totals change and employee receives a notice. |
| ATT-043 | P1 | Reject only a checkout, then undo its rejection. | Check-in remains accepted; checkout/hours disappear and return when restored. |
| ATT-044 | P1 | Undo a paired rejection from either member. | Exactly that rejection batch is restored; both records and calculations return. No duplicate notice is sent by undo. |
| ATT-045 | P1 | Reject a day, let employee record again, then try undoing the old rejection. | Undo is refused because it would create duplicate accepted events; the new day remains intact. |
| ATT-046 | P2 | Inspect audit history after recording, correction, rejection, and undo. | Reviewer/action/timestamps can be traced; raw private selfie contents are not exposed in audit output. |
| ATT-047 | P2 | With an approved leave or excuse, test daily board actions such as return-to-work and undo-excuse using appropriately granted reviewers. | The affected day's bucket and counts update after action; forbidden reviewers cannot perform it. |

## E. Schedule rules and report calculations

| ID | Priority | Steps | Expected result |
|---|---|---|---|
| ATT-048 | P1 | Create/edit a shift and schedule, assign it to an employee, and inspect the calendar and app duty card. | Expected days and times match the effective assignment; existing attendance is not silently lost. |
| ATT-049 | P1 | Add a future-dated shift rule version changing check-in opening, late grace, early grace, or close margin. Test days before and after its effective date. | Each day uses its effective rule version; past results stay tied to the applicable dated rule. |
| ATT-050 | P1 | Change one future duty and a date range, including a rest day; then change a past duty with an existing check-in. | Calendar and app update; existing check-in is rematched under the supported rule; rejected/invalid range changes are atomic. |
| ATT-051 | P1 | Compare scheduled check-in at grace boundary and one minute beyond; checkout at early-departure boundary and one minute before. | Late and early counts/minutes follow the shift's separate grace values. |
| ATT-052 | P2 | Check a shift with overtime enabled and disabled, plus shortfall and a multi-day duty. | Worked hours, overtime, shortfall, late, and early figures agree across day sheet, employee grid, and monthly report. |
| ATT-053 | P1 | Compare a scheduled rest day, approved leave, excused absence, missing checkout, rejected day, and ordinary absence. | Daily buckets, absent list, monthly denominators, and attendance rate use the intended state precedence and expected-duty population. |
| ATT-054 | P1 | File/approve/cancel a guard shift swap; inspect both employees' schedules and attendance before and after. | Only the approved effective swap changes expected duties; attendance attribution and reports follow the final schedule. See `WS-SWAP-*` for the full device flow. |

## F. Scheduled jobs, retention, and API boundaries

| ID | Priority | Steps | Expected result |
|---|---|---|---|
| ATT-055 | P1 | Leave a scheduled check-in open at duty end; run `attendance:shift-clock` twice. | One checkout reminder is sent; the second run does not duplicate it. Outside-schedule check-ins get no reminder. |
| ATT-056 | P1 | Keep it open through the shift's close margin; run the clock. | Check-in becomes missing checkout with no invented checkout or hours; rejected/completed days remain unchanged. |
| ATT-057 | P1 | Sync a valid offline checkout captured before close after the close job ran. | Missing flag clears, checkout is paired, and reports recalculate. |
| ATT-058 | P2 | Create an old attendance selfie beyond retention and a newer one; run `attendance:purge-selfies`. | Old private file is removed and marked purged; newer file remains; both attendance records and their non-image history remain. |
| ATT-059 | P1 | Call all self-service routes without auth, with an unlinked account, and with an employee account. | Auth and self ownership are enforced; a technical account cannot create or read another person's attendance. |
| ATT-060 | P1 | Call oversight list/report/action/selfie routes with no grant, view-only, and out-of-scope reviewers; use unknown IDs too. | Permissions and row scope are enforced server-side; unknown IDs and out-of-scope records cannot leak data. |
| ATT-061 | P2 | Send malformed dates, unsupported filters/sorts, oversized page sizes, and an empty sync batch to the API. | Validation or documented clamping is consistent; no unfiltered or cross-scope data is returned. |
| ATT-062 | P1 | Force a sync storage/DB failure in a disposable environment. | Failed entry reports a generic server error without SQL/path leakage; no partial attendance record or orphan selfie remains. |

## Execution record

| Scenario ID | Date/time and timezone | App/API/dashboard commits | Employee / role | Pass / Fail / Blocked | Evidence / bug ID |
|---|---|---|---|---|---|
| | | | | | |

**Source inventory:** `app/Modules/Attendance/routes.php`, `app/Modules/Me/routes.php`, `app/Modules/Attendance/Services/{AttendanceService,AttendanceOversightService,AttendanceShiftClock,AttendanceScheduleService}.php`, `app/Modules/Scheduling/routes.php`, Flutter `lib/features/attendance`, dashboard `src/sections/attendance` and `src/sections/scheduling`. This pack covers current behavior visible in those sources; execute it against the exact intended deployment commits because scheduling and attendance rules have recently changed.
