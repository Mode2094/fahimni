<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunityGroup;
use App\Models\CommunityGroupMember;
use App\Models\CommunityThread;
use App\Plans\QuotaService;
use App\Traits\CommunityPayloads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

class CommunityGroupController extends Controller
{
    use CommunityPayloads;

    private function tenantId(Request $request): string
    {
        return $request->current_tenant->id;
    }

    private function ensureTeacher(Request $request): void
    {
        $user = $request->user();

        if ($user->is_super_admin) {
            return;
        }

        $pivot = $user->tenantPivots()
            ->where('tenant_id', $this->tenantId($request))
            ->first();

        abort_if(! $pivot || ! in_array($pivot->role, ['teacher', 'owner', 'admin'], true), 403, 'Teacher access required.');
    }

    private function canManage(Request $request, CommunityGroup $group): bool
    {
        if ($group->tenant_id !== $this->tenantId($request)) {
            return false;
        }

        $user = $request->user();

        if ($user->is_super_admin) {
            return true;
        }

        $pivot = $user->tenantPivots()
            ->where('tenant_id', $this->tenantId($request))
            ->first();

        if ($pivot && in_array($pivot->role, ['teacher', 'owner', 'admin'], true)) {
            return true;
        }

        return $group->isModerator($user->id);
    }

    private function makeSlug(Request $request, string $name, ?string $ignoreId = null): string
    {
        $base = Str::slug($name, '-', 'en') ?: 'group-'.Str::random(6);
        $slug = $base;
        $i = 1;
        while (CommunityGroup::where('tenant_id', $this->tenantId($request))
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->tenantId($request);

        $groups = CommunityGroup::visibleTo($tenantId, $user->id)
            ->withCount('activeMembers')
            ->withCount('threads')
            ->with(['members' => fn ($q) => $q->where('user_id', $user->id)])
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->search.'%'))
            ->when($request->filled('mine') && filter_var($request->mine, FILTER_VALIDATE_BOOL),
                fn ($q) => $q->whereHas('members', fn ($m) => $m->where('user_id', $user->id)->where('status', 'active')))
            ->orderBy('name')
            ->get();

