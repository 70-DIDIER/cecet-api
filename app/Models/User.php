<?php

namespace App\Models;

use App\Domain\Identity\Enums\PersonType;
use App\Domain\Identity\Enums\UserKind;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\PernumIdentity;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Account of a member (natural or legal person) or of an internal staff member.
 *
 * @property string $id
 * @property UserKind $kind
 * @property PersonType|null $person_type
 * @property string|null $member_number
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string|null $phone
 * @property string|null $city
 * @property string|null $preferred_payment_method
 * @property Carbon|null $commitment_accepted_at
 * @property string|null $commitment_version
 * @property string|null $signed_place
 * @property Carbon|null $signed_on
 * @property UserStatus $status
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property Carbon|null $last_login_at
 * @property Carbon|null $suspended_at
 * @property string|null $status_before_suspension
 * @property-read string $full_name
 * @property-read Organization|null $organization
 */
#[Fillable(['kind', 'person_type', 'member_number', 'first_name', 'last_name', 'email', 'phone', 'city', 'preferred_payment_method', 'commitment_accepted_at', 'commitment_version', 'signed_place', 'signed_on', 'status', 'email_verified_at', 'password', 'last_login_at', 'suspended_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUlids, Notifiable;

    /**
     * Guard used for roles and permissions, whatever the authentication guard of the request.
     */
    protected string $guard_name = 'web';

    /**
     * @return HasOne<Organization, $this>
     */
    public function organization(): HasOne
    {
        return $this->hasOne(Organization::class);
    }

    /**
     * @return HasMany<PernumIdentity, $this>
     */
    public function pernumIdentities(): HasMany
    {
        return $this->hasMany(PernumIdentity::class);
    }

    public function isStaff(): bool
    {
        return $this->kind === UserKind::Staff;
    }

    public function isMember(): bool
    {
        return $this->kind === UserKind::Member;
    }

    public function isSuperadmin(): bool
    {
        return $this->isStaff() && $this->hasRole('superadmin');
    }

    /**
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->first_name.' '.$this->last_name));
    }

    /**
     * Emails are stored lowercase so that uniqueness is case-insensitive.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value): string => mb_strtolower(trim($value)));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => UserKind::class,
            'person_type' => PersonType::class,
            'status' => UserStatus::class,
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'suspended_at' => 'datetime',
            'commitment_accepted_at' => 'datetime',
            'signed_on' => 'date',
            'password' => 'hashed',
        ];
    }
}
