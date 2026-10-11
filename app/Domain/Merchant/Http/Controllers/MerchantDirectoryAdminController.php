<?php

namespace App\Domain\Merchant\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Merchant\Models\MerchantDirectoryProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Back-office moderation of the public merchant directory.
 *
 * Merchants remain the owners of their profile, while administrators may correct public information or
 * temporarily hide a listing. Every change is audited.
 */
class MerchantDirectoryAdminController
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:120'],
            'listed' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $profiles = MerchantDirectoryProfile::query()
            ->with('merchant.organization')
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
                $query->where(fn (Builder $inner) => $inner
                    ->whereRaw('lower(display_name) like ?', [$term])
                    ->orWhereRaw('lower(activity) like ?', [$term])
                    ->orWhereHas('merchant', fn (Builder $merchant) => $merchant
                        ->whereRaw('lower(email) like ?', [$term])
                        ->orWhereRaw("lower(coalesce(first_name, '') || ' ' || coalesce(last_name, '')) like ?", [$term])));
            })
            ->when($validated['city'] ?? null, fn (Builder $query, string $city) => $query->whereRaw('lower(city) = ?', [mb_strtolower($city)]))
            ->when(array_key_exists('listed', $validated), fn (Builder $query) => $query->where('listed', (bool) $validated['listed']))
            ->orderBy('display_name')
            ->limit($validated['per_page'] ?? 100)
            ->get();

        return response()->json(['data' => $profiles->map(fn (MerchantDirectoryProfile $profile): array => $this->resource($profile))->all()]);
    }

    public function update(Request $request, User $user, AuditLogger $auditLogger): JsonResponse
    {
        $profile = MerchantDirectoryProfile::query()->where('user_id', $user->id)->firstOrFail();

        $validated = $request->validate([
            'listed' => ['required', 'boolean'],
            'display_name' => ['required', 'string', 'min:2', 'max:160'],
            'activity' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'public_phone' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $before = [
            'listed' => $profile->listed,
            'display_name' => $profile->display_name,
            'activity' => $profile->activity,
            'city' => $profile->city,
            'address' => $profile->address,
            'public_phone' => $profile->public_phone,
            'description' => $profile->description,
        ];

        $wasListed = $profile->listed;
        $profile->fill(collect($validated)->except('reason')->all());
        $profile->listed_at = $validated['listed'] ? ($wasListed ? $profile->listed_at : now()) : null;
        $profile->save();

        $auditLogger->record(
            'merchant_directory.admin_updated',
            $profile,
            before: $before,
            after: [
                'listed' => $profile->listed,
                'display_name' => $profile->display_name,
                'activity' => $profile->activity,
                'city' => $profile->city,
                'address' => $profile->address,
                'public_phone' => $profile->public_phone,
                'description' => $profile->description,
            ],
            reason: $validated['reason'],
        );

        return response()->json(['data' => $this->resource($profile->fresh(['merchant.organization']))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(MerchantDirectoryProfile $profile): array
    {
        $merchant = $profile->merchant;

        return [
            'user_id' => $profile->user_id,
            'listed' => $profile->listed,
            'display_name' => $profile->display_name,
            'activity' => $profile->activity,
            'city' => $profile->city,
            'address' => $profile->address,
            'public_phone' => $profile->public_phone,
            'description' => $profile->description,
            'listed_at' => $profile->listed_at?->toIso8601String(),
            'updated_at' => $profile->updated_at?->toIso8601String(),
            'merchant' => [
                'id' => $merchant->id,
                'email' => $merchant->email,
                'name' => $merchant->organization?->legal_name ?: $merchant->full_name,
                'status' => $merchant->status->value,
                'member_number' => $merchant->member_number,
            ],
        ];
    }
}
