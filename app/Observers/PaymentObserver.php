<?php

namespace App\Observers;

use App\Models\Payment;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

class PaymentObserver
{
    public function __construct(
        private readonly NotificationService $notifier,
    ) {}

    public function created(Payment $payment): void
    {
        // Payments are created pending and completed via guarded conditional
        // updates in the callback flow — the updated() hook is the one that
        // fires on completion.
    }

    public function updated(Payment $payment): void
    {
        if ($payment->status !== 'completed') {
            return;
        }

        $wasCompleted = (bool) ($payment->getOriginal('status') === 'completed');

        if ($wasCompleted) {
            return;
        }

        // Tenant staff pivots store lowercase roles ('teacher'/'owner'/'admin');
        // owners and admins are the platform-side recipients.
        $userIds = DB::table('tenant_user')
            ->where('tenant_id', $payment->tenant_id)
            ->whereIn('role', ['owner', 'admin'])
            ->where('status', 'active')
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            return;
        }

        $users = User::whereIn('id', $userIds)->where('is_active', true)->get();

        foreach ($users as $user) {
            $isAr = ($user->locale ?? 'ar') === 'ar';

            $this->notifier->notify(
                notifiable: $user,
                type: 'payment.completed',
                title: $isAr
                    ? "تم استلام دفعة: {$payment->amount} {$payment->currency}"
                    : "Payment received: {$payment->amount} {$payment->currency}",
                body: $isAr
                    ? "اكتملت دفعة بقيمة {$payment->amount} {$payment->currency}."
                    : "A payment of {$payment->amount} {$payment->currency} has been completed.",
                tenantId: $payment->tenant_id,
                data: [
                    'payment_id' => $payment->id,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'status' => $payment->status,
                ],
            );
        }
    }
}
