<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\TransactionResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\TenantSubscription;
use App\Models\Transaction;
use App\Services\IntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $payments = Payment::with('subscription')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'payments' => PaymentResource::collection($payments),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $payment = Payment::with('subscription')->findOrFail($id);

        $this->authorize('view', $payment);

        return response()->json([
            'payment' => new PaymentResource($payment),
        ]);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        // Payment status transitions are financial actions: only platform
        // administrators may change them (super admin UI uses /admin/payments).
        abort_if(! $request->user()->is_super_admin, 403, 'Only platform administrators can change payment status.');

        $payment = Payment::findOrFail($id);

        $this->authorize('updateStatus', $payment);

        $request->validate(['status' => 'required|in:pending,completed,failed,refunded']);

        $oldStatus = $payment->status;

        if ($request->status === 'refunded' && $oldStatus !== 'refunded') {
            // Refunds are processed inside the gateway dashboard and synced
            // via the refund webhook — a status flip alone would lie about
            // money that was collected. Only fake-gateway payments (no real
            // provider charge) can be refunded in-place.
            abort_unless(
                in_array($payment->gateway, ['fake', 'card'], true),
                422,
                "Refunds for {$payment->gateway} payments must be processed inside the gateway dashboard; the platform will sync via webhook."
            );

            $payment->update(['status' => 'refunded']);

            Transaction::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $payment->tenant_id,
                'type' => 'refund',
                'amount' => $payment->amount,
                'direction' => 'out',
                'balance_before' => 0,
                'balance_after' => 0,
                'reference_type' => 'payment',
                'reference_id' => $payment->id,
                'description' => "Refund for payment {$payment->transaction_id}",
            ]);

            Invoice::where('payment_id', $payment->id)->update(['status' => 'cancelled']);
        } elseif ($request->status !== $oldStatus) {
            $payment->update(['status' => $request->status]);
        }

        if ($request->status === 'completed' && $oldStatus !== 'completed') {
            app(IntegrationService::class)->triggerZapier($payment->tenant_id, 'new_payment', [
                'payment_id' => $payment->id,
                'transaction_id' => $payment->transaction_id,
                'amount' => $payment->amount,
                'currency' => $payment->currency ?? 'JOD',
                'status' => $payment->status,
            ]);
        }

        return response()->json([
            'payment' => new PaymentResource($payment->fresh()),
        ]);
    }
}
