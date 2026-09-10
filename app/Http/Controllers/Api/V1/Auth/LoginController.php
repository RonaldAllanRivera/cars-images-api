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
     * Exchange credentials for a bearer token carrying the requested scope,
     * or the default scope when none is asked for.
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

        $abilities = array_key_exists('abilities', $credentials)
            // Intersected in all()'s order, not the request's: the issued list
            // is then canonical however the client asked, which keeps the
            // response byte-stable for the contract fixture, and a client that
            // names the same ability twice gets it once. Validation already
            // restricts each entry to all() - this is the line that still
            // holds if that rule is ever loosened.
            ? array_values(array_intersect(TokenAbilities::all(), $credentials['abilities']))
            // Never all(): minting that would hand every client the privileged
            // abilities it never asked for.
            : TokenAbilities::defaultScope();

        $token = $user->createToken($credentials['device_name'], $abilities);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => $abilities,
            'user' => UserResource::make($user)->resolve(),
        ], 201);
    }
}
