<?php

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Http\Middleware\RequireSuperadminMtls;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Isolated SuperAdmin authentication. Private keys stay in browser-held files.
 * The upstream Nginx FastCGI server parameters, not HTTP headers, attest mTLS.
 */
class SuperadminSecurityController
{
    private function identity(Request $request): array
    {
        $fp = RequireSuperadminMtls::fingerprint($request);
        $user = User::find(config('superadmin.user_id'));
        abort_unless(
            $fp && $user && $user->isSuperadmin() && $user->status === UserStatus::Active,
            403, 'Identité SuperAdmin non autorisée.'
        );

        $profile = DB::table('superadmin_security_profiles')->where('user_id', $user->id)->first();
        if ($profile) {
            abort_unless(hash_equals($profile->cert_sha256, $fp), 403, 'Certificat SuperAdmin non autorisé.');
        } else {
            $bootstrap = config('superadmin.bootstrap_cert_sha256');
            abort_unless(strlen($bootstrap) === 64 && hash_equals($bootstrap, $fp), 403, 'Certificat initial non autorisé.');
        }

        return [$user, $fp, $profile];
    }

    private function respond(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status)->header('Cache-Control', 'no-store');
    }

    private function addresses(array $values): array
    {
        $emails = array_map(fn ($v) => mb_strtolower(trim($v)), $values);
        abort_unless(count($emails) === 3 && count(array_unique($emails)) === 3, 422, 'Trois adresses distinctes sont obligatoires.');

        return $emails;
    }

    private function savedAddresses(object $profile): array
    {
        return [$profile->email_1, $profile->email_2, $profile->email_3];
    }

    private function validateAddresses(Request $request): array
    {
        $data = $request->validate(['emails' => ['required', 'array', 'size:3'], 'emails.*' => ['required', 'email:rfc', 'max:255']]);

        return $this->addresses($data['emails']);
    }

    private function issue(User $user, string $fp, string $purpose, array $emails = [], bool $different = true, ?string $keyId = null, ?string $nonce = null): string
    {
        $codes = [];
        if ($emails) {
            foreach ($emails as $email) {
                do {
                    $code = (string) random_int(100000, 999999);
                } while ($different && in_array($code, $codes, true));
                $codes[] = $different ? $code : ($codes[0] ?? $code);
            }
        }

        $id = (string) Str::uuid();
        DB::table('superadmin_security_challenges')->insert([
            'id' => $id, 'user_id' => $user->id, 'cert_sha256' => $fp,
            'purpose' => $purpose, 'key_id' => $keyId,
            'email_addresses' => $emails ? json_encode($emails) : null,
            'code_hashes' => $emails ? json_encode(array_map(fn ($code) => $this->digest($code), $codes)) : null,
            'nonce' => $nonce, 'attempts' => 0,
            'expires_at' => now()->addMinutes(config('superadmin.otp_minutes', 5)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($emails as $index => $email) {
            Mail::raw(
                "Code de vérification CECT : {$codes[$index]}\nCe code expire dans 5 minutes. Ne le communiquez jamais.",
                function ($message) use ($email) {
                    $message->to($email)->subject('CECT — Validation de sécurité SuperAdmin');
                }
            );
        }

        Log::notice('superadmin.security_challenge_issued', ['user_id' => $user->id, 'purpose' => $purpose]);

        return $id;
    }

    private function digest(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function take(User $user, string $fp, string $purpose, string $id, array $codes): object
    {
        $result = DB::transaction(function () use ($user, $fp, $purpose, $id, $codes) {
            $challenge = DB::table('superadmin_security_challenges')->where('id', $id)->lockForUpdate()->first();
            if (! $challenge || $challenge->user_id !== $user->id || ! hash_equals($challenge->cert_sha256, $fp)
                || $challenge->purpose !== $purpose || $challenge->consumed_at
                || now()->greaterThanOrEqualTo($challenge->expires_at)
                || $challenge->attempts >= config('superadmin.max_attempts', 5)) {
                return null;
            }
            $hashes = json_decode($challenge->code_hashes ?? '[]', true);
            $valid = count($codes) === count($hashes);
            foreach ($hashes as $i => $hash) {
                $valid = hash_equals($hash, $this->digest((string) ($codes[$i] ?? ''))) && $valid;
            }
            DB::table('superadmin_security_challenges')->where('id', $id)->update([
                'attempts' => $challenge->attempts + 1,
                'consumed_at' => $valid ? now() : null,
                'updated_at' => now(),
            ]);

            return $valid ? $challenge : null;
        });
        abort_unless($result, 422, 'Code incorrect, expiré ou déjà utilisé.');

        return $result;
    }

    private function validatedKey(Request $request): string
    {
        $data = $request->validate(['public_key_pem' => ['required', 'string', 'max:5000']]);
        $pem = $data['public_key_pem'];
        $key = openssl_pkey_get_public($pem);
        $details = $key ? openssl_pkey_get_details($key) : false;
        abort_unless($details && $details['type'] === OPENSSL_KEYTYPE_RSA && $details['bits'] >= 3072
            && $details['bits'] <= 4096, 422, 'Clé RSA publique invalide (3072 bits minimum).');

        return $pem;
    }

    private function pendingKey(User $user, string $fp, string $pem): string
    {
        $id = (string) Str::uuid();
        DB::table('superadmin_access_keys')->insert([
            'id' => $id, 'user_id' => $user->id, 'cert_sha256' => $fp,
            'public_key_pem' => $pem, 'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function authenticated(Request $request, User $user, string $fp, string $keyId): void
    {
        abort_unless($request->hasSession(), 403, 'Session navigateur requise.');
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->forget('superadmin_pending_rotation');
        $request->session()->put('superadmin_verified_cert', $fp);
        $request->session()->put('superadmin_verified_key', $keyId);
        $user->forceFill(['last_login_at' => now()])->save();
        Log::notice('superadmin.login_success', ['user_id' => $user->id, 'key_id' => $keyId, 'cert_sha256' => $fp]);
    }

    private function requireVerified(Request $request, User $user, string $fp): void
    {
        abort_unless($request->user()?->id === $user->id && $request->hasSession()
            && hash_equals($fp, (string) $request->session()->get('superadmin_verified_cert', '')), 403);
        $keyId = $request->session()->get('superadmin_verified_key');
        abort_unless($keyId && DB::table('superadmin_access_keys')->where('id', $keyId)
            ->where('user_id', $user->id)->where('status', 'active')
            ->where('expires_at', '>', now())->exists(), 403);
    }

    public function status(Request $request): JsonResponse
    {
        [$user, $fp, $profile] = $this->identity($request);
        $active = DB::table('superadmin_access_keys')->where('user_id', $user->id)
            ->where('cert_sha256', $fp)->where('status', 'active')
            ->where('expires_at', '>', now())->exists();

        return $this->respond(['initialized' => (bool) $profile, 'has_active_key' => $active,
            'key_validity_days' => config('superadmin.key_days', 30)]);
    }

    public function enrollStart(Request $request): JsonResponse
    {
        [$user, $fp, $profile] = $this->identity($request);
        abort_if($profile, 409, 'Initialisation déjà effectuée.');
        $emails = $this->validateAddresses($request);

        return $this->respond(['challenge_id' => $this->issue($user, $fp, 'enroll', $emails)]);
    }

    public function enrollFinish(Request $request): JsonResponse
    {
        [$user, $fp, $profile] = $this->identity($request);
        abort_if($profile, 409);
        $data = $request->validate(['challenge_id' => ['required', 'uuid'], 'codes' => ['required', 'array', 'size:3'], 'codes.*' => ['required', 'digits:6']]);
        $pem = $this->validatedKey($request);
        $challenge = $this->take($user, $fp, 'enroll', $data['challenge_id'], $data['codes']);
        $emails = json_decode($challenge->email_addresses, true);

        DB::table('superadmin_security_profiles')->insert([
            'user_id' => $user->id, 'cert_sha256' => $fp,
            'email_1' => $emails[0], 'email_2' => $emails[1], 'email_3' => $emails[2],
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->respond(['key_id' => $this->pendingKey($user, $fp, $pem), 'activation_required' => true]);
    }

    public function keyChallenge(Request $request): JsonResponse
    {
        [$user, $fp] = $this->identity($request);
        $data = $request->validate(['key_id' => ['required', 'uuid']]);
        $key = DB::table('superadmin_access_keys')->where('id', $data['key_id'])
            ->where('user_id', $user->id)->where('cert_sha256', $fp)
            ->whereIn('status', ['pending', 'active'])->where('expires_at', '>', now())->first();
        abort_unless($key, 403, 'Clé inconnue, expirée ou révoquée.');
        $nonce = base64_encode(random_bytes(32));

        return $this->respond([
            'challenge_id' => $this->issue($user, $fp, 'proof', keyId: $key->id, nonce: $nonce),
            'nonce' => $nonce,
        ]);
    }

    public function keyProve(Request $request): JsonResponse
    {
        [$user, $fp, $profile] = $this->identity($request);
        abort_unless($profile, 403);
        $data = $request->validate(['challenge_id' => ['required', 'uuid'], 'signature' => ['required', 'string', 'max:2048']]);
        $challenge = DB::table('superadmin_security_challenges')->where('id', $data['challenge_id'])->first();
        abort_unless($challenge && $challenge->purpose === 'proof'
            && $challenge->user_id === $user->id && hash_equals($challenge->cert_sha256, $fp)
            && ! $challenge->consumed_at && now()->lessThan($challenge->expires_at), 403);
        $key = DB::table('superadmin_access_keys')->where('id', $challenge->key_id)
            ->where('user_id', $user->id)->where('cert_sha256', $fp)
            ->whereIn('status', ['pending', 'active'])->where('expires_at', '>', now())->first();
        abort_unless($key, 403);
        abort_if($challenge->attempts >= config('superadmin.max_attempts', 5), 403, 'Trop de tentatives.');
        $sig = base64_decode($data['signature'], true);
        $message = "CECT_SUPERADMIN_V1\n{$challenge->id}\n{$challenge->nonce}";
        $valid = $sig !== false && openssl_verify($message, $sig, $key->public_key_pem, OPENSSL_ALGO_SHA256) === 1;
        if (! $valid) {
            DB::table('superadmin_security_challenges')->where('id', $challenge->id)
                ->where('attempts', '<', config('superadmin.max_attempts', 5))->increment('attempts');
            abort(403, 'Signature de clé invalide.');
        }
        abort_if($challenge->attempts >= config('superadmin.max_attempts', 5), 403);
        // Conditional atomic consume closes the replay window for signed challenges.
        $changed = DB::table('superadmin_security_challenges')->where('id', $challenge->id)
            ->whereNull('consumed_at')->where('expires_at', '>', now())->update(['consumed_at' => now()]);
        abort_unless($changed === 1, 403, 'Preuve déjà utilisée.');

        if ($key->status === 'active') {
            $otp = $this->issue($user, $fp, 'login', $this->savedAddresses($profile), false, $key->id);
            return $this->respond(['status' => 'login_otp_required', 'challenge_id' => $otp]);
        }

        DB::transaction(function () use ($user, $fp, $key): void {
            $pending = DB::table('superadmin_access_keys')->where('id', $key->id)->lockForUpdate()->first();
            abort_unless($pending && $pending->status === 'pending' && now()->lessThan($pending->expires_at), 403);
            DB::table('superadmin_access_keys')->where('user_id', $user->id)
                ->where('cert_sha256', $fp)->where('status', 'active')
                ->update(['status' => 'revoked', 'updated_at' => now()]);
            DB::table('superadmin_access_keys')->where('id', $key->id)->update([
                'status' => 'active', 'activated_at' => now(),
                'expires_at' => now()->addDays(max(1, min(90, (int) config('superadmin.key_days', 30)))),
                'updated_at' => now(),
            ]);
        });

        $this->authenticated($request, $user, $fp, $key->id);

        return $this->respond(['status' => 'authenticated']);
    }

    public function loginVerify(Request $request): JsonResponse
    {
        [$user, $fp, $profile] = $this->identity($request);
        abort_unless($profile, 403);
        $data = $request->validate(['challenge_id' => ['required', 'uuid'], 'code' => ['required', 'digits:6']]);
        $challenge = $this->take($user, $fp, 'login', $data['challenge_id'], [$data['code'], $data['code'], $data['code']]);
        abort_unless(DB::table('superadmin_access_keys')->where('id', $challenge->key_id)
            ->where('status', 'active')->where('expires_at', '>', now())->exists(), 403);
        abort_unless($request->hasSession(), 403);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->forget(['superadmin_verified_key', 'superadmin_verified_cert']);
        $request->session()->put('superadmin_pending_rotation', $challenge->key_id);
        $rotation = $this->issue($user, $fp, 'rotate', $this->savedAddresses($profile), true, $challenge->key_id);

        return $this->respond(['status' => 'rotation_otp_required', 'challenge_id' => $rotation]);
    }

    public function rotateFinish(Request $request): JsonResponse
    {
        [$user, $fp] = $this->identity($request);
        abort_unless($request->hasSession() && $request->user()?->id === $user->id
            && $request->session()->has('superadmin_pending_rotation'), 403);
        $data = $request->validate(['challenge_id' => ['required', 'uuid'], 'codes' => ['required', 'array', 'size:3'], 'codes.*' => ['required', 'digits:6']]);
        $pem = $this->validatedKey($request);
        $challenge = $this->take($user, $fp, 'rotate', $data['challenge_id'], $data['codes']);
        abort_unless($challenge->key_id === $request->session()->get('superadmin_pending_rotation'), 403);

        return $this->respond(['key_id' => $this->pendingKey($user, $fp, $pem), 'activation_required' => true]);
    }

    public function recoverStart(Request $request): JsonResponse
    {
        [$user, $fp, $profile] = $this->identity($request);
        abort_unless($profile, 403);
        return $this->respond(['challenge_id' => $this->issue($user, $fp, 'recover', $this->savedAddresses($profile))]);
    }

    public function recoverFinish(Request $request): JsonResponse
    {
        [$user, $fp, $profile] = $this->identity($request);
        abort_unless($profile, 403);
        $data = $request->validate(['challenge_id' => ['required', 'uuid'], 'codes' => ['required', 'array', 'size:3'], 'codes.*' => ['required', 'digits:6']]);
        $pem = $this->validatedKey($request);
        $this->take($user, $fp, 'recover', $data['challenge_id'], $data['codes']);
        return $this->respond(['key_id' => $this->pendingKey($user, $fp, $pem), 'activation_required' => true]);
    }

    public function security(Request $request): JsonResponse
    {
        [$user, $fp, $profile] = $this->identity($request);
        $this->requireVerified($request, $user, $fp);
        return $this->respond(['emails' => $this->savedAddresses($profile), 'key_validity_days' => config('superadmin.key_days', 30)]);
    }

    public function emailsStart(Request $request): JsonResponse
    {
        [$user, $fp] = $this->identity($request);
        $this->requireVerified($request, $user, $fp);
        $emails = $this->validateAddresses($request);
        return $this->respond(['challenge_id' => $this->issue($user, $fp, 'emails', $emails)]);
    }

    public function emailsFinish(Request $request): JsonResponse
    {
        [$user, $fp] = $this->identity($request);
        $this->requireVerified($request, $user, $fp);
        $data = $request->validate(['challenge_id' => ['required', 'uuid'], 'codes' => ['required', 'array', 'size:3'], 'codes.*' => ['required', 'digits:6']]);
        $challenge = $this->take($user, $fp, 'emails', $data['challenge_id'], $data['codes']);
        $emails = json_decode($challenge->email_addresses, true);
        DB::table('superadmin_security_profiles')->where('user_id', $user->id)->update([
            'email_1' => $emails[0], 'email_2' => $emails[1], 'email_3' => $emails[2],
            'updated_at' => now(),
        ]);
        Log::warning('superadmin.security_emails_changed', ['user_id' => $user->id]);
        return $this->respond(['status' => 'updated']);
    }
}
