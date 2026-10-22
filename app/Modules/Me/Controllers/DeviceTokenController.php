<?php

declare(strict_types=1);

namespace App\Modules\Me\Controllers;

use App\Modules\Auth\Enums\DevicePlatform;
use App\Modules\Auth\Services\DeviceTokenService;
use App\Modules\Me\Requests\DeviceTokenRequest;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 *   POST   /api/me/device-tokens  → register this device for push
 *   DELETE /api/me/device-tokens  → stop pushing to it
 */
class DeviceTokenController extends Controller
{
    use ApiRespond;

    public function __construct(
        private readonly DeviceTokenService $deviceTokens,
    ) {}

    public function store(DeviceTokenRequest $request): JsonResponse
    {
        $this->deviceTokens->register(
            user: $request->user(),
            token: $request->validated('token'),
            platform: DevicePlatform::from($request->validated('platform')),
        );

        return $this->success(message: 'تم تسجيل الجهاز للإشعارات.');
    }

    public function destroy(DeviceTokenRequest $request): JsonResponse
    {
        $this->deviceTokens->unregister($request->user(), $request->validated('token'));

        return $this->success(message: 'تم إلغاء تسجيل الجهاز من الإشعارات.');
    }
}
