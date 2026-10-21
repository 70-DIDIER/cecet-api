<?php

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\StaffAccessPolicy;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class StaffAccessWindowController
{
    public function show(User $user, StaffAccessPolicy $policy): JsonResponse
    {
        abort_unless($user->isStaff() && ! $user->isSuperadmin(), 403);
        $value = $policy->for($user);
        return response()->json(['data' => [
            'enabled' => (bool) ($value?->enabled ?? false),
            'weekdays' => $value ? json_decode((string) $value->weekdays, true) : [1,2,3,4,5],
            'start_time' => $value?->start_time ?? '08:00',
            'end_time' => $value?->end_time ?? '18:00',
            'timezone' => 'Africa/Lome',
        ]]);
    }

    public function update(Request $request, User $user, AuditLogger $audit): JsonResponse
    {
        abort_unless($user->isStaff() && ! $user->isSuperadmin(), 403);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'weekdays' => ['required', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $actor = $request->user();
        DB::transaction(function () use ($actor, $user, $data, $audit): void {
            $existing = DB::table('staff_access_windows')->where('user_id', $user->id)->lockForUpdate()->first();
            $next = [
                'enabled' => $data['enabled'],
                'weekdays' => json_encode(array_values($data['weekdays'])),
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'timezone' => 'Africa/Lome',
                'updated_at' => now(),
            ];
            DB::table('staff_access_windows')->updateOrInsert(['user_id' => $user->id],
                [...$next, 'created_at' => $existing?->created_at ?? now()]);
            $audit->record('staff.login_hours_changed', $user,
                before: $existing ? ['enabled' => (bool) $existing->enabled, 'weekdays' => json_decode($existing->weekdays, true),
                    'start_time' => $existing->start_time, 'end_time' => $existing->end_time] : null,
                after: ['enabled' => $data['enabled'], 'weekdays' => $data['weekdays'],
                    'start_time' => $data['start_time'], 'end_time' => $data['end_time']],
                reason: $data['reason'], actor: $actor);
        });
        return $this->show($user, app(StaffAccessPolicy::class));
    }
}
