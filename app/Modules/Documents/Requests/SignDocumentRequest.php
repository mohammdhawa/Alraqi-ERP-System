<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use App\Modules\Documents\Requests\Concerns\ConfirmsTheCaller;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/documents/{document}/sign — «قرأت ووافقت» (documents design §9.2,
 * §10.3): the confirmation only, `ConfirmsTheCaller`'s. Whether the document
 * still awaits the signature, the caller's drawn signature and the password's
 * match are the service's: the route's `can:answer,document` runs first, so
 * no one but the recipient (and the administrator, whom the service refuses)
 * has their body read.
 */
class SignDocumentRequest extends FormRequest
{
    use ConfirmsTheCaller;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->confirmationRules();
    }

    public function messages(): array
    {
        return $this->confirmationMessages();
    }
}
