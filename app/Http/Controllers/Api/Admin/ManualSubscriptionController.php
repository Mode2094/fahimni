<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\DuplicatePaymentReferenceException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ManualSubscriptionOperationResource;
use App\Models\ManualSubscriptionOperation;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\ManualSubscriptionService;
use App\Support\UploadValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Super-Admin manual subscription management for a single teacher.
 *
 * Every route here is behind the `admin` middleware (super admin only). The
 * teacher is always addressed by id and its tenant is resolved server-side —
 * the client never supplies a tenant id.
 */
class ManualSubscriptionController extends Controller
{
    public function __construct(
        private readonly ManualSubscriptionService $service,
    ) {}

    public function history(Request $request, string $teacher): JsonResponse
    {
        $tenant = $this->tenantForTeacher($teacher);

        $operations = ManualSubscriptionOperation::withoutTenant()
            ->where('tenant_id', $tenant->id)
            ->with(['previousPlan:id,name', 'newPlan:id,name'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'operations' => ManualSubscriptionOperationResource::collection($operations),
        ]);
    }

    public function preview(Request $request, string $teacher): JsonResponse
    {
        $tenant = $this->tenantForTeacher($teacher);
        $validated = $request->validate($this->service->rules(requireConfirmation: false));

        try {
            return response()->json(['preview' => $this->service->preview($tenant, $validated)]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function apply(Request $request, string $teacher): JsonResponse
    {
        $tenant = $this->tenantForTeacher($teacher);
        $validated = $request->validate($this->service->rules());

        $isFreeGrant = ($validated['operation_type'] ?? null) === 'free_grant';

        // A paid manual operation requires an honest amount, a payment method and
        // an explicit confirmation that the admin actually verified receipt of
        // the money (not just a reference). A missing/zero amount is NEVER
        // silently treated as a free grant.
        if (! $isFreeGrant) {
            if (empty($validated['payment_method'])) {
                return response()->json([
                    'message' => 'A payment method is required for a paid operation.',
                    'errors' => ['payment_method' => ['The payment method field is required.']],
                ], 422);
            }

            if (! isset($validated['amount']) || ! is_numeric($validated['amount']) || (float) $validated['amount'] <= 0) {
                return response()->json([
                    'message' => 'A positive amount is required for a paid operation.',
                    'errors' => ['amount' => ['A positive amount is required for a paid operation.']],
                ], 422);
            }

            if (empty($validated['confirm_payment_received'])) {
                return response()->json([
                    'message' => 'You must confirm that you verified receipt of the payment.',
                    'errors' => ['confirm_payment_received' => ['You must confirm that you verified receipt of the payment.']],
                ], 422);
            }
        }

        // Store the (optional) proof privately before the transaction. On any
        // failure below it is removed so no orphan file is left behind.
        $proofPath = null;

        if ($request->hasFile('proof')) {
            try {
                UploadValidator::generic($request->file('proof'));
            } catch (RuntimeException $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => ['proof' => [$e->getMessage()]],
                ], 422);
            }

            $proofPath = $request->file('proof')->store("payment-proofs/{$tenant->id}", 'local');
        }

        $validated['proof_path'] = $proofPath;
        $validated['_request'] = $request;

        try {
            $result = $this->service->apply($request->user(), $tenant, $validated);
        } catch (DuplicatePaymentReferenceException $e) {
            if ($proofPath) {
                Storage::disk('local')->delete($proofPath);
            }

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['payment_reference' => [$e->getMessage()]],
            ], 422);
        } catch (\InvalidArgumentException $e) {
            if ($proofPath) {
                Storage::disk('local')->delete($proofPath);
            }

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($proofPath) {
                Storage::disk('local')->delete($proofPath);
            }

            throw $e;
        }

        $operation = $result['operation'];
        $operation->load(['previousPlan:id,name', 'newPlan:id,name']);

        return response()->json([
            'message' => $result['already_applied']
                ? 'This operation was already applied.'
                : 'The subscription was updated and access is active immediately.',
            'already_applied' => $result['already_applied'],
            'operation' => new ManualSubscriptionOperationResource($operation),
            'subscription' => $result['subscription'],
        ]);
    }

    public function proof(Request $request, string $operation): StreamedResponse|JsonResponse
    {
        $op = ManualSubscriptionOperation::withoutTenant()->findOrFail($operation);

        if (! $op->proof_path || ! Storage::disk('local')->exists($op->proof_path)) {
            abort(404, 'Proof not found.');
        }

        return Storage::disk('local')->response(
            $op->proof_path,
            'proof-'.$op->id.'.'.pathinfo($op->proof_path, PATHINFO_EXTENSION),
        );
    }

    private function tenantForTeacher(string $teacher): \App\Models\Tenant
    {
        $user = User::role('Teacher')
            ->with('tenantPivots.tenant')
            ->findOrFail($teacher);

        $tenant = $user->tenantPivots->first()?->tenant;

        abort_if(! $tenant, 422, 'This teacher has no workspace yet.');

        return $tenant;
    }
}
