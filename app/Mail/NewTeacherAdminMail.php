<?php

namespace App\Mail;

use App\Jobs\NotifyAdminNewTeacherJob;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Concierge alert to the platform Super Admin when a teacher registers.
 *
 * Rendered by the branded, RTL `mail.new-teacher-admin` view. Sent from the
 * queued {@see NotifyAdminNewTeacherJob} so registration is never
 * blocked by the mail transport.
 */
class NewTeacherAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{total:int,today:int,this_month:int,paid_subscribers:int}  $stats
     */
    public function __construct(
        public readonly string $platformNameFull,
        public readonly string $teacherName,
        public readonly string $teacherEmail,
        public readonly ?string $teacherPlatformName,
        public readonly string $subscriptionType,
        public readonly string $registeredAt,
        public readonly array $stats,
        public readonly ?string $logoUrl = null,
        public readonly ?string $actionUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🎉 مبروك! معلم جديد انضم إلى فهمني — أصبح لدينا '.$this->stats['total'].' معلمًا',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.new-teacher-admin');
    }
}
