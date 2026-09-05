<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
