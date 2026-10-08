<?php

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Audit\SecurityEventLogger;
use App\Domain\Identity\Http\Resources\UserResource;
use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Identity\SessionTerminator;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

class SessionController
{
    public function __construct(
        private readonly SecurityEventLogger $securityLogger,
        private readonly SessionTerminator $terminator,
    ) {}

    /**
     * Profile of the authenticated account.
     */
    public function me(Request $request): UserResource
    {
        return new UserResource($this->user($request)->load(['organization', 'pernumIdentities']));
    }

    /**
     * Active sessions (tokens) of the authenticated account.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $current = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->getKey() : null;

        $sessions = $user->tokens()
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SanctumToken $token): array => [
                'id' => $token->id,
                'device_name' => $token->name,
                'ip_address' => $token->ip_address,
                'user_agent' => $token->user_agent,
                'created_at' => $token->created_at?->toIso8601ZuluString(),
                'last_used_at' => $token->last_used_at?->toIso8601ZuluString(),
                'expires_at' => $token->expires_at?->toIso8601ZuluString(),
                'current' => $token->id === $currentId,
            ]);

        return response()->json(['data' => $sessions]);
    }

    /**
     * Revoke one session of the authenticated account.
     */
    public function destroy(Request $request, int $session): JsonResponse
    {
        $user = $this->user($request);
        $deleted = $user->tokens()->whereKey($session)->delete();

        abort_if($deleted === 0, 404);

        $this->securityLogger->record('auth.session_revoked', userId: $user->id, metadata: ['token_id' => $session]);

        return response()->json(null, 204);
    }

    /**
     * End every other session of the account, tokens and cookie sessions alike ("log out other devices").
     */
    public function destroyOthers(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $current = $user->currentAccessToken();

        $revoked = $this->terminator->terminateOthers(
            $user,
            $current instanceof PersonalAccessToken ? (int) $current->getKey() : null,
            $request->hasSession() ? $request->session()->getId() : null,
        );

        $this->securityLogger->record('auth.other_sessions_revoked', userId: $user->id, metadata: ['revoked' => $revoked]);

        return response()->json(['data' => ['revoked' => $revoked]]);
    }

    /**
     * Log out: revoke the current session.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $tokenId = $token->getKey();
            $token->delete();
        } else {
            $tokenId = null;
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        $this->securityLogger->record('auth.logout', userId: $user->id, metadata: ['token_id' => $tokenId, 'mode' => $tokenId === null ? 'session' : 'token']);

        return response()->json(null, 204);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
