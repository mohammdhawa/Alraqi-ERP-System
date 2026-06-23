<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RefreshTokenRequest
 *
 * Validates the refresh token is present and properly formatted.
 *
 * NOTE: This is a public endpoint (no auth:sanctum) because the access token
 * has expired — that's the whole reason the client is calling /refresh.
 * Authentication here is done via the refresh token itself in AuthService.
 */
class RefreshTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth is done via the refresh token, not Sanctum
    }

    /**
     * @return array<string, array<string>>
     */
    public function rules(): array
    {
        return [
            'refresh_token' => ['required', 'string', 'size:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'refresh_token.required' => 'رمز التحديث مطلوب.',
            'refresh_token.size'     => 'صيغة رمز التحديث غير صحيحة.',
        ];
    }
}