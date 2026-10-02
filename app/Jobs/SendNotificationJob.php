<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public readonly User $notifiable,
        public readonly string $type,
        public readonly string $title,
        public readonly ?string $body = null,
        public readonly ?User $sender = null,
        public readonly ?string $tenantId = null,
        public readonly array $data = [],
        public readonly array $channels = ['database'],
    ) {}

    public function handle(NotificationService $notificationService): void
    {
        // المستخدم قد يُحذف قبل معالجة الجوب — تجاهل بدل فشل متكرر.
        if (! $this->notifiable->exists) {
            return;
        }

        $notificationService->deliver(
            $this->notifiable,
            $this->type,
            $this->title,
            $this->body,
            $this->sender,
            $this->tenantId,
            $this->data,
            $this->channels,
        );
    }
}
