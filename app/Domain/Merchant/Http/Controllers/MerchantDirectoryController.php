<?php

namespace App\Domain\Merchant\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Merchant\Exceptions\MerchantException;
use App\Domain\Merchant\Models\MerchantDirectoryProfile;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public directory of merchants accepting YEM, and the merchant's own directory profile.
 *
 * Only merchants who chose to be listed, whose account is active and who hold an active Pack Marchand appear.
 */
class MerchantDirectoryController
{
    /**
     * Public list, searchable by name or activity and filterable by city.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:120'],
        ]);

        $profiles = MerchantDirectoryProfile::query()
            ->where('listed', true)
            ->whereHas('merchant', fn (Builder $merchant) => $merchant
                ->where('status', UserStatus::Active)
                ->whereExists(fn ($packs) => $packs->from('user_packs')->whereColumn('user_packs.user_id', 'users.id')->where('family', 'MARCHAND')->where('status', 'active')))
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
                $query->where(fn (Builder $inner) => $inner->whereRaw('lower(display_name) like ?', [$term])->orWhereRaw('lower(activity) like ?', [$term]));
            })
            ->when($validated['city'] ?? null, fn (Builder $query, string $city) => $query->whereRaw('lower(city) = ?', [mb_strtolower($city)]))
            ->orderBy('display_name')
            ->limit(500)
            ->get();

        return response()->json(['data' => $profiles->map(fn (MerchantDirectoryProfile $profile): array => $profile->toPublicArray())->all()]);
    }

    public function show(Request $request): JsonResponse
    {
        $profile = MerchantDirectoryProfile::query()->find($this->merchant($request)->id);

        return response()->json(['data' => $profile === null ? null : [...$profile->toPublicArray(), 'listed' => $profile->listed]]);
    }

    /**
     * Create or update the directory profile, and choose whether to appear in the public directory.
     */
    public function update(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $merchant = $this->merchant($request);

        $validated = $request->validate([
            'listed' => ['required', 'boolean'],
            'display_name' => ['required', 'string', 'min:2', 'max:160'],
            'activity' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'public_phone' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $profile = MerchantDirectoryProfile::query()->firstOrNew(['user_id' => $merchant->id]);
        $wasListed = $profile->exists && $profile->listed;

        $profile->fill($validated);
        $profile->listed_at = $validated['listed'] ? ($wasListed ? $profile->listed_at : now()) : null;
        $profile->save();

        $auditLogger->record('merchant_directory.updated', $merchant, after: ['listed' => $profile->listed, 'display_name' => $profile->display_name], actor: $merchant);

        return response()->json(['data' => [...$profile->toPublicArray(), 'listed' => $profile->listed]]);
    }

    private function merchant(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $isMerchant = $user->status === UserStatus::Active
            && UserPack::query()->where('user_id', $user->id)->where('family', 'MARCHAND')->where('status', 'active')->exists();

        if (! $isMerchant) {
            throw MerchantException::notAMerchant();
        }

        return $user;
    }
}
