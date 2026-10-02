<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Card\GenerateCardsRequest;
use App\Http\Requests\Card\ImportCardsRequest;
use App\Http\Requests\Card\RedeemCardRequest;
use App\Http\Resources\ActivationCardResource;
use App\Http\Resources\CourseResource;
use App\Http\Resources\EnrollmentResource;
use App\Models\ActivationCard;
use App\Models\CardRedemption;
use App\Models\Course;
use App\Models\Enrollment;
use App\Plans\QuotaService;
use App\Services\StudentGamificationService;
use App\Support\AuditLogger;
use App\Support\CardExcelParser;
use App\Support\DevicePolicy;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

class ActivationCardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ActivationCard::class);

        $cards = ActivationCard::with(['course', 'student.roles', 'redemptions.course'])
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->course_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('batch_id'), fn ($q) => $q->where('batch_id', $request->batch_id))
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'cards' => ActivationCardResource::collection($cards),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $card = ActivationCard::with(['course', 'student', 'redemptions'])->findOrFail($id);

        $this->authorize('view', $card);

        return response()->json([
            'card' => new ActivationCardResource($card),
        ]);
    }

    public function generate(GenerateCardsRequest $request): JsonResponse
    {
        $this->authorize('create', ActivationCard::class);

        app(QuotaService::class)->assertCan($request->current_tenant->id, 'cards', $request->integer('count', 1));

        $course = Course::findOrFail($request->course_id);
        $tenantId = $request->header('X-Tenant-Id');
        $count = $request->integer('count');
        $batchId = $request->input('batch_id', (string) UuidV7::generate());

        $cards = [];
        for ($i = 0; $i < $count; $i++) {
            $cards[] = [
                'id' => UuidV7::generate(),
                'tenant_id' => $tenantId,
                'course_id' => $course->id,
                'batch_id' => $batchId,
                'code' => strtoupper(substr(bin2hex(random_bytes(12)), 0, 24)),
                'status' => 'generated',
                'expires_at' => $request->input('expires_at'),
                'metadata' => json_encode($request->input('metadata', [])),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        ActivationCard::insert($cards);

        $created = ActivationCard::where('batch_id', $batchId)->get();

        return response()->json([
            'message' => "$count card(s) generated successfully.",
            'batch_id' => $batchId,
            'cards' => ActivationCardResource::collection($created),
        ], 201);
    }

    public function redeem(RedeemCardRequest $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $request->header('X-Tenant-Id');

        $this->authorize('redeem', ActivationCard::class);

        $card = ActivationCard::where('code', $request->code)
            ->where('status', 'generated')
            ->first();

        if (! $card) {
            return response()->json(['message' => 'Invalid or already used card code.'], 404);
        }

        if ($card->expires_at && now()->gt($card->expires_at)) {
            $card->update(['status' => 'expired']);

            return response()->json(['message' => 'Card has expired.'], 410);
        }

        if ($card->tenant_id !== $tenantId) {
            return response()->json(['message' => 'Card does not belong to this tenant.'], 403);
        }

        $exists = Enrollment::where('course_id', $card->course_id)
            ->where('user_id', $user->id)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Already enrolled in this course.'], 409);
        }

        // The redemption must be atomic: lock the card row, re-verify it is
        // still unused, then create the enrollment + redemption + status
        // flip in a single transaction so two concurrent redeemers can never
        // both enroll with the same card.
        try {
            $redemption = DB::transaction(function () use ($card, $user, $request, $tenantId) {
                $locked = ActivationCard::whereKey($card->id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked || $locked->status !== 'generated') {
                    return null;
                }

                if ($locked->expires_at && now()->gt($locked->expires_at)) {
                    $locked->update(['status' => 'expired']);

                    return 'expired';
                }

                // The (course_id, user_id) unique constraint may fire when
                // two different cards for the same course are redeemed
                // concurrently — map it to a clean 409, never a 500.
                try {
                    $enrollment = Enrollment::create([
                        'id' => UuidV7::generate(),
                        'tenant_id' => $tenantId,
                        'course_id' => $locked->course_id,
                        'user_id' => $user->id,
                        'status' => 'active',
                        'enrolled_at' => now(),
                        'source' => 'card',
                        'metadata' => ['card_code' => $locked->code, 'card_id' => $locked->id],
                    ]);
                } catch (QueryException $e) {
                    if ($e->getCode() === '23505' || str_contains($e->getMessage(), 'UNIQUE constraint')) {
                        throw new HttpResponseException(
                            response()->json(['message' => 'You are already enrolled in this course.'], 409)
                        );
                    }
                    throw $e;
                }

                $deviceId = $request->header('X-Device-Id') ?? $request->input('device_id');
                if ($deviceId) {
                    DevicePolicy::register($request, $tenantId, $user);
                }

                $locked->update([
                    'status' => 'redeemed',
                    'student_id' => $user->id,
                    'used_at' => now(),
                    'metadata' => array_merge($locked->metadata ?? [], ['device_id' => $deviceId]),
                ]);

                CardRedemption::create([
                    'id' => UuidV7::generate(),
                    'tenant_id' => $tenantId,
                    'card_id' => $locked->id,
                    'course_id' => $locked->course_id,
                    'student_id' => $user->id,
                    'enrollment_id' => $enrollment->id,
                    'redeemed_at' => now(),
                    'ip_address' => $request->ip(),
                    'metadata' => ['device_id' => $deviceId],
                ]);

                return $enrollment;
            });
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }

        if ($redemption === null) {
            return response()->json(['message' => 'Invalid or already used card code.'], 404);
        }

        if ($redemption === 'expired') {
            return response()->json(['message' => 'Card has expired.'], 410);
        }

        app(StudentGamificationService::class)->logActivity(
            $user,
            $tenantId,
            'enrollment',
            'سجّل في الكورس: '.($redemption->course?->title ?? ''),
            ['course_id' => $redemption->course_id, 'source' => 'card']
        );

        app(StudentGamificationService::class)->checkAchievements($user, $tenantId);

        AuditLogger::log($request, 'card.redeemed', 'card', $card->id, ['code' => $card->code, 'course_id' => $card->course_id]);

        return response()->json([
            'message' => 'Card redeemed successfully. You are now enrolled.',
            'enrollment' => new EnrollmentResource($redemption->load('course')),
        ]);
    }

    public function cancel(string $id): JsonResponse
    {
        $card = ActivationCard::findOrFail($id);

        $this->authorize('create', ActivationCard::class);

        if ($card->status === 'redeemed') {
            return response()->json(['message' => 'Cannot cancel a redeemed card.'], 422);
        }

        $card->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        return response()->json([
            'card' => new ActivationCardResource($card->fresh()),
        ]);
    }

    public function import(ImportCardsRequest $request): JsonResponse
    {
        $this->authorize('create', ActivationCard::class);

        try {
            $raw = CardExcelParser::parse($request->file('file')->getRealPath(), $request->file('file')->getClientOriginalName());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not read file: '.$e->getMessage()], 422);
        }

        $normalized = collect($raw)
            ->map(fn ($code) => strtoupper(preg_replace('/\s+/', '', trim((string) $code))))
            ->filter(fn ($code) => strlen($code) >= 6)
            ->unique()
            ->values();

        // Quota applies to the rows that will actually be created — parsing
        // first means an oversized file is rejected cleanly and short
        // codes/duplicates don't consume the cards quota.
        app(QuotaService::class)->assertCan($request->current_tenant->id, 'cards', $normalized->count());

        $course = Course::findOrFail($request->course_id);
        $tenantId = $request->header('X-Tenant-Id');
        $batchId = $request->input('batch_id') ?: (string) UuidV7::generate();

        $existing = ActivationCard::whereIn('code', $normalized)->pluck('code')->flip();
        // Duplicates in file = rows that were dropped by normalization
        // (short codes, whitespace) OR repeated in the file.
        $duplicatesInFile = count($raw) - $normalized->uniqueStrict()->count();

        $now = now();
        $rows = [];
        foreach ($normalized as $code) {
            if ($existing->has($code)) {
                continue;
            }
            $rows[] = [
                'id' => UuidV7::generate(),
                'tenant_id' => $tenantId,
                'course_id' => $course->id,
                'batch_id' => $batchId,
                'code' => $code,
                'status' => 'generated',
                'expires_at' => $request->input('expires_at'),
                'metadata' => json_encode(array_merge($request->input('metadata', []), ['source' => 'import'])),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $skippedDb = count($normalized) - count($rows);

        if ($rows !== []) {
            ActivationCard::insert($rows);
        }

        $created = ActivationCard::where('batch_id', $batchId)->get();

        return response()->json([
            'message' => count($rows).' card(s) imported successfully.',
            'batch_id' => $batchId,
            'summary' => [
                'total_rows' => count($raw),
                'imported' => count($rows),
                'duplicates_in_file' => $duplicatesInFile,
                'duplicates_in_db' => $skippedDb,
            ],
            'cards' => ActivationCardResource::collection($created),
        ], 201);
    }

    public function importTemplate(): JsonResponse
    {
        $this->authorize('create', ActivationCard::class);

        $content = "\xEF\xBB\xBFcode\nABC123XYZ\nDEF456QRS\nGHI789TUV\n";

        return response()->json(['content' => $content]);
    }

    public function batches(): JsonResponse
    {
        $this->authorize('viewAny', ActivationCard::class);

        // NOTE: MAX()/MIN() on UUID columns is invalid in PostgreSQL, so the
        // course/date info is derived from the batch's own cards instead.
        $batches = ActivationCard::query()
            ->select('batch_id', DB::raw('MIN(created_at) as created_at'), DB::raw('MAX(expires_at) as expires_at'))
            ->whereNotNull('batch_id')
            ->groupBy('batch_id')
            ->orderByDesc('created_at')
            ->get();

        $batchIds = $batches->pluck('batch_id');

        $cards = ActivationCard::with(['course', 'student.roles'])
            ->whereIn('batch_id', $batchIds)
            ->get()
            ->groupBy('batch_id');

        $result = $batches->map(function ($batch) use ($cards) {
            $batchCards = $cards[$batch->batch_id] ?? collect();

            return [
                'batch_id' => $batch->batch_id,
                'course' => $batchCards->first()?->course
                    ? new CourseResource($batchCards->first()->course)
                    : null,
                'created_at' => $batch->created_at,
                'expires_at' => $batch->expires_at,
                'total' => $batchCards->count(),
                'redeemed' => $batchCards->where('status', 'redeemed')->count(),
                'cancelled' => $batchCards->where('status', 'cancelled')->count(),
                'expired' => $batchCards->where('status', 'expired')->count(),
                'generated' => $batchCards->where('status', 'generated')->count(),
                'cards' => ActivationCardResource::collection($batchCards),
            ];
        });

        return response()->json(['batches' => $result]);
    }

    public function batchInfo(string $batchId): JsonResponse
    {
        $this->authorize('viewAny', ActivationCard::class);

        $cards = ActivationCard::with(['course', 'student'])
            ->where('batch_id', $batchId)
            ->get();

        return response()->json([
            'batch_id' => $batchId,
            'total' => $cards->count(),
            'redeemed' => $cards->where('status', 'redeemed')->count(),
            'cancelled' => $cards->where('status', 'cancelled')->count(),
            'expired' => $cards->where('status', 'expired')->count(),
            'generated' => $cards->where('status', 'generated')->count(),
            'cards' => ActivationCardResource::collection($cards),
        ]);
    }
}
