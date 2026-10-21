<?php

namespace App\Domain\Kyc\Didit;

use App\Domain\Kyc\Models\MemberKycCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Server-side only. No API key, session creation or decision is exposed to a browser.
 * Hosted Didit flow performs ID capture, selfie, active 3D liveness and face-match,
 * subject to the configured workflow actually including all four modules.
 */
final class DiditGateway
{
    public function configured(): bool
    {
        return config('kyc.provider') === 'didit'
            && filled(config('kyc.didit.api_key'))
            && filled(config('kyc.didit.workflow_id'))
            && filled(config('kyc.didit.webhook_secret'));
    }

    public function create(MemberKycCase $case): array
    {
        abort_unless($this->configured(), 503, 'Didit n’est pas encore configuré.');
        $response = Http::withHeaders(['x-api-key' => config('kyc.didit.api_key')])
            ->acceptJson()->timeout(12)
            ->post(rtrim(config('kyc.didit.base_url'), '/').'/v3/session/', [
                'workflow_id' => config('kyc.didit.workflow_id'),
                'vendor_data' => 'cect-kyc:'.$case->id,
            ]);
        if (! $response->successful()) {
            report(new \RuntimeException('Didit session API HTTP '.$response->status()));
            abort(502, 'Impossible de démarrer le contrôle d’identité.');
        }
        $url = $response->json('url');
        $session = $response->json('session_id');
        abort_unless(is_string($session) && strlen($session) >= 12 && is_string($url), 502);
        $url = str_starts_with($url, 'https://') ? $url : 'https://'.$url;
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
        abort_unless(in_array($host, config('kyc.didit.allowed_redirect_hosts'), true), 502,
            'Lien de vérification invalide.');
        return ['session_id' => $session, 'url' => $url];
    }

    public function decision(string $session): array
    {
        abort_unless($this->configured(), 503);
        abort_unless((bool) preg_match('/^[a-zA-Z0-9_-]{12,128}$/', $session), 400);
        $response = Http::withHeaders(['x-api-key' => config('kyc.didit.api_key')])
            ->acceptJson()->timeout(12)
            ->get(rtrim(config('kyc.didit.base_url'), '/').'/v3/session/'.rawurlencode($session).'/decision/');
        if (! $response->successful()) {
            report(new \RuntimeException('Didit decision API HTTP '.$response->status()));
            abort(502, 'Vérification Didit temporairement indisponible.');
        }
        $data = $response->json();
        abort_unless(is_array($data) && is_string($data['status'] ?? null), 502);
        return $data;
    }

    /**
     * Do not trust top-level Approved without module proofs, particularly liveness
     * and identity-photo face match. Fail closed if the provider response or workflow
     * does not expose the required details. Keep only booleans, not biometric scores.
     */
    public function checks(array $decision): array
    {
        $payload = is_array($decision['decision'] ?? null) ? $decision['decision'] : $decision;
        $pass = static fn (array $rows): bool => collect($rows)->contains(
            static fn ($item) => is_array($item) && ($item['status'] ?? null) === 'Approved'
        );
        $docs = $pass((array) ($payload['id_verifications'] ?? []));
        $face = $pass((array) ($payload['face_matches'] ?? []));
        $liveness = collect((array) ($payload['liveness_checks'] ?? []))->contains(
            static fn ($row) => is_array($row)
                && ($row['status'] ?? null) === 'Approved'
                && in_array(strtoupper(str_replace([' ', '-'], '_', (string) ($row['method'] ?? ''))),
                    ['3D_ACTION', 'ACTION', 'ACTIVE', '3D_FLASH', 'FLASH'], true)
        );
        $warnings = array_merge(
            (array) ($payload['warnings'] ?? []),
            ...array_map(static fn ($list): array => array_merge([], ...array_map(
                static fn ($row): array => (array) ($row['warnings'] ?? []), is_array($list) ? $list : []
            )), [$payload['id_verifications'] ?? [], $payload['liveness_checks'] ?? [], $payload['face_matches'] ?? []])
        );
        return [
            'document' => $docs,
            'active_liveness' => $liveness,
            'face_match' => $face,
            'warnings_present' => count($warnings) > 0,
            'complete' => $docs && $liveness && $face && count($warnings) === 0,
        ];
    }

    /**
     * Prefer full-payload V2 HMAC; support original raw-body signature.
     * We do not accept the envelope-only X-Signature-Simple.
     */
    public function signatureValid(string $raw, ?string $v2, ?string $v1): bool
    {
        $secret = config('kyc.didit.webhook_secret');
        if (! is_string($secret) || $secret === '') return false;
        if ($v2) {
            $payload = json_decode($raw, true);
            if (! is_array($payload)) return false;
            $canonical = json_encode($this->canonicalize($payload),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            return hash_equals(hash_hmac('sha256', $canonical, $secret), strtolower($v2));
        }
        return is_string($v1) && hash_equals(hash_hmac('sha256', $raw, $secret), strtolower($v1));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (array_is_list($value)) return array_map($this->canonicalize(...), $value);
        ksort($value, SORT_STRING);
        return array_map($this->canonicalize(...), $value);
    }
}
