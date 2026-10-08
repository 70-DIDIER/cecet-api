<?php

namespace App\Domain\Notification;

use App\Domain\Notification\Mail\MemberNoticeMail;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Informs members and merchants of the outcome of their operations by email.
 *
 * A notification is a courtesy, never part of the operation: it is queued after commit, and a failure to queue it
 * is logged without undoing anything. Each event can be switched off by the Superadmin (notifications.disabled_events).
 */
class Notifier
{
    public const EVENTS = [
        'membership.activated',
        'payment.rejected',
        'pack.activated',
        'topup.credited',
        'exchange.yem_received',
        'exchange.rejected',
        'merchant_payment.declared',
        'merchant_payment.confirmed',
        'merchant_payment.rejected',
    ];

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @param  array<string, string|int>  $data
     */
    public function notify(User $recipient, string $event, array $data = []): void
    {
        if (in_array($event, array_map('strval', $this->settings->array('notifications.disabled_events')), true)) {
            return;
        }

        [$subject, $lines] = $this->message($event, $data);

        try {
            Mail::to($recipient->email)->queue(new MemberNoticeMail($event, $subject, $recipient->organization->legal_name ?? $recipient->full_name, $lines));
        } catch (Throwable $exception) {
            Log::warning('notification.not_queued', ['event' => $event, 'user_id' => $recipient->id, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * @param  array<string, string|int>  $data
     * @return array{0: string, 1: list<string>}
     */
    private function message(string $event, array $data): array
    {
        $get = fn (string $key): string => (string) ($data[$key] ?? '');

        return match ($event) {
            'membership.activated' => ['Votre adhésion à la CECT est active', [
                'Votre paiement a été reçu : votre adhésion est désormais active.',
                'Vous pouvez souscrire les packs ouverts à votre profil.',
            ]],
            'payment.rejected' => ['Votre paiement n\'a pas pu être validé', [
                "Le paiement {$get('reference')} ({$get('label')}) n'a pas été retrouvé par la CECT.",
                "Motif : {$get('reason')}",
                'Vous pouvez effectuer un nouveau paiement depuis votre espace membre.',
            ]],
            'pack.activated' => ["Votre pack {$get('pack')} est actif", [
                "Votre pack {$get('pack')} est activé.",
                'Votre quota est crédité selon la périodicité prévue par le pack.',
            ]],
            'topup.credited' => ['Votre rechargement Perso est crédité', [
                "Votre rechargement de {$get('amount')} FCFA a été reçu : {$get('quantity')} YEM ont été crédités sur votre pack Perso.",
            ]],
            'exchange.yem_received' => ["YEM reçus pour la demande {$get('reference')}", [
                "La CECT a confirmé la réception de {$get('quantity')} YEM pour la demande {$get('reference')}.",
                "La contre-valeur de {$get('amount')} FCFA vous sera versée sur le moyen de paiement enregistré.",
            ]],
            'exchange.rejected' => ["Demande {$get('reference')} rejetée", [
                "La CECT n'a pas retrouvé le transfert de la demande {$get('reference')}.",
                "Motif : {$get('reason')}",
                'La quantité réservée a été remise à votre disposition.',
            ]],
            'merchant_payment.declared' => ["Paiement {$get('reference')} à confirmer", [
                "{$get('payer')} déclare vous avoir transféré {$get('quantity')} YEM (paiement {$get('reference')}).",
                'Vérifiez la réception sur votre Pernum, puis confirmez-la ou signalez-la comme non reçue depuis votre espace marchand.',
            ]],
            'merchant_payment.confirmed' => ["Paiement {$get('reference')} confirmé", [
                "Votre paiement de {$get('quantity')} YEM à {$get('merchant')} est confirmé.",
            ]],
            'merchant_payment.rejected' => ["Paiement {$get('reference')} non confirmé", [
                "Le transfert de votre paiement {$get('reference')} n'a pas été retrouvé.",
                "Motif : {$get('reason')}",
                'La quantité réservée a été remise à votre disposition.',
            ]],
            default => ['Information de la CECT', []],
        };
    }
}
