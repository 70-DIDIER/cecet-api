<?php

namespace App\Domain\Backoffice\Http\Controllers;

use App\Domain\Identity\Actions\ConfirmStepUp;
use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Http\Resources\OtpChallengeResource;
use App\Domain\Identity\Models\OtpChallenge;
use App\Domain\Identity\Otp\OtpService;
use App\Domain\Shared\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Confirmation by e-mail code before a sensitive action (validations, exports, settings…).
 */
class StepUpController
{
    private const CHALLENGE_KEY = 'backoffice.step_up_challenge';

    public function show(Request $request): View
    {
        $id = $request->session()->get(self::CHALLENGE_KEY);
        $challenge = is_string($id) ? OtpChallenge::query()->whereKey($id)->whereNull('consumed_at')->first() : null;

        return view('backoffice.auth.step-up', [
            'challenge' => $challenge === null ? null : (new OtpChallengeResource($challenge))->resolve($request),
            'return' => $this->safeReturn($request->query('return')),
        ]);
    }

    public function send(Request $request, OtpService $otp): RedirectResponse
    {
        $user = $this->user($request);

        try {
            $request->session()->put(self::CHALLENGE_KEY, $otp->issue($user, OtpPurpose::StepUp)->id);
        } catch (ApiException $exception) {
            return back()->withErrors(['code' => $exception->getMessage()]);
        }

        return redirect()->route('backoffice.step-up', ['return' => $this->safeReturn($request->input('return'))]);
    }

    public function verify(Request $request, ConfirmStepUp $confirm): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'digits:6']], ['code.digits' => 'Le code contient 6 chiffres.']);
        $challengeId = $request->session()->get(self::CHALLENGE_KEY);

        if (! is_string($challengeId)) {
            return redirect()->route('backoffice.step-up', ['return' => $this->safeReturn($request->input('return'))]);
        }

        try {
            $confirm->handle($this->user($request), null, $request->session(), $challengeId, $validated['code']);
        } catch (ApiException $exception) {
            return back()->withErrors(['code' => $exception->getMessage()]);
        }

        $request->session()->forget(self::CHALLENGE_KEY);

        return redirect($this->safeReturn($request->input('return')))->with('status', 'Identité confirmée : vous pouvez effectuer l\'action sensible.');
    }

    /**
     * Only a path inside the back-office is accepted as a destination (no open redirect).
     */
    private function safeReturn(mixed $value): string
    {
        $fallback = route('backoffice.dashboard');

        if (! is_string($value) || ! str_starts_with($value, '/backoffice') || str_starts_with($value, '//') || str_contains($value, '\\')) {
            return $fallback;
        }

        return $value;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
