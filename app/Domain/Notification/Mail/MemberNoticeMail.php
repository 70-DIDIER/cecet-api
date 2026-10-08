<?php

namespace App\Domain\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Plain-text information email to a member or a merchant. Queued, and only sent once the transaction that
 * caused it has committed: a rolled-back operation never notifies anyone.
 */
class MemberNoticeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public readonly string $event,
        public readonly string $subjectLine,
        public readonly string $greetingName,
        public readonly array $lines,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.member-notice', with: [
            'greetingName' => $this->greetingName,
            'lines' => $this->lines,
        ]);
    }
}
