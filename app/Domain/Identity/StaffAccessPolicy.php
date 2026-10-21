<?php

namespace App\Domain\Identity;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Staff working hours; independent of SuperAdmin mTLS.
 * Default is unrestricted until the SuperAdmin sets a schedule.
 */
final class StaffAccessPolicy
{
    public function for(User $user): ?object
    {
        return DB::table('staff_access_windows')->where('user_id', $user->id)->first();
    }

    public function allows(User $user): bool
    {
        $policy = $this->for($user);
        if (! $policy || ! $policy->enabled) {
            return true;
        }
        $days = json_decode((string) $policy->weekdays, true);
        if (! is_array($days) || ! $policy->start_time || ! $policy->end_time) {
            return false; // An incomplete active policy fails closed.
        }
        $now = CarbonImmutable::now($policy->timezone ?: 'Africa/Lome');
        $weekday = (int) $now->dayOfWeekIso; // Monday = 1, Sunday = 7.
        $clock = $now->format('H:i');

        // Same-day windows only. Overnight shifts require explicit future extension.
        return in_array($weekday, $days, true)
            && $clock >= $policy->start_time && $clock < $policy->end_time;
    }
}
