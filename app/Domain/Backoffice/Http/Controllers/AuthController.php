<?php

namespace App\Domain\Backoffice\Http\Controllers;

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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Sign-in of the internal accounts to the back-office: password, then the code sent by e-mail, then a cookie session.
 * Members never get in: their credentials are refused before any code is sent.
 */
class AuthController
{
    private const CHALLENGE_KEY = 'backoffice.challenge';

    public function __construct(private readonly SecurityEventLogger $securityLogger) {}

    public function showLogin(): View|RedirectResponse
    {
        $user = Auth::guard('web')->user();

        if ($user instanceof User && $user->isStaff() && $user->status === UserStatus::Active) {
            return redirect()->route('backoffice.dashboard');
        }

        return view('backoffice.auth.login');
    }

    public function login(Request $request, AuthenticateUser $authenticate): RedirectResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        $identifier = mb_strtolower(trim($validated['identifier']));

        try {
            if (! User::query()->where('email', $identifier)->where('kind', UserKind::Staff)->exists()) {
                // Same answer and same duration whether the account exists, is a member or does not exist.
                Hash::check($validated['password'], Hash::make('timing-equalizer'));
                $this->securityLogger->record('backoffice.login_rejected', SecuritySeverity::Warning, identifier: $identifier);

                throw IdentityException::invalidCredentials();
            }

            $result = $authenticate->handle($identifier, $validated['password']);
        } catch (ApiException $exception) {
            return back()->withInput(['identifier' => $validated['identifier']])->withErrors(['identifier' => $exception->getMessage()]);
        }

        if ($result->challenge === null) {
            return back()->withErrors(['identifier' => 'La connexion au back-office exige un code de confirmation.']);
        }

        $request->session()->put(self::CHALLENGE_KEY, $result->challenge->id);

        return redirect()->route('backoffice.otp');
    }

    public function showOtp(Request $request): View|RedirectResponse
    {
        $challenge = $this->pendingChallenge($request);

        if ($challenge === null) {
            return redirect()->route('backoffice.login');
        }

        return view('backoffice.auth.otp', ['challenge' => (new OtpChallengeResource($challenge))->resolve($request)]);
    }

    public function verifyOtp(Request $request, CompleteLogin $completeLogin): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'digits:6']], ['code.digits' => 'Le code contient 6 chiffres.']);
        $challenge = $this->pendingChallenge($request);

        if ($challenge === null) {
            return redirect()->route('backoffice.login')->withErrors(['identifier' => 'Votre tentative de connexion a expiré : recommencez.']);
        }

        try {
            $user = $completeLogin->handle($challenge->id, $validated['code']);
        } catch (ApiException $exception) {
            return back()->withErrors(['code' => $exception->getMessage()]);
        }

        if (! $user->isStaff() || $user->status !== UserStatus::Active) {
            $request->session()->forget(self::CHALLENGE_KEY);

            return redirect()->route('backoffice.login')->withErrors(['identifier' => 'Ce compte n\'a pas accès au back-office.']);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->forget(self::CHALLENGE_KEY);
        $user->forceFill(['last_login_at' => now()])->save();

        $this->securityLogger->record('auth.login_succeeded', userId: $user->id, metadata: ['mode' => 'backoffice']);

        return redirect()->intended(route('backoffice.dashboard'));
    }

    public function resendOtp(Request $request, ResendOtp $resend): RedirectResponse
    {
        $challenge = $this->pendingChallenge($request);

        if ($challenge === null) {
            return redirect()->route('backoffice.login');
        }

        try {
            $request->session()->put(self::CHALLENGE_KEY, $resend->handle($challenge->id)->id);
        } catch (ApiException $exception) {
            return back()->withErrors(['code' => $exception->getMessage()]);
        }

        return back()->with('status', 'Un nouveau code vient d\'être envoyé.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $user = Auth::guard('web')->user();

        if ($user instanceof User) {
            $this->securityLogger->record('auth.logout', userId: $user->id, metadata: ['mode' => 'backoffice']);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('backoffice.login')->with('status', 'Vous êtes déconnecté.');
    }

    private function pendingChallenge(Request $request): ?OtpChallenge
    {
        $id = $request->session()->get(self::CHALLENGE_KEY);

        return is_string($id) ? OtpChallenge::query()->whereKey($id)->whereNull('consumed_at')->first() : null;
    }
}
