<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $invoices = Invoice::with('tenant', 'subscription.plan', 'payment')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'invoices' => InvoiceResource::collection($invoices),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $invoice = Invoice::with('tenant', 'subscription.plan', 'payment')->findOrFail($id);

        $this->authorize('view', $invoice);

        return response()->json([
            'invoice' => new InvoiceResource($invoice),
        ]);
    }
}
