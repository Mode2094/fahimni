<?php

namespace App\Console\Commands;

use App\Mail\PlatformNotification;
use App\Support\MailDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * fahimni:mail-test — send a REAL transactional email and report honestly
 * whether it was handed to a mailer that can actually deliver it.
 *
 * This never claims success for a `log`/`array` mailer: it exits non-zero and
 * says so. Use it to prove SMTP works to a real test inbox after configuring
 * MAIL_* in .env.
 */
class MailTestCommand extends Command
{
    protected $signature = 'fahimni:mail-test
        {to? : recipient address (defaults to MAIL_FROM_ADDRESS)}
        {--from= : override the From address for this test}';

    protected $description = 'Send a real test email and verify a deliverable mailer is configured.';

    public function handle(): int
    {
        $to = (string) ($this->argument('to') ?: config('mail.from.address', ''));
        $mailer = (string) config('mail.default', '');
        $from = (string) ($this->option('from') ?: config('mail.from.address', ''));

        $this->line('Mailer      : '.($mailer !== '' ? $mailer : '(unset)'));
        $this->line('SMTP host   : '.(string) config('mail.mailers.smtp.host', '(n/a)'));
        $this->line('From        : '.$from);
        $this->line('Recipient   : '.$to);
        $this->newLine();

        if (Validator::make(['to' => $to], ['to' => 'required|email'])->fails()) {
            $this->error('A valid recipient is required: pass an address, e.g. `php artisan fahimni:mail-test you@example.com`.');

            return self::FAILURE;
        }

        $configured = MailDelivery::isConfigured();

        if (! $configured) {
            $this->warn('This mailer is NOT configured to deliver to a real inbox (log/array/placeholder/localhost host).');
            $this->warn('The message below will be handed to the mailer anyway so you can see the transport behaviour.');
            $this->newLine();
        }

        try {
            Mail::to($to)->send(new PlatformNotification(
                title: 'اختبار البريد — منصة فهمني',
                body: "هذه رسالة اختبار من منصة فهمني للتأكد من إعداد البريد.\n\nإذا وصلتك هذه الرسالة فالإرسال يعمل. الوقت: ".now()->toDateTimeString(),
                data: [],
            ));
        } catch (\Throwable $e) {
            $this->error('Sending failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $configured) {
            $this->error("Handed to the '{$mailer}' mailer, which does NOT deliver to an inbox — treat as NOT delivered.");

            return self::FAILURE;
        }

        $this->info("Sent via '{$mailer}' to {$to}. Check the inbox (and spam) to confirm arrival.");

        return self::SUCCESS;
    }
}
