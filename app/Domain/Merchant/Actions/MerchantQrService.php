<?php

namespace App\Domain\Merchant\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Enums\EndOfTermPolicy;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Identity\Enums\PernumKind;
use App\Domain\Identity\Enums\PernumStatus;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Merchant\Exceptions\MerchantException;
use App\Domain\Merchant\Models\MerchantQrCode;
use App\Domain\Subscription\Enums\UserPackStatus;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Merchant QR codes: issued for an active Pack Marchand, revocable and rotatable (leak, change of Business Pernum).
 *
 * The QR only carries an opaque random token. Resolving it reloads everything from the server: the merchant, its
 * pack, and its current Pernum. A Pernum written in a forged QR is never trusted.
 */
class MerchantQrService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function issue(User $merchant, UserPack $pack, ?string $outletLabel): MerchantQrCode
    {
        $this->assertUsableMerchantPack($merchant, $pack);
        $this->merchantPernum($merchant);

        return DB::transaction(function () use ($merchant, $pack, $outletLabel): MerchantQrCode {
            $qr = MerchantQrCode::query()->create([
                'user_id' => $merchant->id,
                'user_pack_id' => $pack->id,
                'token' => $this->newToken(),
                'outlet_label' => $outletLabel,
                'version' => 1,
                'status' => MerchantQrCode::ACTIVE,
            ]);

            $this->auditLogger->record('merchant_qr.issued', $qr, after: ['outlet_label' => $outletLabel, 'version' => 1], actor: $merchant);

            return $qr;
        });
    }

    /**
     * Replaces a QR by a new token (new version); the old one stops resolving immediately.
     */
    public function rotate(User $actor, MerchantQrCode $qr, string $reason): MerchantQrCode
    {
        return DB::transaction(function () use ($actor, $qr, $reason): MerchantQrCode {
            $qr = MerchantQrCode::query()->lockForUpdate()->findOrFail($qr->id);

            if (! $qr->isActive()) {
                throw MerchantException::qrNotFound();
            }

            $qr->forceFill(['status' => MerchantQrCode::REVOKED, 'revoked_at' => now(), 'revocation_reason' => $reason])->save();

            $next = MerchantQrCode::query()->create([
                'user_id' => $qr->user_id,
                'user_pack_id' => $qr->user_pack_id,
                'token' => $this->newToken(),
                'outlet_label' => $qr->outlet_label,
                'version' => $qr->version + 1,
                'status' => MerchantQrCode::ACTIVE,
                'replaces_id' => $qr->id,
            ]);

            $this->auditLogger->record('merchant_qr.rotated', $next, before: ['qr_id' => $qr->id, 'version' => $qr->version], after: ['version' => $next->version], reason: $reason, actor: $actor);

            return $next;
        });
    }

    public function revoke(User $actor, MerchantQrCode $qr, string $reason): MerchantQrCode
    {
        return DB::transaction(function () use ($actor, $qr, $reason): MerchantQrCode {
            $qr = MerchantQrCode::query()->lockForUpdate()->findOrFail($qr->id);

            if ($qr->isActive()) {
                $qr->forceFill(['status' => MerchantQrCode::REVOKED, 'revoked_at' => now(), 'revocation_reason' => $reason])->save();
                $this->auditLogger->record('merchant_qr.revoked', $qr, before: ['status' => MerchantQrCode::ACTIVE], after: ['status' => MerchantQrCode::REVOKED], reason: $reason, actor: $actor);
            }

            return $qr;
        });
    }

    /**
     * Resolves a scanned token into the merchant to pay, as known by the server right now.
     *
     * @return array{qr: MerchantQrCode, merchant: User, pack: UserPack, pernum: string}
     */
    public function resolve(string $token): array
    {
        $qr = MerchantQrCode::query()->with(['merchant.organization', 'userPack.version'])->where('token', $token)->first();

        if ($qr === null || ! $qr->isActive()) {
            throw MerchantException::qrNotFound();
        }

        try {
            $this->assertUsableMerchantPack($qr->merchant, $qr->userPack);
            $pernum = $this->merchantPernum($qr->merchant);
        } catch (MerchantException) {
            throw MerchantException::merchantUnavailable();
        }

        return ['qr' => $qr, 'merchant' => $qr->merchant, 'pack' => $qr->userPack, 'pernum' => $pernum];
    }

    /**
     * The Pernum on which the merchant receives YEM: its Business Pernum, or failing that its main active Pernum.
     */
    public function merchantPernum(User $merchant): string
    {
        $pernum = $merchant->pernumIdentities()
            ->whereIn('status', [PernumStatus::Declared, PernumStatus::Verified])
            ->orderByRaw('CASE WHEN kind = ? THEN 0 ELSE 1 END', [PernumKind::Business->value])
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [PernumStatus::Verified->value])
            ->latest()
            ->value('pernum');

        return is_string($pernum) && $pernum !== '' ? $pernum : throw MerchantException::businessPernumRequired();
    }

    public function assertUsableMerchantPack(User $merchant, UserPack $pack): void
    {
        $pack->loadMissing('version');
        $termEnded = $pack->expires_at !== null && $pack->expires_at->isPast() && $pack->version->end_of_term === EndOfTermPolicy::Expire;

        if ($merchant->status !== UserStatus::Active || $pack->user_id !== $merchant->id || $pack->family !== PackFamily::Marchand
            || $pack->status !== UserPackStatus::Active || $termEnded) {
            throw MerchantException::notAMerchant();
        }
    }

    private function newToken(): string
    {
        return Str::random(40);
    }
}