        return response()->json([
            'groups' => $groups->map(fn (CommunityGroup $g) => $this->groupPayload($g, $user->id)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureTeacher($request);

        app(QuotaService::class)->assertCan($request->current_tenant->id, 'community_groups');

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'cover_image' => 'nullable|string|max:500',
            'visibility' => 'nullable|string|in:public,private',
            'join_policy' => 'nullable|string|in:open,request',
        ]);

        $group = CommunityGroup::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $this->tenantId($request),
            'name' => $validated['name'],
            'slug' => $this->makeSlug($request, $validated['name']),
            'description' => $validated['description'] ?? null,
            'cover_image' => $validated['cover_image'] ?? null,
            'visibility' => $validated['visibility'] ?? 'public',
            'join_policy' => $validated['join_policy'] ?? 'open',
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]);

        CommunityGroupMember::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $this->tenantId($request),
            'group_id' => $group->id,
            'user_id' => $request->user()->id,
            'role' => 'moderator',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return response()->json([
            'group' => $this->groupPayload($group->loadCount('activeMembers')->loadCount('threads'), $request->user()->id),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->tenantId($request);

        $group = CommunityGroup::withCount('activeMembers')->withCount('threads')
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $isTeacher = $this->canManage($request, $group);
        $membership = $group->userMembership($user->id);
        $canSee = $group->visibility === 'public' || $membership?->status === 'active' || $isTeacher;

        if (! $canSee) {
            return response()->json(['message' => 'This group is private.'], 403);
        }

        $threads = CommunityThread::where('tenant_id', $tenantId)
            ->where('group_id', $group->id)
            ->visibleTo($user->id, $isTeacher)
            ->with(['author:id,name,avatar_url', 'group:id,name,slug,visibility'])
            ->orderByDesc('is_pinned')
            ->orderByDesc('last_activity_at')
            ->limit(min((int) $request->input('limit', 50), 100))
            ->get();

        $likedIds = $this->likedIds($threads->pluck('id')->all(), CommunityThread::class, $tenantId, $user->id);

        return response()->json([
            'group' => $this->groupPayload($group, $user->id),
            'can_manage' => $isTeacher,
            'is_moderator' => $group->isModerator($user->id) || $isTeacher,
            'threads' => $threads->map(fn ($t) => $this->threadPayload($t, $likedIds, $user->id))->values(),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $group = CommunityGroup::where('tenant_id', $this->tenantId($request))->findOrFail($id);

        if (! $this->canManage($request, $group)) {
            return response()->json(['message' => 'You are not allowed to manage this group.'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'cover_image' => 'nullable|string|max:500',
            'visibility' => 'nullable|string|in:public,private',
            'join_policy' => 'nullable|string|in:open,request',
            'status' => 'nullable|string|in:active,archived',
        ]);

        $data = $validated;
        if (isset($validated['name'])) {
            $data['slug'] = $this->makeSlug($request, $validated['name'], $group->id);
        }

        $group->update($data);

        return response()->json([
            'group' => $this->groupPayload($group->fresh()->loadCount('activeMembers')->loadCount('threads'), $request->user()->id),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $group = CommunityGroup::where('tenant_id', $this->tenantId($request))->findOrFail($id);

        if (! $this->canManage($request, $group)) {
            return response()->json(['message' => 'You are not allowed to manage this group.'], 403);
        }

        $group->delete();

        return response()->json(['message' => 'Group deleted.']);
    }

    public function join(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->tenantId($request);

        $group = CommunityGroup::where('tenant_id', $tenantId)->where('status', 'active')->findOrFail($id);

        $existing = $group->userMembership($user->id);

        if ($existing && $existing->status === 'active') {
            return response()->json(['message' => 'You are already a member.'], 409);
        }

        $autoApprove = $group->join_policy === 'open' || $this->canManage($request, $group);

        if ($existing) {
            $existing->update([
                'status' => $autoApprove ? 'active' : 'pending',
                'joined_at' => $autoApprove ? now() : $existing->joined_at,
            ]);
        } else {
            CommunityGroupMember::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenantId,
                'group_id' => $group->id,
                'user_id' => $user->id,
                'role' => 'member',
                'status' => $autoApprove ? 'active' : 'pending',
                'joined_at' => $autoApprove ? now() : null,
            ]);
        }

        return response()->json([
            'group' => $this->groupPayload($group->loadCount('activeMembers')->loadCount('threads'), $user->id),
            'message' => $autoApprove ? 'Joined successfully.' : 'Membership request sent.',
        ]);
    }

    public function leave(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $group = CommunityGroup::where('tenant_id', $this->tenantId($request))->findOrFail($id);

        $membership = $group->userMembership($user->id);

        if (! $membership) {
            return response()->json(['message' => 'You are not a member of this group.'], 404);
        }

        $isModerator = $group->isModerator($user->id);
        $otherModerators = $group->members()
            ->where('status', 'active')
            ->whereIn('role', ['moderator'])
            ->where('user_id', '!=', $user->id)
            ->count();

        if ($isModerator && $otherModerators === 0 && ! $this->canManage($request, $group)) {
            return response()->json(['message' => 'You are the only moderator of this group.'], 409);
        }

        $membership->delete();

        return response()->json([
            'group' => $this->groupPayload($group->loadCount('activeMembers')->loadCount('threads'), $user->id),
            'message' => 'Left group.',
        ]);
    }

    public function members(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $group = CommunityGroup::where('tenant_id', $this->tenantId($request))->findOrFail($id);

        $membership = $group->userMembership($user->id);
        $isTeacher = $this->canManage($request, $group);

        if (! $isTeacher && $membership?->status !== 'active') {
            return response()->json(['message' => 'Members list is only visible to group members.'], 403);
        }

        $members = CommunityGroupMember::with('user:id,name,email,avatar_url')
            ->where('group_id', $group->id)
            ->when(! $isTeacher, fn ($q) => $q->where('status', 'active'))
            ->orderByRaw("status = 'active' DESC, role = 'moderator' DESC, joined_at ASC")
            ->get();

        return response()->json([
            'members' => $members->map(fn (CommunityGroupMember $m) => [
                'id' => $m->id,
                'group_id' => $m->group_id,
                'user_id' => $m->user_id,
                'name' => $m->user?->name,
                'email' => $m->user?->email,
                'avatar_url' => $m->user?->avatar_url,
                'role' => $m->role,
                'status' => $m->status,
                'joined_at' => $m->joined_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function updateMember(Request $request, string $id, string $memberId): JsonResponse
    {
        $group = CommunityGroup::where('tenant_id', $this->tenantId($request))->findOrFail($id);

        if (! $this->canManage($request, $group)) {
            return response()->json(['message' => 'You are not allowed to manage this group.'], 403);
        }

        $member = CommunityGroupMember::where('group_id', $group->id)->findOrFail($memberId);

        $validated = $request->validate([
            'role' => 'nullable|string|in:member,moderator',
            'status' => 'nullable|string|in:active,pending',
        ]);

        $member->update($validated);

        return response()->json([
            'member' => [
                'id' => $member->id,
                'group_id' => $member->group_id,
                'user_id' => $member->user_id,
                'role' => $member->role,
                'status' => $member->status,
                'joined_at' => $member->joined_at?->toIso8601String(),
            ],
        ]);
    }

    public function removeMember(Request $request, string $id, string $memberId): JsonResponse
    {
        $group = CommunityGroup::where('tenant_id', $this->tenantId($request))->findOrFail($id);

        if (! $this->canManage($request, $group)) {
            return response()->json(['message' => 'You are not allowed to manage this group.'], 403);
        }

        $member = CommunityGroupMember::where('group_id', $group->id)->findOrFail($memberId);
        $member->delete();

        return response()->json(['message' => 'Member removed.']);
    }
}
