<?php

namespace App\Domain\Identity\Http\Requests;

use App\Domain\Identity\Enums\PernumStatus;
use App\Domain\Identity\Enums\PersonType;
use App\Domain\Identity\PasswordPolicy;
use App\Domain\Settings\SettingsService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registration of a natural person (Pernum) or a legal person (Pernum Business + organization data), with the
 * commitment to the statutes and internal rules and the place and date of signature of the membership form.
 */
class RegisterMemberRequest extends FormRequest
{
    public const PHONE_FORMAT = '/^\+[1-9][0-9]{7,14}$/';

    public const PERNUM_FORMAT = '/^[A-Za-z0-9._-]{3,64}$/';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'pernum' => trim((string) $this->input('pernum')),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(SettingsService $settings, PasswordPolicy $passwordPolicy): array
    {
        $isLegal = $this->input('person_type') === PersonType::Legal->value;

        return [
            'person_type' => ['required', Rule::enum(PersonType::class)],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['required', 'string', 'regex:'.self::PHONE_FORMAT],
            'pernum' => [
                'required', 'string', 'regex:'.self::PERNUM_FORMAT,
                Rule::unique('pernum_identities', 'pernum')->where(fn ($query) => $query->where('status', '<>', PernumStatus::Revoked->value)),
            ],
            'password' => ['required', 'confirmed', $passwordPolicy->rule()],
            'city' => ['nullable', 'string', 'max:100'],
            'preferred_payment_method' => ['nullable', 'string', Rule::in($this->enabledPaymentMethods($settings))],
            'commitment_accepted' => ['accepted'],
            'signed_place' => ['required', 'string', 'max:120'],
            'signed_on' => ['required', 'date', 'before_or_equal:today'],
            'legal_name' => [Rule::requiredIf($isLegal), 'prohibited_unless:person_type,legal', 'string', 'max:200'],
            'legal_form' => [Rule::requiredIf($isLegal), 'prohibited_unless:person_type,legal', 'string', Rule::in($settings->array('organization.legal_forms'))],
            'rccm' => [Rule::requiredIf($isLegal && $settings->boolean('organization.rccm_required')), 'prohibited_unless:person_type,legal', 'nullable', 'string', 'max:60'],
            'nif' => [Rule::requiredIf($isLegal && $settings->boolean('organization.nif_required')), 'prohibited_unless:person_type,legal', 'nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return list<string>
     */
    private function enabledPaymentMethods(SettingsService $settings): array
    {
        return array_values(collect($settings->array('payment.methods'))
            ->filter(fn (mixed $method): bool => is_array($method) && ($method['enabled'] ?? false) === true)
            ->pluck('code')
            ->map(fn (mixed $code): string => (string) $code)
            ->all());
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'first_name' => $this->input('person_type') === PersonType::Legal->value ? 'prénom du représentant' : 'prénom',
            'last_name' => $this->input('person_type') === PersonType::Legal->value ? 'nom du représentant' : 'nom',
            'pernum' => 'Pernum',
            'legal_name' => 'raison sociale',
            'legal_form' => 'statut juridique',
            'commitment_accepted' => 'engagement',
            'signed_place' => 'lieu de signature',
            'signed_on' => 'date de signature',
        ];
    }
}
