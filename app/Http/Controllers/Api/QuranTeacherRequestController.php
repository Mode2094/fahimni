<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PlatformNotification;
use App\Models\QuranTeacherRequest;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Email;
use Illuminate\Validation\ValidationException;

class QuranTeacherRequestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'email'           => ['required', 'string', 'max:255', new Email],
            'phone'           => 'nullable|string|max:50',
            'city'            => 'nullable|string|max:255',
            'country'         => 'nullable|string|max:2',
            'memorized_juz'   => 'nullable|integer|between:1,30',
            'tajweed_level'   => 'nullable|string|in:beginner,intermediate,advanced,proficient,ijazah',
            'message'         => 'nullable|string|max:2000',
        ]);

        $existing = QuranTeacherRequest::where('email', $validated['email'])
            ->whereIn('status', [QuranTeacherRequest::STATUS_PENDING, QuranTeacherRequest::STATUS_APPROVED])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => $existing->status === QuranTeacherRequest::STATUS_APPROVED
                    ? 'تمت الموافقة على طلبك مسبقاً. تحقق من بريدك الإلكتروني.'
                    : 'يوجد طلب مسبق بنفس البريد قيد المراجعة.',
            ], 422);
        }

        $requestModel = QuranTeacherRequest::create($validated);

        return response()->json([
            'message' => 'تم استلام طلبك بنجاح. سنراجعه ونتواصل معك قريباً.',
            'id'      => $requestModel->id,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $query = QuranTeacherRequest::query()
            ->with('plan:id,name,slug')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%")
                    ->orWhere('city', 'ilike', "%{$search}%");
            });
        }

        $requests = $query->paginate($request->integer('per_page', 20));

        return response()->json($requests);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $qr = QuranTeacherRequest::findOrFail($id);

        $validated = $request->validate([
            'status'      => 'required|in:pending,approved,rejected',
            'admin_notes' => 'nullable|string|max:2000',
            'plan_id'     => 'nullable|uuid|exists:subscription_plans,id',
        ]);

        $qr->update([
            'status'       => $validated['status'],
            'admin_notes'  => $validated['admin_notes'] ?? $qr->admin_notes,
            'plan_id'      => $validated['plan_id'] ?? $qr->plan_id,
            'approved_by'  => $request->user()->id,
            'reviewed_at'  => now(),
        ]);

        if ($validated['status'] === QuranTeacherRequest::STATUS_APPROVED) {
            $user = $this->createAccount($qr);
            $this->sendApprovalEmail($user, $qr);
        }

        return response()->json([
            'message' => match ($validated['status']) {
                'approved' => 'تمت الموافقة على الطلب وإنشاء الحساب. تم إرسال رابط تسجيل الدخول.',
                'rejected' => 'تم رفض الطلب.',
                default    => 'تم تحديث الحالة.',
            },
        ]);
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'total'    => QuranTeacherRequest::count(),
            'pending'  => QuranTeacherRequest::where('status', 'pending')->count(),
            'approved' => QuranTeacherRequest::where('status', 'approved')->count(),
            'rejected' => QuranTeacherRequest::where('status', 'rejected')->count(),
        ]);
    }

    /**
     * Create a User account with "Teacher" role for an approved Quran teacher request.
     */
    private function createAccount(QuranTeacherRequest $qr): \App\Models\User
    {
        $user = \App\Models\User::firstOrCreate(
            ['email' => $qr->email],
            [
                'name'     => $qr->name,
                'phone'    => $qr->phone,
                'password' => bcrypt('quran-' . substr(md5($qr->email), 0, 8)),
                'is_active' => true,
            ]
        );

        if (!$user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        if (!$user->hasRole('Teacher')) {
            $user->assignRole('Teacher');
        }

        return $user;
    }

    /**
     * Send approval email with password reset link to the newly created teacher.
     */
    private function sendApprovalEmail(\App\Models\User $user, QuranTeacherRequest $qr): void
    {
        try {
            // Create a password reset token via the built-in broker
            $broker = \Illuminate\Support\Facades\Password::broker();
            $token = $broker->createToken($user);

            // Build a reset URL pointing to the frontend
            $resetUrl = config('app.frontend_url', 'http://localhost:3000')
                . '/ar/reset-password?token=' . $token
                . '&email=' . urlencode($user->email);

            $planName = $qr->plan?->name;

            $body = "أهلاً {$user->name}،\n\n";
            $body .= "تمت الموافقة على طلبك كمعلم قرءان في منصة شahead.\n\n";
            $body .= "لإنشاء كلمة المرور والدخول للمنصة، اضغط على الرابط التالي:\n";
            $body .= "{$resetUrl}\n\n";
            $body .= "بيانات الدخول:\n";
            $body .= "- البريد: {$user->email}\n";
            if ($planName) {
                $body .= "- الخطة: {$planName}\n";
            }

            Mail::to($user->email)->queue(
                new PlatformNotification(
                    title: 'مرحباً بك في شahead — تم تفعيل حسابك كمعلم قرءان',
                    body: $body,
                    data: ['action_url' => $resetUrl, 'action_text' => 'تسجيل الدخول'],
                )
            );
        } catch (\Throwable $e) {
            Log::error('Quran teacher approval email failed', [
                'user_id' => $user->id,
                'email'   => $user->email,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
