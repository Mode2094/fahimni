<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Traits\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use AuthorizesTenantAccess;

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id');
        $user = $request->user();

        if ($user->is_super_admin) {
            return $this->superAdminDashboard();
        }

        $pivot = $user->tenantPivots()
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $pivot) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        return $this->isTenantStaff($request, $tenantId)
            ? $this->teacherDashboard($tenantId, $user)
            : $this->studentDashboard($tenantId, $user);
    }

    private function superAdminDashboard(): JsonResponse
    {
        return response()->json([
            'total_tenants' => Tenant::count(),
            'total_users' => User::count(),
            'total_students' => Enrollment::distinct('user_id')->count('user_id'),
            'total_courses' => Course::count(),
            'active_subscriptions' => TenantSubscription::whereIn('status', ['active', 'trialing'])->count(),
            'total_revenue' => Payment::where('status', 'completed')->sum('amount'),
            'recent_payments' => Payment::with('subscription')
                ->orderByDesc('created_at')
                ->take(10)
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'amount' => $p->amount,
                    'currency' => $p->currency,
                    'status' => $p->status,
                    'created_at' => $p->created_at,
                ]),
        ]);
    }

    private function teacherDashboard(string $tenantId, $user): JsonResponse
    {
        $activeBookings = Booking::where('tenant_id', $tenantId)
            ->where('teacher_id', $user->id)
            ->whereIn('status', [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED])
            ->where('ends_at', '>', now());

        return response()->json([
            'total_courses' => Course::byTenant($tenantId)->count(),
            'total_students' => Enrollment::byTenant($tenantId)->count(),
            'total_exams' => Exam::byTenant($tenantId)->count(),
            'total_attempts' => ExamAttempt::byTenant($tenantId)->count(),
            'pending_bookings' => (clone $activeBookings)->where('status', Booking::STATUS_PENDING)->count(),
            'upcoming_bookings' => (clone $activeBookings)->count(),
            'recent_bookings' => Booking::where('tenant_id', $tenantId)
                ->where('teacher_id', $user->id)
                ->with('student')
                ->orderByDesc('starts_at')
                ->take(5)
                ->get()
                ->map(fn (Booking $b) => [
                    'id' => $b->id,
                    'kind' => $b->kind,
                    'status' => $b->status,
                    'starts_at' => $b->starts_at?->toIso8601String(),
                    'student' => $b->student ? ['id' => $b->student->id, 'name' => $b->student->name] : null,
                ]),
        ]);
    }

    private function studentDashboard(string $tenantId, $user): JsonResponse
    {
        return response()->json([
            'enrolled_courses' => Enrollment::byTenant($tenantId)
                ->where('user_id', $user->id)
                ->count(),
            'completed_courses' => Enrollment::byTenant($tenantId)
                ->where('user_id', $user->id)
                ->where('status', 'completed')
                ->count(),
            'upcoming_exams' => Exam::byTenant($tenantId)
                ->where('is_published', true)
                ->count(),
        ]);
    }
}
