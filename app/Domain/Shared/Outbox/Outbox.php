<?php

namespace App\Domain\Shared\Outbox;

use App\Domain\Audit\Enums\SecuritySeverity;
use App\Domain\Audit\SecurityEventLogger;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * Transactional outbox.
 *
 * push() writes the message in the caller's transaction: it exists if and only if the business change committed,
 * whatever queue backend is used. relay() then delivers pending messages to their handler, with retries and
 * backoff; a message failing too often is parked as "dead" and raises a critical security event.
 */
class Outbox
{
    private const MAX_ATTEMPTS = 10;

    /**
     * A claimed message is hidden from other relays for this long; if its handler never finishes, it is retried.
     */
    private const LEASE_SECONDS = 300;

    /**
     * @var array<string, class-string<OutboxHandler>>
     */
    private array $handlers = [];

    public function __construct(
        private readonly Container $container,
        private readonly SecurityEventLogger $securityLogger,
    ) {}

    /**
     * @param  class-string<OutboxHandler>  $handler
     */
    public function register(string $type, string $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function push(string $type, array $payload): OutboxMessage
    {
        if (! isset($this->handlers[$type])) {
            throw new LogicException("No outbox handler registered for {$type}.");
        }

        $message = OutboxMessage::query()->create([
            'type' => $type,
            'payload' => $payload,
            'status' => OutboxMessage::STATUS_PENDING,
            'available_at' => now(),
        ]);

        DB::afterCommit(fn () => RelayOutboxJob::dispatch());

        return $message;
    }

    /**
     * Delivers due messages. Safe to run concurrently.
     *
     * A message is first claimed in a short committed transaction (SKIP LOCKED, plus a lease that hides it from other
     * relays), then its handler runs outside any transaction, so that what the handler commits (for instance
     * "transfer submitted") is durable before any external call. If the process dies mid-way, the lease expires and
     * the message is delivered again: handlers are idempotent.
     *
     * @return array{processed: int, failed: int}
     */
    public function relay(int $limit = 50): array
    {
        $processed = 0;
        $failed = 0;

        for ($i = 0; $i < $limit; $i++) {
            $message = $this->claim();

            if ($message === null) {
                break;
            }

            $this->deliver($message) ? $processed++ : $failed++;
        }

        return ['processed' => $processed, 'failed' => $failed];
    }

    private function claim(): ?OutboxMessage
    {
        return DB::transaction(function (): ?OutboxMessage {
            $message = OutboxMessage::query()
                ->where('status', OutboxMessage::STATUS_PENDING)
                ->where('available_at', '<=', now())
                ->orderBy('available_at')
                ->orderBy('id')
                ->lock('for update skip locked')
                ->first();

            $message?->forceFill([
                'attempts' => $message->attempts + 1,
                'available_at' => now()->addSeconds(self::LEASE_SECONDS),
            ])->save();

            return $message;
        });
    }

    private function deliver(OutboxMessage $message): bool
    {
        try {
            $handler = $this->container->make($this->handlers[$message->type] ?? throw new LogicException("No outbox handler registered for {$message->type}."));
            $handler->handle($message->payload);

            $message->forceFill(['status' => OutboxMessage::STATUS_PROCESSED, 'processed_at' => now(), 'last_error' => null])->save();

            return true;
        } catch (Throwable $exception) {
            report($exception);
            $dead = $message->attempts >= self::MAX_ATTEMPTS;

            $message->forceFill([
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                'status' => $dead ? OutboxMessage::STATUS_DEAD : OutboxMessage::STATUS_PENDING,
                'available_at' => now()->addSeconds(min(3600, 15 * (2 ** $message->attempts))),
            ])->save();

            if ($dead) {
                $this->securityLogger->record('outbox.message_dead', SecuritySeverity::Critical, metadata: [
                    'message_id' => $message->id,
                    'type' => $message->type,
                    'error' => mb_substr($exception->getMessage(), 0, 300),
                ]);
            }

            return false;
        }
    }
}
