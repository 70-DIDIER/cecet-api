<?php

namespace App\Domain\Ops\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Operational alert sent to the platform's supervisors.
 */
class OpsAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly string $severity,
        public readonly string $description,
        public readonly int $value,
    ) {}

    public function envelope(): Envelope
    {
        $level = $this->severity === 'critical' ? 'P1 CRITIQUE' : 'P2';

        return new Envelope(subject: "[CECT {$level}] {$this->code}");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.ops-alert', with: [
            'code' => $this->code,
            'severity' => $this->severity,
            'description' => $this->description,
            'value' => $this->value,
            'environment' => (string) config('app.env'),
        ]);
    }
}
