<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily cleanup of expired/revoked refresh tokens so the table does not
// grow unbounded. The command keeps revoked tokens for a retention window
// (default 7 days) for forensic analysis before deleting them.
Schedule::command('auth:prune-refresh-tokens')->daily();