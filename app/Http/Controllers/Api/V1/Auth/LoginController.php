<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\TokenAbilities;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /**
     * Exchange credentials for a bearer token carrying every ability.
     */
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            // One message for "no such account" and "wrong password", so the
            // endpoint cannot be used to discover which emails exist.
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $token = $user->createToken($credentials['device_name'], TokenAbilities::all());

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => TokenAbilities::all(),
            'user' => UserResource::make($user)->resolve(),
        ], 201);
    }
}
