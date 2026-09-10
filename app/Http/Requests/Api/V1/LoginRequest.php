<?php

namespace App\Http\Requests\Api\V1;

use App\Auth\TokenAbilities;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            // Names the token (Settings -> "Pixel 8", "Chrome on laptop") so
            // one device can be revoked without touching the others.
            'device_name' => ['required', 'string', 'max:255'],
            /*
             * Optional, and narrowing only. A client that omits this gets
             * TokenAbilities::defaultScope(); a client that sends it gets the
             * intersection with what exists. `min:1` because a zero-ability
             * token fails every route silently, which reads as a server fault
             * from the client side - a 422 at the door is kinder and truer.
             */
            'abilities' => ['sometimes', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(TokenAbilities::all())],
        ];
    }
}
