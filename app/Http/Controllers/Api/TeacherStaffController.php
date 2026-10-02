<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PlatformNotification;
use App\Models\TeacherInvitation;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\FeatureService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

class TeacherStaffController extends Controller
{
    private function requireOwner(Request $request): TenantUser
    {
        $pivot = $request->user()->tenantPivots()
            ->where('tenant_id', $request->current_tenant->id)
            ->first();

        $isOwner = $request->current_tenant->owner_id === $request->user()->id;

        abort_if(! $pivot || (! $isOwner && ! in_array($pivot->role, ['owner', 'admin'], true)), 403, 'Owner access required.');

        return $pivot;
    }

    private function ensureMember(Request $request): TenantUser
    {
        $pivot = $request->user()->tenantPivots()
            ->where('tenant_id', $request->current_tenant->id)
            ->first();

        abort_if(! $pivot || ! in_array($pivot->role, ['teacher', 'owner', 'admin'], true), 403, 'Teacher access required.');

        return $pivot;
    }

    private function requirePermission(Request $request, string $permission): void
    {
        $user = $request->user();

        if ($user->is_super_admin) {
            return;
        }

        $pivot = $user->tenantPivots()
            ->where('tenant_id', $request->current_tenant->id)
            ->where('status', 'active')
            ->first();

        abort_if(! $pivot || ! in_array($pivot->role, ['teacher', 'owner', 'admin'], true), 403, 'Teacher access required.');

        if (in_array($pivot->role, ['owner', 'admin'], true)) {
            return;
        }

        abort_unless($user->canInTenant($request->current_tenant->id, $permission), 403, 'This permission is not granted to your account.');
    }

    private function validateStaffPermissions(mixed $permissions): array
    {
        $permissions = (array) $permissions;

        $unknown = array_diff($permissions, TeacherInvitation::PERMISSION_CATALOG);
        abort_if($unknown !== [], 422, 'Unknown staff permission(s): '.implode(', ', $unknown));

        return array_values(array_unique($permissions));
    }

