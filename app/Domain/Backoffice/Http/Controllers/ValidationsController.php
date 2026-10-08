<?php

namespace App\Domain\Backoffice\Http\Controllers;

use App\Domain\Exchange\Actions\ValidationDesk;
use App\Domain\Exchange\Models\ExchangeRequest;
use App\Domain\Identity\Actions\ConfirmStepUp;
use App\Domain\Merchant\Actions\MerchantPaymentService;
use App\Domain\Merchant\Models\MerchantPayment;
use App\Domain\Payment\Actions\ManualPaymentDesk;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\ValidationQueue;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The validators' work screen: payments made outside the provider, YEM declared as sent to the CECT, and merchant
 * payments to arbitrate. The decisions are the same actions as the API's; a decision needs a recent step-up.
 */
class ValidationsController
{
    public function __construct(
        private readonly ValidationQueue $queue,
        private readonly ManualPaymentDesk $payments,
        private readonly ValidationDesk $exchanges,
        private readonly MerchantPaymentService $merchantPayments,
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request): View
    {
        $user = $this->user($request);
        $canPayments = $user->can('payments.validate_manual');
        $canYem = $user->can('yem_validations.view');
        abort_unless($canPayments || $canYem, 403);

        $history = $request->query('statut') === 'historique';
        $stepUpUntil = (int) $request->session()->get(ConfirmStepUp::SESSION_KEY, 0);

        return view('backoffice.validations.index', [
            'items' => $this->queue->items($canPayments, $canYem, $history),
            'history' => $history,
            'canDecidePayments' => $canPayments,
            'canDecideYem' => $user->can('yem_validations.confirm'),
            'stepUpActive' => $stepUpUntil > now()->getTimestamp(),
            'stepUpUntil' => $stepUpUntil > now()->getTimestamp() ? now()->setTimestamp($stepUpUntil) : null,
            'methodLabels' => $this->methodLabels(),
        ]);
    }

    public function approvePayment(Request $request, PaymentAttempt $attempt): RedirectResponse
    {
        $validated = $request->validate([
            'received_amount' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:120'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->attempt(fn () => $this->payments->approve($this->user($request), $attempt, (int) $validated['received_amount'], $validated['reference'], $validated['comment'] ?? null), 'Paiement validé : le membre en est informé.');
    }

    public function rejectPayment(Request $request, PaymentAttempt $attempt): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        return $this->attempt(fn () => $this->payments->reject($this->user($request), $attempt, $validated['reason']), 'Paiement rejeté : le membre en est informé.');
    }

    public function showExchange(ExchangeRequest $exchange): View
    {
        $exchange->load(['user.pernumIdentities', 'user.organization', 'userPack.plan', 'validationEvents.actor']);

        return view('backoffice.validations.exchange', [
            'exchange' => $exchange,
            'requireReference' => $this->settings->boolean('validation.require_external_reference'),
        ]);
    }

    public function confirmExchange(Request $request, ExchangeRequest $exchange): RedirectResponse
    {
        $validated = $request->validate([
            'quantity_received' => ['required', 'regex:/^\d{1,22}(\.\d{1,6})?$/'],
            'external_reference' => ['nullable', 'string', 'max:120'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ], ['quantity_received.regex' => 'La quantité reçue doit être un nombre (6 décimales au plus).']);

        return $this->attempt(
            fn () => $this->exchanges->confirm($this->user($request), $exchange, $validated['quantity_received'], $validated['external_reference'] ?? null, $validated['comment'] ?? null),
            "Réception {$exchange->reference} confirmée : la contre-valeur est désormais due.",
            route('backoffice.validations'),
            $request,
        );
    }

    public function rejectExchange(Request $request, ExchangeRequest $exchange): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        return $this->attempt(
            fn () => $this->exchanges->reject($this->user($request), $exchange, $validated['reason']),
            "Demande {$exchange->reference} rejetée : la quantité réservée est libérée.",
            route('backoffice.validations'),
            $request,
        );
    }

    public function disputeExchange(Request $request, ExchangeRequest $exchange): RedirectResponse
    {
        $validated = $request->validate(['comment' => ['required', 'string', 'min:5', 'max:2000']]);

        return $this->attempt(
            fn () => $this->exchanges->dispute($this->user($request), $exchange, $validated['comment']),
            "Demande {$exchange->reference} placée en litige : la quantité reste réservée.",
            route('backoffice.validations.exchange', $exchange),
            $request,
        );
    }

    public function confirmMerchantPayment(Request $request, MerchantPayment $payment): RedirectResponse
    {
        return $this->attempt(fn () => $this->merchantPayments->confirmByValidator($this->user($request), $payment), "Paiement {$payment->reference} confirmé.");
    }

    public function rejectMerchantPayment(Request $request, MerchantPayment $payment): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        return $this->attempt(fn () => $this->merchantPayments->rejectByValidator($this->user($request), $payment, $validated['reason']), "Paiement {$payment->reference} rejeté : la quantité est rendue au payeur.");
    }

    /**
     * Runs a decision; a business refusal comes back to the page as a message instead of an error page.
     *
     * @param  callable(): mixed  $decision
     */
    private function attempt(callable $decision, string $success, ?string $to = null, ?Request $request = null): RedirectResponse
    {
        try {
            $decision();
        } catch (ApiException $exception) {
            return back()->withErrors(['decision' => $exception->getMessage()]);
        }

        return ($to === null ? redirect()->route('backoffice.validations') : redirect($to))->with('status', $success);
    }

    /**
     * @return array<string, string>
     */
    private function methodLabels(): array
    {
        return collect($this->settings->array('payment.methods'))
            ->filter(fn (mixed $method): bool => is_array($method) && isset($method['code'], $method['label']))
            ->mapWithKeys(fn (array $method): array => [(string) $method['code'] => (string) $method['label']])
            ->all();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
