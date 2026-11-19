<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The dev clock's file
    |--------------------------------------------------------------------------
    |
    | Where `php artisan dev:clock` keeps the offset a LOCAL environment adds
    | to every "now" (App\Shared\Support\DevClock). No file, no offset. It sits
    | under storage/app, which git ignores, and no other environment reads it.
    |
    */

    'path' => env('DEV_CLOCK_PATH', storage_path('app/dev-clock.json')),

    /*
    | The adb binary `dev:clock --adb=<serial>` runs. Empty: the Android SDK's
    | own (ANDROID_HOME, ANDROID_SDK_ROOT, or the default install), then `adb`
    | on the PATH.
    */

    'adb' => env('DEV_CLOCK_ADB'),

];
