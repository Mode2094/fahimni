<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScheduledLesson;
use App\Rules\TenantScopedExists;
use App\Traits\AuthorizesTenantAccess;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

class ScheduledLessonController extends Controller
{
    use AuthorizesTenantAccess;

    private const RECUR_WEEKS = 12;

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->current_tenant->id;

        $lessons = ScheduledLesson::where('tenant_id', $tenantId)
            ->orderBy('lesson_date')
            ->get();

        $items = [];
        foreach ($lessons as $lesson) {
            foreach ($this->instances($lesson) as $date) {
                $item = $this->payload($lesson, $date);
                if ($request->boolean('upcoming') && $item['is_past']) {
                    continue;
                }
                $items[] = $item;
            }
        }

        usort($items, fn ($a, $b) => strcmp($a['instance_date'], $b['instance_date']));

        return response()->json(['lessons' => $items]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $lesson = ScheduledLesson::where('tenant_id', $request->current_tenant->id)
            ->findOrFail($id);

        return response()->json(['lesson' => $this->payload($lesson)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'lesson_date' => 'required|date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'is_recurring' => 'nullable|boolean',
            'repeat_until' => 'nullable|date|after_or_equal:lesson_date',
            'provider' => 'nullable|string|max:50',
            'meeting_url' => 'nullable|string|max:500',
            'course_id' => ['nullable', 'string', new TenantScopedExists('courses')],
        ]);

        $isRecurring = filter_var($validated['is_recurring'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $lessonDate = Carbon::parse($validated['lesson_date']);

        $lesson = ScheduledLesson::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $request->current_tenant->id,
            'course_id' => $validated['course_id'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'lesson_date' => $lessonDate->toDateString(),
            'start_time' => $validated['start_time'] ?? null,
            'end_time' => $validated['end_time'] ?? null,
            'is_recurring' => $isRecurring,
            'day_of_week' => $isRecurring ? $lessonDate->dayOfWeek : null,
            'repeat_until' => $isRecurring
                ? ($validated['repeat_until'] ?? $lessonDate->copy()->addWeeks(self::RECUR_WEEKS)->toDateString())
                : null,
            'provider' => $validated['provider'] ?? 'custom',
            'meeting_url' => $this->normalizeUrl($validated['meeting_url'] ?? null),
            'status' => 'scheduled',
        ]);

        return response()->json(['lesson' => $this->payload($lesson)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $lesson = ScheduledLesson::where('tenant_id', $request->current_tenant->id)
            ->findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'lesson_date' => 'sometimes|date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'is_recurring' => 'nullable|boolean',
            'repeat_until' => 'nullable|date|after_or_equal:lesson_date',
            'provider' => 'nullable|string|max:50',
            'meeting_url' => 'nullable|string|max:500',
            'status' => 'nullable|string|in:scheduled,completed,cancelled',
        ]);

        $isRecurring = array_key_exists('is_recurring', $validated)
            ? filter_var($validated['is_recurring'], FILTER_VALIDATE_BOOLEAN)
            : $lesson->is_recurring;

        $lessonDate = Carbon::parse($validated['lesson_date'] ?? $lesson->lesson_date);

        $lesson->update([
            'title' => $validated['title'] ?? $lesson->title,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $lesson->description,
            'lesson_date' => $lessonDate->toDateString(),
            'start_time' => array_key_exists('start_time', $validated) ? ($validated['start_time'] ?: null) : $lesson->start_time,
            'end_time' => array_key_exists('end_time', $validated) ? ($validated['end_time'] ?: null) : $lesson->end_time,
            'is_recurring' => $isRecurring,
            'day_of_week' => $isRecurring ? $lessonDate->dayOfWeek : null,
            'repeat_until' => array_key_exists('repeat_until', $validated)
                ? ($isRecurring ? ($validated['repeat_until'] ?: $lessonDate->copy()->addWeeks(self::RECUR_WEEKS)->toDateString()) : null)
                : $lesson->repeat_until,
            'provider' => $validated['provider'] ?? $lesson->provider,
            'meeting_url' => array_key_exists('meeting_url', $validated)
                ? $this->normalizeUrl($validated['meeting_url'])
                : $lesson->meeting_url,
            'status' => $validated['status'] ?? $lesson->status,
        ]);

        return response()->json(['lesson' => $this->payload($lesson->fresh())]);
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $lesson = ScheduledLesson::where('tenant_id', $request->current_tenant->id)
            ->findOrFail($id);

        $lesson->update(['status' => 'completed']);

        return response()->json(['lesson' => $this->payload($lesson->fresh())]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $lesson = ScheduledLesson::where('tenant_id', $request->current_tenant->id)
            ->findOrFail($id);

        $lesson->update(['status' => 'cancelled']);

        return response()->json(['lesson' => $this->payload($lesson->fresh())]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $lesson = ScheduledLesson::where('tenant_id', $request->current_tenant->id)
            ->findOrFail($id);

        $lesson->delete();

        return response()->json(['message' => 'Scheduled lesson deleted.'], 200);
    }

    /**
     * Students see upcoming, non-cancelled lessons of THEIR teachers only —
     * never every teacher's schedule in the tenant.
     */
    public function upcoming(Request $request): JsonResponse
    {
        $tenantId = $request->current_tenant->id;
        $today = Carbon::today();

        // Students see the schedule but never the meeting join link — it is
        // released by the teacher through the lesson itself.
        $isStaff = $this->isTenantStaff($request, $tenantId);

        $lessons = ScheduledLesson::where('tenant_id', $tenantId)
            ->where('status', '!=', 'cancelled')
            ->when(! $isStaff, function ($q) use ($request) {
                // Scope to the courses the student is enrolled in; staff see
                // the whole tenant schedule. Unbound sessions (no course_id)
                // stay invisible to students to avoid leaking every
                // teacher's calendar.
                $q->whereHas('course.enrollments', function ($eq) use ($request) {
                    $eq->where('user_id', $request->user()->id)
                        ->whereIn('status', ['active', 'completed']);
                });
            })
            ->orderBy('lesson_date')
            ->get();

        $items = [];
        foreach ($lessons as $lesson) {
            foreach ($this->instances($lesson, $today) as $date) {
                if ($date->lt($today)) {
                    continue;
                }
                $items[] = $this->payload($lesson, $date, includeMeeting: $isStaff);
            }
        }

        usort($items, fn ($a, $b) => strcmp($a['instance_date'], $b['instance_date']));

        return response()->json(['lessons' => array_slice($items, 0, 50)]);
    }

    /**
     * @return array<int, Carbon>
     */
    private function instances(ScheduledLesson $lesson, ?Carbon $from = null): array
    {
        $start = $lesson->lesson_date;
        $from ??= $start->copy();

        if (! $lesson->is_recurring) {
            return [$start];
        }

        $end = $lesson->repeat_until
            ?? $start->copy()->addWeeks(self::RECUR_WEEKS);

        // A 10-year repeat_until would generate 520 rows per request —
        // hard-cap the horizon and the instance count.
        if ($end->gt($start->copy()->addWeeks(self::RECUR_WEEKS * 2))) {
            $end = $start->copy()->addWeeks(self::RECUR_WEEKS * 2);
        }

        $dates = [];
        $cursor = $start->copy();
        while ($cursor->lte($end) && count($dates) < 200) {
            $dates[] = $cursor->copy();
            $cursor->addWeek();
        }

        return $dates;
    }

    private function payload(ScheduledLesson $lesson, ?Carbon $instance = null, bool $includeMeeting = true): array
    {
        $date = $instance ?? $lesson->lesson_date;
        $today = Carbon::today();

        return [
            'id' => $lesson->id,
            'instance_key' => $lesson->id.'::'.$date->toDateString(),
            'title' => $lesson->title,
            'description' => $lesson->description,
            'instance_date' => $date->toDateString(),
            'start_time' => $lesson->start_time,
            'end_time' => $lesson->end_time,
            'is_recurring' => $lesson->is_recurring,
            'day_of_week' => $lesson->day_of_week,
            'repeat_until' => $lesson->repeat_until?->toDateString(),
            'provider' => $lesson->provider,
            'meeting_url' => $includeMeeting ? $lesson->meeting_url : null,
            'status' => $lesson->status,
            'is_past' => $date->lt($today),
            'is_today' => $date->isSameDay($today),
        ];
    }

    private function normalizeUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        if (! Str::startsWith($url, ['http://', 'https://'])) {
            $url = 'https://'.$url;
        }

        return $url;
    }
}
