<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * The app's "is my token still good?" probe.
     */
    public function __invoke(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }
}
