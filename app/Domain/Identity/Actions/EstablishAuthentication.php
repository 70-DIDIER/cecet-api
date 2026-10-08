<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\SecurityEventLogger;
use App\Domain\Identity\Http\Resources\AccessTokenResource;
use App\Domain\Identity\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Final step of every successful authentication.
 *
 * Requests coming from the React site (stateful Sanctum domains) get an HttpOnly session cookie: no token ever
 * reaches the browser. Other clients (mobile apps, integrations) get an expiring bearer token.
 */
class EstablishAuthentication
{
    public function __construct(
        private readonly IssueAccessToken $tokens,
        private readonly SecurityEventLogger $securityLogger,
    ) {}

    public function handle(Request $request, User $user, string $deviceName): JsonResponse
    {
        if (! $request->hasSession()) {
            return (new AccessTokenResource($this->tokens->handle($user, $deviceName)))->response();
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        $this->securityLogger->record('auth.login_succeeded', userId: $user->id, metadata: ['mode' => 'session']);

        return response()->json(['data' => [
            'status' => 'authenticated',
            'mode' => 'session',
            'user' => new UserResource($user->loadMissing(['organization', 'pernumIdentities'])),
        ]]);
    }
}
