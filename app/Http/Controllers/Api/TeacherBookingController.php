<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeacherBookingController extends Controller
{
    private function ensureTeacher(Request $request): void
    {
        $pivot = $request->user()->tenantPivots()
            ->where('tenant_id', $request->current_tenant->id)
            ->first();

        abort_if(! $pivot || ! in_array($pivot->role, ['teacher', 'owner', 'admin'], true), 403, 'Teacher access required.');
    }

    private function scope(Request $request, ?Builder $query = null)
    {
        $query = $query ?? Booking::query();
        $query->where('tenant_id', $request->current_tenant->id);

        $pivot = $request->user()->tenantPivots()
            ->where('tenant_id', $request->current_tenant->id)
            ->first();

        // Team members see only their own bookings; owner/admin see all.
        if ($pivot && $pivot->role === 'teacher') {
            $query->where('teacher_id', $request->user()->id);
        }

        return $query;
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureTeacher($request);

        $status = $request->input('status');
        $kind = $request->input('kind');

        $query = $this->scope($request);

        if ($status && in_array($status, Booking::STATUSES, true)) {
            $query->where('status', $status);
        }

        if ($kind && in_array($kind, [Booking::KIND_APPOINTMENT, Booking::KIND_SERVICE], true)) {
            $query->where('kind', $kind);
        }

        $bookings = $query->with(['student', 'service', 'payment'])
            ->orderByDesc('starts_at')
            ->paginate(min(max((int) $request->input('per_page', 20), 1), 100));

        return response()->json([
            'bookings' => $bookings->through(function (Booking $b) {
                return $this->payload($b);
            }),
        ]);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $this->ensureTeacher($request);

        $booking = $this->scope($request, Booking::where('id', $booking->id))
            ->with(['student', 'service', 'payment'])
            ->firstOrFail();

        return response()->json(['booking' => $this->payload($booking)]);
    }

    public function updateStatus(Request $request, Booking $booking): JsonResponse
    {
        $this->ensureTeacher($request);

        $booking = $this->scope($request, Booking::where('id', $booking->id))->firstOrFail();

        $validated = $request->validate([
            'status' => ['required', Rule::in([Booking::STATUS_CONFIRMED, Booking::STATUS_COMPLETED, Booking::STATUS_CANCELLED])],
            'meeting_url' => 'nullable|url|max:500',
        ]);

        $booking->update([
            'status' => $validated['status'],
            'meeting_url' => $validated['meeting_url'] ?? $booking->meeting_url,
        ]);

        if (in_array($booking->status, [Booking::STATUS_CONFIRMED, Booking::STATUS_CANCELLED], true)) {
            $this->notifyStudent($booking, $booking->status === Booking::STATUS_CONFIRMED ? 'confirmed' : 'cancelled');
        }

        return response()->json(['booking' => $this->payload($booking->fresh(['student', 'service']))]);
    }

    private function notifyStudent(Booking $booking, string $event): void
    {
        $title = match ($event) {
            'confirmed' => 'تم تأكيد حجزك',
            default => 'تم إلغاء الحجز',
        };
        $body = match ($event) {
            'confirmed' => 'تم تأكيد موعدك بنجاح'.($booking->meeting_url ? '، رابط الجلسة جاهز' : ''),
            default => 'تم إلغاء الموعد المقرر',
        };

        $student = User::find($booking->student_id);
        if (! $student) {
            return;
        }

        app(NotificationService::class)->notify(
            $student,
            'booking.'.$event,
            $title,
            $body,
            User::find($booking->teacher_id),
            $booking->tenant_id,
            ['booking_id' => $booking->id],
        );
    }

    private function payload(Booking $b): array
    {
        return [
            'id' => $b->id,
            'kind' => $b->kind,
            'status' => $b->status,
            'starts_at' => $b->starts_at?->toIso8601String(),
            'ends_at' => $b->ends_at?->toIso8601String(),
            'price' => $b->price,
            'currency' => $b->currency,
            'notes' => $b->notes,
            'meeting_url' => $b->meeting_url,
            'student' => $b->student ? ['id' => $b->student->id, 'name' => $b->student->name, 'email' => $b->student->email, 'phone' => $b->student->phone, 'avatar_url' => $b->student->avatar_url] : null,
            'service' => $b->service ? [
                'id' => $b->service->id,
                'type' => $b->service->type,
                'title' => $b->service->title,
                'price' => $b->service->price,
                'duration_minutes' => $b->service->duration_minutes,
            ] : null,
            'payment' => $b->payment ? ['id' => $b->payment->id, 'status' => $b->payment->status, 'gateway' => $b->payment->gateway] : null,
            'created_at' => $b->created_at?->toIso8601String(),
        ];
    }
}
