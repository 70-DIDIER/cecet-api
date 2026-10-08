<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Enums\PernumKind;
use App\Domain\Identity\Enums\PernumStatus;
use App\Domain\Identity\Enums\PersonType;
use App\Domain\Identity\Enums\UserKind;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\OtpChallenge;
use App\Domain\Identity\Otp\OtpService;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Registers a natural or legal person. The account waits for email verification, then for the
 * membership payment: the client can never activate it by itself.
 */
class RegisterMember
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly AuditLogger $auditLogger,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated registration payload
     * @return array{user: User, challenge: OtpChallenge}
     */
    public function handle(array $data): array
    {
        $personType = PersonType::from((string) $data['person_type']);

        try {
            return DB::transaction(function () use ($data, $personType): array {
                $user = User::query()->create([
                    'kind' => UserKind::Member,
                    'person_type' => $personType,
                    'member_number' => $this->nextMemberNumber(),
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'city' => $data['city'] ?? null,
                    'preferred_payment_method' => $data['preferred_payment_method'] ?? null,
                    'commitment_accepted_at' => now(),
                    'commitment_version' => $this->settings->string('membership.commitment_version'),
                    'signed_place' => $data['signed_place'],
                    'signed_on' => $data['signed_on'],
                    'status' => UserStatus::PendingEmailVerification,
                    'password' => $data['password'],
                ]);

                if ($personType === PersonType::Legal) {
                    $user->organization()->create([
                        'legal_name' => $data['legal_name'],
                        'legal_form' => $data['legal_form'],
                        'rccm' => $data['rccm'] ?? null,
                        'nif' => $data['nif'] ?? null,
                    ]);
                }

                $user->pernumIdentities()->create([
                    'pernum' => $data['pernum'],
                    'kind' => $personType === PersonType::Legal ? PernumKind::Business : PernumKind::Personal,
                    'status' => PernumStatus::Declared,
                ]);

                $this->auditLogger->record(
                    action: 'members.registered',
                    subject: $user,
                    after: [
                        'person_type' => $personType->value,
                        'member_number' => $user->member_number,
                        'email' => $user->email,
                        'pernum' => $data['pernum'],
                        'legal_name' => $data['legal_name'] ?? null,
                        'commitment_version' => $user->commitment_version,
                        'signed_place' => $data['signed_place'],
                        'signed_on' => $data['signed_on'],
                    ],
                    actor: $user,
                );

                return ['user' => $user, 'challenge' => $this->otp->issue($user, OtpPurpose::EmailVerification)];
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException('ACCOUNT_ALREADY_EXISTS', 'Un compte existe déjà avec cet email ou ce Pernum.', 409);
        }
    }

    private function nextMemberNumber(): string
    {
        $next = DB::scalar("select nextval('member_number_seq')");

        return 'CECT-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
