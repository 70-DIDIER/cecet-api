<?php

namespace App\Domain\Identity\Mail;

use App\Domain\Identity\Enums\OtpPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email carrying a one-time code. Queued encrypted, only after the surrounding transaction commits.
 */
class OtpCodeMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly OtpPurpose $purpose,
        public readonly string $code,
        public readonly CarbonImmutable $expiresAt,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->purpose->subject());
    }

    public function content(): Content
    {
        return new Content(text: 'mail.otp-code', with: [
            'subjectLine' => $this->purpose->subject(),
            'code' => $this->code,
            'validityMinutes' => max(1, (int) ceil(now()->diffInSeconds($this->expiresAt) / 60)),
        ]);
    }
}
