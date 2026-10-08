<?php

namespace App\Domain\Payment\Http\Controllers;

use App\Domain\Payment\Actions\ReceiveProviderWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController
{
    /**
     * Receive a payment provider webhook.
     *
     * Public endpoint: the request is authenticated by the provider's signature over the raw body. Duplicates
     * are acknowledged without being processed twice, and the outcome is always re-read from the provider.
     */
    public function __invoke(Request $request, string $provider, ReceiveProviderWebhook $receive): JsonResponse
    {
        $isNew = $receive->handle($provider, $request);

        return response()->json(['received' => true, 'duplicate' => ! $isNew]);
    }
}
