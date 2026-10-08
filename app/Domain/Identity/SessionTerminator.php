<?php

namespace App\Domain\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ends the authenticated sessions of an account, whatever their kind: bearer tokens and cookie sessions
 * (stored server-side). Used on suspension, password reset and "log out other devices".
 */
class SessionTerminator
{
    /**
     * @return int number of tokens and sessions ended
     */
    public function terminateAll(User $user): int
    {
        return $user->tokens()->delete() + $this->deleteCookieSessions($user, null);
    }

    /**
     * @return int number of tokens and sessions ended
     */
    public function terminateOthers(User $user, ?int $currentTokenId, ?string $currentSessionId): int
    {
        $tokens = $user->tokens()->when($currentTokenId !== null, fn ($query) => $query->whereKeyNot($currentTokenId))->delete();

        return $tokens + $this->deleteCookieSessions($user, $currentSessionId);
    }

    private function deleteCookieSessions(User $user, ?string $keepSessionId): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::table(config()->string('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->when($keepSessionId !== null, fn ($query) => $query->where('id', '<>', $keepSessionId))
            ->delete();
    }
}
