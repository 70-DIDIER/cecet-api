<?php

namespace App\Domain\Notification\Http\Controllers;

use App\Domain\Notification\Models\MemberNotification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class NotificationController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $validated = $request->validate([
            'unread' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = MemberNotification::query()
            ->where('user_id', $user->id)
            ->when(($validated['unread'] ?? false) === true, fn ($builder) => $builder->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $page = $query->paginate($validated['per_page'] ?? 30);

        return response()->json([
            'data' => collect($page->items())->map(fn (MemberNotification $notification): array => $this->serialize($notification))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'unread' => MemberNotification::query()->where('user_id', $user->id)->whereNull('read_at')->count(),
            ],
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $item = MemberNotification::query()
            ->where('user_id', $this->user($request)->id)
            ->whereKey($notification)
            ->firstOrFail();

        if ($item->read_at === null) {
            $item->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['data' => $this->serialize($item->refresh())]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $count = DB::table('member_notifications')
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => ['updated' => $count]]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function serialize(MemberNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'event' => $notification->event,
            'title' => $notification->title,
            'message' => $notification->message,
            'data' => $notification->data,
            'read_at' => $notification->read_at?->toIso8601ZuluString(),
            'created_at' => $notification->created_at->toIso8601ZuluString(),
        ];
    }
}
