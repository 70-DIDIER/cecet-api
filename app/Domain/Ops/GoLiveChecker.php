<?php

namespace App\Domain\Ops;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Identity\Enums\UserKind;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Settings\SettingsService;
use App\Models\User;

/**
 * Automatable part of the go-live checklist (cahier v4.0 §33): configuration that must be right before production.
 * Business, provider-contract, pentest and restore-test items stay human decisions and are not judged here.
 */
class GoLiveChecker
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @return list<array{code: string, ok: bool, message: string}>
     */
    public function check(): array
    {
        $services = (array) config('services.fedapay');
        $usesFedapay = in_array('fedapay', [$this->settings->string('payment.inbound_provider'), $this->settings->string('payment.payout_provider')], true);

        return [
            $this->item('app_env', config('app.env') === 'production', 'APP_ENV doit valoir « production ».'),
            $this->item('app_debug', config('app.debug') === false, 'APP_DEBUG doit être désactivé (fuite de données dans les erreurs).'),
            $this->item('https', str_starts_with((string) config('app.url'), 'https://'), 'APP_URL doit être en https.'),
            $this->item('session_cookie_secure', config('session.secure') === true, 'SESSION_SECURE_COOKIE doit être à true.'),
            $this->item('mailer', ! in_array(config('mail.default'), ['log', 'array', null], true), 'MAIL_MAILER doit envoyer de vrais e-mails : les codes OTP en dépendent.'),
            $this->item('queue', config('queue.default') !== 'sync', 'QUEUE_CONNECTION ne doit pas être « sync » : les webhooks et e-mails doivent passer par une file.'),
            $this->item('demo_bypass', $this->settings->boolean('platform.demo_gateway_bypass') === false, 'platform.demo_gateway_bypass doit être désactivé.'),
            $this->item('inbound_provider', $this->settings->string('payment.inbound_provider') !== 'fake', 'payment.inbound_provider ne doit pas être « fake ».'),
            $this->item('payout_provider', $this->settings->string('payment.payout_provider') !== 'fake', 'payment.payout_provider ne doit pas être « fake ».'),
            $this->item('provider_keys', ! $usesFedapay || (filled($services['secret_key'] ?? null) && filled($services['webhook_secret'] ?? null)), 'Clé secrète et secret de webhook FedaPay requis.'),
            $this->item('provider_environment', ! $usesFedapay || ($services['environment'] ?? 'sandbox') === 'live', 'FEDAPAY_ENVIRONMENT doit valoir « live ».'),
            // Warn even when the FAKE adapter is active: a configured sandbox
            // API token without the webhook signing secret cannot be promoted
            // safely to external collection merely by changing the provider.
            $this->item('fedapay_webhook_ready',
                ! filled($services['secret_key'] ?? null) || filled($services['webhook_secret'] ?? null),
                'Une clé API FedaPay est présente, mais le secret de signature webhook manque : impossible de valider les encaissements externes.'),
            $this->item('cect_pernum', filled($this->settings->string('yem.cect_pernum')), 'yem.cect_pernum (Pernum de réception de la CECT) n\'est pas configuré.'),
            $this->item('alert_emails', $this->settings->array('ops.alert_emails') !== [], 'ops.alert_emails est vide : personne ne recevra les alertes.'),
            $this->item('superadmin', $this->activeStaffWith(PermissionCatalog::SUPERADMIN) >= 1, 'Aucun compte Superadmin actif (php artisan cect:create-superadmin).'),
            $this->item('staff_roles', $this->activeStaffWith(PermissionCatalog::ACCOUNTING) >= 1, 'Il faut au moins un compte Comptabilité nominatif actif, doté des permissions de validation YEM et de gestion des paiements.'),
            $this->item('hash_driver', config('hashing.driver') === 'argon2id', 'HASH_DRIVER doit rester argon2id.'),
        ];
    }

    private function activeStaffWith(string $role): int
    {
        return User::query()->where('kind', UserKind::Staff)->where('status', UserStatus::Active)->role($role)->count();
    }

    /**
     * @return array{code: string, ok: bool, message: string}
     */
    private function item(string $code, bool $ok, string $message): array
    {
        return ['code' => $code, 'ok' => $ok, 'message' => $message];
    }
}