    private function enforcePlan(Request $request, FeatureService $features, ?string $excludedEmail = null): void
    {
        if (! $features->hasFeature('multi_teacher_enabled')) {
            abort(403, 'Multi-teacher support is not included in your current plan.');
        }

        $tenantId = $request->current_tenant->id;
        $used = TenantUser::where('tenant_id', $tenantId)
            ->whereIn('role', ['teacher', 'admin'])
            ->where('status', 'active')
            ->count();

        $limit = $features->getFeatureValue('teachers_limit', '0');

        if ($limit !== 'unlimited' && (int) $limit > 0) {
            $pending = TeacherInvitation::where('tenant_id', $tenantId)
                ->where('status', TeacherInvitation::STATUS_PENDING)
                ->where('expires_at', '>', now())
                ->when($excludedEmail, fn ($q) => $q->where('email', '!=', $excludedEmail))
                ->count();

            if ($used + $pending >= (int) $limit) {
                abort(422, "Teacher limit reached: you have used {$used} of {$limit} teachers.");
            }
        }
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureMember($request);

        $teachers = TenantUser::where('tenant_id', $request->current_tenant->id)
            ->whereIn('role', ['teacher', 'owner', 'admin'])
            ->where('status', 'active')
            ->with('user')
            ->orderBy('joined_at')
            ->get();

        return response()->json([
            'teachers' => $teachers->map(fn (TenantUser $pivot) => $this->memberPayload($pivot)),
            'invitations' => TeacherInvitation::where('tenant_id', $request->current_tenant->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (TeacherInvitation $inv) => [
                    'id' => $inv->id,
                    'email' => $inv->email,
                    'role' => $inv->role,
                    'permissions' => $inv->permissions,
                    'status' => $inv->status,
                    'expires_at' => $inv->expires_at?->toIso8601String(),
                    'created_at' => $inv->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function invite(Request $request, FeatureService $features): JsonResponse
    {
        $pivot = $this->requireOwner($request);

        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'role' => 'nullable|in:teacher,admin',
            'permissions' => 'nullable|array',
        ]);

        $this->enforcePlan($request, $features);

        $email = strtolower($validated['email']);
        $existing = TenantUser::where('tenant_id', $request->current_tenant->id)
            ->whereHas('user', fn ($q) => $q->where('email', $email))
            ->first();
        abort_if($existing, 422, 'This user is already a member of your institution.');

        $duplicate = TeacherInvitation::where('tenant_id', $request->current_tenant->id)
            ->where('email', $email)
            ->where('status', TeacherInvitation::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->first();
        abort_if($duplicate, 422, 'An invitation for this email is already pending.');

        $permissions = isset($validated['permissions'])
            ? $this->validateStaffPermissions($validated['permissions'])
            : [];

        $invitation = TeacherInvitation::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $request->current_tenant->id,
            'email' => $email,
            'role' => $validated['role'] ?? 'teacher',
            'permissions' => $permissions,
            'token' => Str::random(64),
            'status' => TeacherInvitation::STATUS_PENDING,
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        $tenant = Tenant::find($request->current_tenant->id);
        // Always build the accept link from the configured frontend URL —
        // never from the request Host header (host-header poisoning would
        // let an attacker deliver the invite token to their own domain).
        $frontend = rtrim(config('app.frontend_url') ?: config('app.url'), '/');
        $acceptUrl = $frontend.'/invite/'.$invitation->token;
        $user = $request->user();

        try {
            Mail::to($email)->send(
                new PlatformNotification(
                    title: 'دعوة للانضمام كمعلم',
                    body: "تمت دعوتك للانضمام إلى «{$tenant->name}» كمعلم. اضغط الرابط للقبول: {$acceptUrl}",
                    data: ['action_url' => $acceptUrl, 'action_text' => 'قبول الدعوة'],
                )
            );
        } catch (\Throwable $e) {
            Log::warning('Teacher invitation email failed', ['error' => $e->getMessage()]);
        }

        app(NotificationService::class)->notify(
            $user,
            'teacher.invitation_sent',
            'تم إرسال دعوة المعلم',
            "تم إرسال دعوة إلى {$email} للانضمام إلى منصتك",
            null,
            $request->current_tenant->id,
            ['invitation_id' => $invitation->id],
        );

        return response()->json([
            'message' => 'Invitation sent successfully.',
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'permissions' => $invitation->permissions,
                'status' => $invitation->status,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function cancelInvitation(Request $request, TeacherInvitation $invitation): JsonResponse
    {
        $this->requireOwner($request);

        abort_if($invitation->tenant_id !== $request->current_tenant->id, 404, 'Invitation not found.');
        abort_if($invitation->status !== TeacherInvitation::STATUS_PENDING, 422, 'Invitation is no longer pending.');

        $invitation->update(['status' => TeacherInvitation::STATUS_CANCELLED]);

        return response()->json(['message' => 'Invitation cancelled.']);
    }

    public function acceptInvitation(Request $request, string $token): JsonResponse
    {
        $invitation = TeacherInvitation::where('token', $token)->firstOrFail();

        abort_if($invitation->status !== TeacherInvitation::STATUS_PENDING, 422, 'This invitation is no longer valid.');
        abort_if($invitation->expires_at->isPast(), 422, 'This invitation has expired.');

        $user = $request->user();

        abort_if(strtolower($user->email) !== strtolower($invitation->email), 403, 'This invitation was sent to a different email address.');

        $tenant = Tenant::find($invitation->tenant_id);
        abort_if(! $tenant, 404, 'Institution not found.');

        $membership = TenantUser::where('tenant_id', $invitation->tenant_id)
            ->where('user_id', $user->id)
            ->first();

        if ($membership) {
            $membership->update([
                'role' => $invitation->role,
                'status' => 'active',
                'metadata' => array_merge($membership->metadata ?? [], ['permissions' => $invitation->permissions]),
            ]);
        } else {
            TenantUser::create([
                'tenant_id' => $invitation->tenant_id,
                'user_id' => $user->id,
                'role' => $invitation->role,
                'status' => 'active',
                'metadata' => ['permissions' => $invitation->permissions],
            ]);
        }

        if (! in_array('Teacher', $user->getRoleNames()->all(), true)) {
            $user->assignRole('Teacher');
        }

        $invitation->update(['status' => TeacherInvitation::STATUS_ACCEPTED, 'accepted_at' => now()]);

        $inviter = User::find($invitation->invited_by);
        if ($inviter) {
            app(NotificationService::class)->notify(
                $inviter,
                'teacher.invitation_accepted',
                'تم قبول الدعوة',
                "{$user->name} انضم إلى منصتك كمعلم",
                $user,
                $invitation->tenant_id,
                ['invitation_id' => $invitation->id],
            );
        }

        return response()->json([
            'message' => 'Invitation accepted. Welcome to the team!',
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'role' => $invitation->role,
            ],
        ]);
    }

    public function updateMember(Request $request, User $member): JsonResponse
    {
        $this->requireOwner($request);

        $pivot = TenantUser::where('tenant_id', $request->current_tenant->id)
            ->where('user_id', $member->id)
            ->whereIn('role', ['teacher', 'admin'])
            ->first();
        abort_if(! $pivot, 404, 'Member not found.');

        $validated = $request->validate([
            'role' => 'nullable|in:teacher,admin',
            'permissions' => 'nullable|array',
            'status' => 'nullable|in:active,inactive',
        ]);

        $data = [];
        if (isset($validated['role'])) {
            $data['role'] = $validated['role'];
        }
        if (isset($validated['status'])) {
            $data['status'] = $validated['status'];
        }
        if (isset($validated['permissions'])) {
            $data['metadata'] = array_merge(
                $pivot->metadata ?? [],
                ['permissions' => $this->validateStaffPermissions($validated['permissions'])]
            );
        }

        $pivot->update($data);

        return response()->json(['message' => 'Member updated.', 'teacher' => $this->memberPayload($pivot->fresh(['user']))]);
    }

    public function removeMember(Request $request, User $member): JsonResponse
    {
        $pivot = $this->requireOwner($request);

        $target = TenantUser::where('tenant_id', $request->current_tenant->id)
            ->where('user_id', $member->id)
            ->whereIn('role', ['teacher', 'admin'])
            ->first();
        abort_if(! $target, 404, 'Member not found.');
        abort_if($target->user_id === $pivot->user_id, 422, 'You cannot remove yourself.');

        $target->update(['status' => 'inactive']);

        return response()->json(['message' => 'Member removed.']);
    }

    private function memberPayload(TenantUser $pivot): array
    {
        $user = $pivot->user;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => $user->avatar_url,
            'role' => $pivot->role,
            'status' => $pivot->status,
            'permissions' => $pivot->metadata['permissions'] ?? [],
            'joined_at' => $pivot->joined_at?->toIso8601String(),
        ];
    }
}
