<?php

namespace App\Filament\Support;

use App\Domain\Identity\Actions\ConfirmStepUp;
use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Http\Resources\OtpChallengeResource;
use App\Domain\Identity\Models\OtpChallenge;
use App\Domain\Identity\Otp\OtpService;
use App\Domain\Shared\Exceptions\ApiException;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * Confirmation by e-mail code before a sensitive action. An action adds `StepUp::fields()` to its form, calls
 * `StepUp::issue()` when it opens and `StepUp::confirm()` first thing when it is submitted: the code is only asked
 * when no confirmation is still valid, and the action stays open if the code is wrong.
 */
final class StepUp
{
    private const CHALLENGE_KEY = 'filament.step_up_challenge';

    public static function active(): bool
    {
        return (int) session(ConfirmStepUp::SESSION_KEY, 0) > now()->getTimestamp();
    }

    /**
     * @return array<int, TextInput>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('step_up_code')
                ->label('Code de confirmation')
                ->helperText(fn (): string => 'Action sensible : saisissez le code à 6 chiffres envoyé à '.self::maskedAddress().'.')
                ->inputMode('numeric')
                ->length(6)
                ->visible(fn (): bool => ! self::active())
                ->required(fn (): bool => ! self::active())
                ->autocomplete('one-time-code'),
        ];
    }

    public static function issue(): void
    {
        if (self::active()) {
            return;
        }

        try {
            $challenge = app(OtpService::class)->issue(self::user(), OtpPurpose::StepUp);
        } catch (ApiException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        session()->put(self::CHALLENGE_KEY, $challenge->id);
    }

    /**
     * Verifies the code when one is required; otherwise does nothing. Halts the action on a wrong code.
     *
     * @param  array<string, mixed>  $data
     */
    public static function confirm(Action $action, array $data): void
    {
        if (self::active()) {
            return;
        }

        $challengeId = session(self::CHALLENGE_KEY);

        if (! is_string($challengeId)) {
            Notification::make()->title('Aucun code envoyé : rouvrez l\'action pour en recevoir un.')->danger()->send();
            $action->halt();

            return;
        }

        try {
            app(ConfirmStepUp::class)->handle(self::user(), null, session()->driver(), $challengeId, (string) ($data['step_up_code'] ?? ''));
        } catch (ApiException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
            $action->halt();

            return;
        }

        session()->forget(self::CHALLENGE_KEY);
    }

    private static function maskedAddress(): string
    {
        $id = session(self::CHALLENGE_KEY);
        $challenge = is_string($id) ? OtpChallenge::query()->whereKey($id)->first() : null;

        return $challenge === null ? 'votre adresse e-mail' : (string) (new OtpChallengeResource($challenge))->resolve(request())['sent_to'];
    }

    private static function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
