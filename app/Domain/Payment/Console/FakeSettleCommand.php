<?php

namespace App\Domain\Payment\Console;

use App\Domain\Payment\Enums\ProviderPaymentStatus;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Providers\Fake\FakePaymentProvider;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/**
 * Development helper: moves a fake-provider payment to a status and prints the signed webhook
 * to send to the local API, as the real provider would.
 */
class FakeSettleCommand extends Command
{
    protected $signature = 'payments:fake-settle
        {attempt : Identifiant de la tentative de paiement}
        {status=approved : approved, declined ou canceled}
        {--send : Envoie directement le webhook signé à l’API locale (sans serveur HTTP à lancer)}';

    protected $description = 'Simule la réponse du faux prestataire de paiement (hors production) et affiche le webhook signé à envoyer';

    public function handle(Application $app, FakePaymentProvider $fake): int
    {
        if (! $app->environment('testing')) {
            $this->error('Commande réservée aux tests automatisés.');

            return self::FAILURE;
        }

        $status = ProviderPaymentStatus::tryFrom((string) $this->argument('status'));
        $attempt = PaymentAttempt::query()->where('provider', FakePaymentProvider::NAME)->find((string) $this->argument('attempt'));

        if ($status === null || $attempt === null || $attempt->provider_transaction_id === null) {
            $this->error('Tentative introuvable (faux prestataire uniquement) ou statut invalide.');

            return self::FAILURE;
        }

        $webhook = $fake->simulate($attempt->provider_transaction_id, $status);
        $url = rtrim((string) config('app.url'), '/').'/api/v1/webhooks/payments/fake';
        $header = FakePaymentProvider::SIGNATURE_HEADER;

        $this->info("Transaction {$attempt->provider_transaction_id} passée à « {$status->value} ». Webhook à envoyer en POST :");
        $this->line("  URL     : {$url}");
        $this->line("  En-tête : {$header}: {$webhook['headers'][$header]}");
        $this->line("  Corps   : {$webhook['body']}");

        if ($this->option('send')) {
            $request = Request::create($url, 'POST', server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_'.strtoupper(str_replace('-', '_', $header)) => $webhook['headers'][$header],
            ], content: $webhook['body']);
            $response = app(Kernel::class)->handle($request);

            $this->info("Webhook envoyé : HTTP {$response->getStatusCode()}.");

            return $response->isSuccessful() ? self::SUCCESS : self::FAILURE;
        }

        return self::SUCCESS;
    }
}