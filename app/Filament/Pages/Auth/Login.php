<?php

namespace App\Filament\Pages\Auth;

use App\Domain\Audit\Enums\SecuritySeverity;
use App\Domain\Audit\SecurityEventLogger;
use App\Domain\Identity\Actions\AuthenticateUser;
use App\Domain\Identity\Actions\CompleteLogin;
use App\Domain\Identity\Actions\ResendOtp;
use App\Domain\Identity\Enums\UserKind;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Http\Resources\OtpChallengeResource;
use App\Domain\Identity\Models\OtpChallenge;
use App\Domain\Shared\Exceptions\ApiException;
use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Sign-in of the internal accounts: e-mail and password, then the code sent by e-mail. Members never get in:
 * their credentials are refused before any code is sent.
 */
class Login extends BaseLogin
{
    #[Locked]
    public ?string $challengeId = null;

    #[Locked]
    public ?string $sentTo = null;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getEmailFormComponent()->visible(fn (): bool => $this->challengeId === null),
            $this->getPasswordFormComponent()->visible(fn (): bool => $this->challengeId === null),
            $this->getCodeFormComponent()->visible(fn (): bool => $this->challengeId !== null),
        ]);
    }

    protected function getCodeFormComponent(): Component
    {
        return TextInput::make('code')
            ->label('Code de vérification')
            ->numeric()
            ->length(6)
            ->autocomplete('one-time-code')
            ->required(fn (): bool => $this->challengeId !== null)
            ->autofocus();
    }

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(8);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        return $this->challengeId === null ? $this->startLogin() : $this->finishLogin();
    }

    private function startLogin(): null
    {
        $data = $this->form->getState();
        $identifier = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');

        try {
            if (! User::query()->where('email', $identifier)->where('kind', UserKind::Staff)->exists()) {
                // Same answer and same duration whether the account exists, is a member or does not exist.
                Hash::check($password, Hash::make('timing-equalizer'));
                app(SecurityEventLogger::class)->record('backoffice.login_rejected', SecuritySeverity::Warning, identifier: $identifier);

                throw IdentityException::invalidCredentials();
            }

            $result = app(AuthenticateUser::class)->handle($identifier, $password);
        } catch (ApiException $exception) {
            throw ValidationException::withMessages(['data.email' => $exception->getMessage()]);
        }

        if ($result->challenge === null) {
            throw ValidationException::withMessages(['data.email' => 'La connexion exige un code de confirmation.']);
        }

        $this->rememberChallenge($result->challenge);
        $this->form->fill();

        return null;
    }

    private function finishLogin(): LoginResponse
    {
        $data = $this->form->getState();
        $challenge = OtpChallenge::query()->whereKey($this->challengeId)->whereNull('consumed_at')->first();

        if ($challenge === null) {
            $this->resetChallenge();

            throw ValidationException::withMessages(['data.email' => 'Votre tentative de connexion a expiré : recommencez.']);
        }

        try {
            $user = app(CompleteLogin::class)->handle($challenge->id, (string) $data['code']);
        } catch (ApiException $exception) {
            throw ValidationException::withMessages(['data.code' => $exception->getMessage()]);
        }

        if (! $user->canAccessPanel(Filament::getPanel('admin')) || $user->status !== UserStatus::Active) {
            $this->resetChallenge();

            throw ValidationException::withMessages(['data.email' => 'Ce compte n\'a pas accès au back-office.']);
        }

        Filament::auth()->login($user);
        session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        app(SecurityEventLogger::class)->record('auth.login_succeeded', userId: $user->id, metadata: ['mode' => 'filament']);

        return app(LoginResponse::class);
    }

    public function resendCodeAction(): Action
    {
        return Action::make('resendCode')
            ->label('Renvoyer le code')
            ->link()
            ->visible(fn (): bool => $this->challengeId !== null)
            ->action(function (): void {
                try {
                    $this->rememberChallenge(app(ResendOtp::class)->handle((string) $this->challengeId));
                    Notification::make()->title('Un nouveau code vient d\'être envoyé.')->success()->send();
                } catch (ApiException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                }
            });
    }

    public function changeAccountAction(): Action
    {
        return Action::make('changeAccount')
            ->label('Changer de compte')
            ->link()
            ->color('gray')
            ->visible(fn (): bool => $this->challengeId !== null)
            ->action(function (): void {
                $this->resetChallenge();
                $this->form->fill();
            });
    }

    protected function getFormActions(): array
    {
        return [$this->getAuthenticateFormAction(), $this->resendCodeAction(), $this->changeAccountAction()];
    }

    protected function getAuthenticateFormAction(): Action
    {
        return Action::make('authenticate')
            ->label(fn (): string => $this->challengeId === null ? 'Continuer' : 'Valider le code')
            ->submit('authenticate');
    }

    public function getHeading(): string|Htmlable
    {
        return $this->challengeId === null ? 'Back-office CECT' : 'Vérifiez votre identité';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->challengeId === null
            ? 'Connectez-vous avec votre compte interne.'
            : "Un code à 6 chiffres a été envoyé à {$this->sentTo}.";
    }

    private function rememberChallenge(OtpChallenge $challenge): void
    {
        $this->challengeId = $challenge->id;
        $this->sentTo = (new OtpChallengeResource($challenge))->resolve(request())['sent_to'] ?? null;
    }

    private function resetChallenge(): void
    {
        $this->challengeId = null;
        $this->sentTo = null;
    }
}
