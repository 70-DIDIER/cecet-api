<?php

namespace App\Domain\Ops;

use App\Domain\Audit\Enums\SecuritySeverity;
use App\Domain\Audit\SecurityEventLogger;
use App\Domain\Ops\Mail\OpsAlertMail;
use App\Domain\Reporting\Models\ExportSnapshot;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\Outbox\OutboxMessage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Operational supervision (cahier v4.0 §26): evaluates the platform's health and raises alerts.
 *
 * Critical (P1): ledger divergence, broken audit chain, suspicious payment (double payment, amount mismatch).
 * Major (P2): payout failures, dead or stuck outbox messages, failed jobs, massive webhook rejections, failed
 * snapshot. Each alert is recorded as a security event and emailed to the configured recipients, at most once per
 * configured delay for the same alert.
 */
class OpsMonitor
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SecurityEventLogger $securityLogger,
    ) {}

    /**
     * @return list<array{code: string, severity: string, ok: bool, value: int, message: string}>
     */
    public function check(bool $integrity = false): array
    {
        $since = now()->subHour();

        $checks = [
            $this->evaluate('suspicious_payments', SecuritySeverity::Critical,
                DB::table('payment_attempts')->where('requires_review', true)->where('updated_at', '>=', $since)->count(),
                0, 'Paiement(s) suspect(s) à examiner (double paiement, montant incohérent, paiement sur intention annulée).'),
            $this->evaluate('payout_failures', SecuritySeverity::Warning,
                DB::table('payout_items')->where('status', 'failed')->where('settled_at', '>=', $since)->count(),
                $this->settings->integer('ops.payout_failure_threshold') - 1, 'Hausse des virements de contre-valeur en échec sur la dernière heure.'),
            $this->evaluate('outbox_dead', SecuritySeverity::Warning,
                DB::table('outbox_messages')->where('status', OutboxMessage::STATUS_DEAD)->count(),
                0, 'Message(s) de l\'outbox abandonné(s) après plusieurs tentatives : un traitement (payout) ne s\'exécutera pas sans intervention.'),
            $this->evaluate('outbox_backlog', SecuritySeverity::Warning,
                DB::table('outbox_messages')->where('status', OutboxMessage::STATUS_PENDING)->where('available_at', '<', now()->subMinutes(15))->count(),
                0, 'Messages de l\'outbox en attente depuis plus de 15 minutes : le relais (outbox:relay) ou la file semble bloqué.'),
            $this->evaluate('failed_jobs', SecuritySeverity::Warning,
                DB::table('failed_jobs')->where('failed_at', '>=', $since)->count(),
                0, 'Tâche(s) d\'arrière-plan en échec sur la dernière heure.'),
            $this->evaluate('webhook_rejections', SecuritySeverity::Warning,
                DB::table('security_events')->where('event_type', 'webhook.invalid_signature')->where('occurred_at', '>=', $since)->count(),
                $this->settings->integer('ops.webhook_rejection_threshold') - 1, 'Webhooks de paiement rejetés en masse (signature invalide) sur la dernière heure.'),
            $this->evaluate('snapshot_failed', SecuritySeverity::Warning,
                ExportSnapshot::query()->orderByDesc('version')->value('status') === ExportSnapshot::FAILED ? 1 : 0,
                0, 'La dernière génération du snapshot consolidé a échoué.'),
        ];

        if ($integrity) {
            $checks[] = $this->evaluate('ledger_divergence', SecuritySeverity::Critical, Artisan::call('ledger:verify') === 0 ? 0 : 1, 0,
                'Divergence entre un compte (quota ou marchand) et son journal : geler les payouts jusqu\'à explication.');
            $checks[] = $this->evaluate('audit_chain_broken', SecuritySeverity::Critical, Artisan::call('audit:verify') === 0 ? 0 : 1, 0,
                'La chaîne d\'intégrité du journal d\'audit est rompue : altération possible.');
        }

        return $checks;
    }

    /**
     * Runs the checks and raises an alert for each failing one.
     *
     * @return list<array{code: string, severity: string, ok: bool, value: int, message: string}>
     */
    public function run(bool $integrity = false): array
    {
        $checks = $this->check($integrity);

        foreach ($checks as $check) {
            if (! $check['ok']) {
                $this->alert($check);
            }
        }

        return $checks;
    }

    /**
     * @return array{code: string, severity: string, ok: bool, value: int, message: string}
     */
    private function evaluate(string $code, SecuritySeverity $severity, int $value, int $tolerated, string $message): array
    {
        return ['code' => $code, 'severity' => $severity->value, 'ok' => $value <= $tolerated, 'value' => $value, 'message' => $message];
    }

    /**
     * @param  array{code: string, severity: string, ok: bool, value: int, message: string}  $check
     */
    private function alert(array $check): void
    {
        $repeatMinutes = max(1, $this->settings->integer('ops.alert_repeat_minutes'));

        if (! Cache::add("ops-alert:{$check['code']}", true, now()->addMinutes($repeatMinutes))) {
            return;
        }

        $this->securityLogger->record("ops.alert.{$check['code']}", SecuritySeverity::from($check['severity']), metadata: ['value' => $check['value'], 'message' => $check['message']]);

        $recipients = array_values(array_filter(array_map('strval', $this->settings->array('ops.alert_emails')), fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false));

        if ($recipients === []) {
            return;
        }

        try {
            Mail::to($recipients)->queue(new OpsAlertMail($check['code'], $check['severity'], $check['message'], $check['value']));
        } catch (Throwable $exception) {
            Log::critical('ops.alert_not_sent', ['code' => $check['code'], 'error' => $exception->getMessage()]);
        }
    }
}
