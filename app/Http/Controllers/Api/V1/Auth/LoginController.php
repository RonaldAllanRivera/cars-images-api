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
     * Exchange credentials for a bearer token carrying the default scope.
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

        // defaultScope(), not all(): all() is the set a request may be
        // validated against, and minting it here would hand every client the
        // privileged abilities it never asked for.
        $abilities = TokenAbilities::defaultScope();

        $token = $user->createToken($credentials['device_name'], $abilities);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => $abilities,
            'user' => UserResource::make($user)->resolve(),
        ], 201);
    }
}
