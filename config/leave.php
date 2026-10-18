<?php

/*
|--------------------------------------------------------------------------
| Leave — work order §4.7, docs/LEAVE_BUILD.md §2
|--------------------------------------------------------------------------
|
| Legacy's config/leave.php carried only `annual_days`; the other two keys are
| the ERP's (the resolved chain, Q1 = B, and the attachments the owner
| answered for on 2026-10-18).
|
*/

return [

    /*
    | The allocation an employee has in a year when HR has not set one on their
    | leave_balances row. 15, confirmed by the project owner (D13, 2026-10-16).
    */
    'annual_days' => (int) env('LEAVE_ANNUAL_DAYS', 15),

    /*
    | The unit whose manager is a leave request's second step — مدير الموارد
    | (Q1 = B). A department CODE, not an employee id, so a new HR manager
    | needs no deploy: the step resolves to whoever manages the unit then.
    */
    'hr_unit_code' => env('LEAVE_HR_UNIT_CODE', 'HR'),

    /*
    | Files on a request or an excuse (the owner's answer, 2026-10-18): up to
    | five, PDF or image, 10 MB each. Required only where the type says so
    | (sick leave), and never on HR's excuse.
    */
    'attachments' => [
        'max_files' => 5,
        'max_kb'    => 10240,
        'mimes'     => ['pdf', 'jpg', 'jpeg', 'png'],
    ],

];
