<?php

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Actions\EstablishAuthentication;
use App\Domain\Identity\Enums\UserKind;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\StaffAccessPolicy;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Staff login without a password or activation.
 * One code is delivered to the three pre-enrolled SuperAdmin guardian inboxes.
 * Only a named, active staff user with an allowed work window may use it.
 */
final class StaffGuardianLoginController
{
    public function start(Request $request, StaffAccessPolicy $schedule): JsonResponse
    {
        abort_unless(config('staff_guardian.enabled'), 404);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        $user = User::query()->where('email', mb_strtolower(trim($data['email'])))
            ->where('kind', UserKind::Staff)->first();
        abort_unless(
            $user && ! $user->isSuperadmin()
                && $user->hasAnyRole(['administrateur', 'comptabilite'])
                && $user->status === UserStatus::Active,
            403, 'Accès interne non autorisé.'
        );
        abort_unless($schedule->allows($user), 403, 'Connexion indisponible hors des horaires autorisés.');

        $security = DB::table('superadmin_security_profiles')->where('user_id', config('superadmin.user_id'))->first();
        abort_unless($security, 503, 'Les trois adresses de sécurité doivent être configurées par le SuperAdmin.');
        $recipients = array_values(array_unique([$security->email_1, $security->email_2, $security->email_3]));
        abort_unless(count($recipients) === 3, 503);

        $code = (string) random_int(100000, 999999);
        $id = (string) Str::uuid();
        DB::table('staff_guardian_challenges')->where('user_id', $user->id)->whereNull('consumed_at')->delete();
        DB::table('staff_guardian_challenges')->insert([
            'id' => $id, 'user_id' => $user->id,
            'code_hash' => $this->digest($code),
            'attempts' => 0, 'expires_at' => now()->addMinutes(5),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $name = trim($user->first_name.' '.$user->last_name);
        $role = $user->getRoleNames()->map(fn (string $r): string => match (mb_strtolower($r)) {
            'comptabilite', 'comptabilité', 'comptable' => 'Comptabilité',
            'administrateur', 'admin' => 'Administrateur',
            default => ucfirst($r),
        })->implode(', ');
        foreach ($recipients as $recipient) {
            Mail::raw(
                "Demande de connexion CECT\nEmployé : {$name}\nFonction : {$role}\n".
                "Code de connexion : {$code}\nExpire dans 5 minutes.\n".
                "Si cette demande n'est pas autorisée, prévenez immédiatement le SuperAdmin.",
                fn ($m) => $m->to($recipient)->subject("CECT — Connexion {$role} : {$name}")
            );
        }
        return response()->json(['data' => ['challenge_id' => $id, 'status' => 'otp_required',
            'sent_to' => 'trois adresses de sécurité du SuperAdmin']], 202)->header('Cache-Control', 'no-store');
    }

    public function verify(Request $request, StaffAccessPolicy $schedule, EstablishAuthentication $establish): JsonResponse
    {
        abort_unless(config('staff_guardian.enabled'), 404);
        // Reject non-browser clients BEFORE consuming a valid one-time challenge.
        abort_unless($request->hasSession(), 403, 'Connexion depuis la console sécurisée requise.');
        $data = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'digits:6'],
        ]);
        $userId = DB::transaction(function () use ($data): ?string {
            $record = DB::table('staff_guardian_challenges')->where('id', $data['challenge_id'])->lockForUpdate()->first();
            if (! $record || $record->consumed_at || $record->attempts >= 5 || now()->greaterThanOrEqualTo($record->expires_at)) {
                return null;
            }
            $valid = hash_equals($record->code_hash, $this->digest($data['code']));
            DB::table('staff_guardian_challenges')->where('id', $record->id)->update([
                'attempts' => $record->attempts + 1,
                'consumed_at' => $valid ? now() : null,
                'updated_at' => now(),
            ]);
            return $valid ? $record->user_id : null;
        });
        abort_unless($userId, 422, 'Code invalide, expiré ou déjà utilisé.');
        $user = User::query()->find($userId);
        abort_unless($user && $user->isStaff() && ! $user->isSuperadmin()
            && $user->hasAnyRole(['administrateur', 'comptabilite'])
            && $user->status === UserStatus::Active && $schedule->allows($user),
            403, 'Connexion refusée par les règles d’accès du personnel.');
        // Staff guardian login is cookie-only. No long-lived bearer credentials.
        abort_unless($request->hasSession(), 403, 'Connexion depuis la console sécurisée requise.');
        $response = $establish->handle($request, $user, 'cect-staff-browser');
        $request->session()->put('staff_guardian_user', $user->id);
        $request->session()->put('staff_guardian_at', now()->timestamp);
        return $response;
    }

    private function digest(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
