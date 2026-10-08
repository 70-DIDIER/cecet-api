<?php

namespace App\Domain\Shared\Outbox;

/**
 * Executes one type of outbox message. Must be idempotent: a message can be delivered more than once.
 */
interface OutboxHandler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void;
}
