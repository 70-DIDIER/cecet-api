<?php

namespace App\Domain\Payment\Models;

use App\Domain\Payment\Enums\WebhookEventStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Authenticated webhook received from a provider. Unique per (provider, provider_event_id).
 *
 * @property string $id
 * @property string $provider
 * @property string $provider_event_id
 * @property string|null $event_name
 * @property string|null $provider_transaction_id
 * @property array<string, mixed> $payload
 * @property WebhookEventStatus $status
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property string|null $error
 * @property string|null $ip_address
 */
#[Fillable(['provider', 'provider_event_id', 'event_name', 'provider_transaction_id', 'payload', 'status', 'received_at', 'processed_at', 'error', 'ip_address'])]
class ProviderWebhookEvent extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => WebhookEventStatus::class,
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
