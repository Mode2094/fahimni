<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Transaction::class);

        $query = Transaction::query()
            ->with('tenant:id,name,slug')
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->direction))
            ->when($request->filled('tenant_id'), fn ($q) => $q->where('tenant_id', $request->tenant_id))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->when($request->filled('search'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('description', 'ilike', '%'.$request->search.'%')
                    ->orWhere('reference_id', 'ilike', '%'.$request->search.'%')
                    ->orWhereHas('tenant', fn ($t) => $t->where('name', 'ilike', '%'.$request->search.'%'));
            }));

        $transactions = $query->orderByDesc('created_at')->limit(500)->get();

        $base = Transaction::query();
        $stats = [
            'total' => (clone $base)->count(),
            'in_count' => (clone $base)->where('direction', 'in')->count(),
            'out_count' => (clone $base)->where('direction', 'out')->count(),
            'in_total' => (float) (clone $base)->where('direction', 'in')->sum('amount'),
            'out_total' => (float) (clone $base)->where('direction', 'out')->sum('amount'),
            'net' => (float) ((clone $base)->where('direction', 'in')->sum('amount') - Transaction::query()->where('direction', 'out')->sum('amount')),
        ];

        return response()->json([
            'transactions' => TransactionResource::collection($transactions),
            'stats' => $stats,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $transaction = Transaction::with('tenant:id,name,slug')->findOrFail($id);

        $this->authorize('view', $transaction);

        return response()->json([
            'transaction' => new TransactionResource($transaction),
        ]);
    }
}
