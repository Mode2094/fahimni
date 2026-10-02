<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PlatformNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $title,
        public readonly ?string $body,
        public readonly array $data = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.notifications.platform',
            with: [
                'title' => $this->title,
                'body' => $this->body,
                'actionUrl' => $this->data['action_url'] ?? null,
            ],
        );
    }
}
