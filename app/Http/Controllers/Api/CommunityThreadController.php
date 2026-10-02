<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunityComment;
use App\Models\CommunityGroup;
use App\Models\CommunityThread;
use App\Services\FeatureService;
use App\Traits\CommunityPayloads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class CommunityThreadController extends Controller
{
    use CommunityPayloads;

    private function tenantId(Request $request): string
    {
        return $request->current_tenant->id;
    }

    private function isTeacher(Request $request): bool
    {
        $user = $request->user();

        if ($user->is_super_admin) {
            return true;
        }

        return $user->tenantPivots()
            ->where('tenant_id', $this->tenantId($request))
            ->whereIn('role', ['teacher', 'owner', 'admin'])
            ->where('status', 'active')
            ->exists();
    }

    private function canManage(Request $request, CommunityThread $thread): bool
    {
        if ($thread->tenant_id !== $this->tenantId($request)) {
            return false;
        }

        if ($this->isTeacher($request)) {
            return true;
        }

        return $thread->group_id !== null
            && $thread->group?->isModerator($request->user()->id);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->tenantId($request);
        $isTeacher = $this->isTeacher($request);

        $query = CommunityThread::with(['author:id,name,avatar_url', 'group:id,name,slug,visibility'])
            ->where('tenant_id', $tenantId)
            ->visibleTo($user->id, $isTeacher)
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('tag'), fn ($q) => $q->whereJsonContains('tags', $request->tag))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($q) use ($request) {
                    $q->where('title', 'like', '%'.$request->search.'%')
                        ->orWhere('body', 'like', '%'.$request->search.'%');
                });
            });

        if ($request->filled('group_id')) {
            $group = CommunityGroup::where('tenant_id', $tenantId)->findOrFail($request->group_id);
            $membership = $group->userMembership($user->id);

            if (! $isTeacher && $group->visibility === 'private' && $membership?->status !== 'active') {
                return response()->json(['message' => 'This group is private.'], 403);
            }

            $query->where('group_id', $group->id);
        }

        if ($request->filled('author')) {
            $query->where('user_id', $request->author);
        }

        if ($request->input('sort') === 'popular') {
            $query->orderByDesc('likes_count');
        } elseif ($request->input('sort') === 'unanswered') {
            $query->where('type', 'question')->where('answers_count', 0)->orderByDesc('last_activity_at');
        } elseif ($request->input('sort') === 'latest') {
            $query->orderByDesc('created_at');
        } else {
            $query->orderByDesc('last_activity_at');
        }

        $limit = min((int) $request->input('limit', 50), 100);
        $threads = $query->limit($limit)->get();

        $likedIds = $this->likedIds($threads->pluck('id')->all(), CommunityThread::class, $tenantId, $user->id);

        return response()->json([
            'threads' => $threads->map(fn ($t) => $this->threadPayload($t, $likedIds, $user->id))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->tenantId($request);

        $features = app(FeatureService::class);
        if (! $features->hasFeature('community_enabled')) {
            return response()->json(['message' => 'Community is not included in your current plan.'], 403);
        }

        $validated = $request->validate([
            'group_id' => 'nullable|uuid',
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:20000',
            'type' => 'nullable|string|in:discussion,question,announcement',
            'tags' => 'nullable|array|max:5',
            'tags.*' => 'string|max:40',
        ]);

        // Announcements are staff-issued only — students must not be able
        // to broadcast to the whole community.
        if (($validated['type'] ?? 'discussion') === 'announcement' && ! $this->isTeacher($request)) {
            return response()->json(['message' => 'Only teachers can post announcements.'], 403);
        }

        $group = null;
        if (! empty($validated['group_id'])) {
            $group = CommunityGroup::where('tenant_id', $tenantId)->where('status', 'active')->findOrFail($validated['group_id']);

            if ($group->visibility === 'private' && ! $group->isMember($user->id) && ! $this->isTeacher($request)) {
                return response()->json(['message' => 'You must join this group before posting.'], 403);
            }
        }

        $thread = CommunityThread::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'group_id' => $group?->id,
            'user_id' => $user->id,
            'title' => $validated['title'],
            'body' => $validated['body'],
            'type' => $validated['type'] ?? 'discussion',
            'tags' => array_values(array_unique(array_map('trim', $validated['tags'] ?? []))),
            'status' => 'open',
            'is_pinned' => false,
            'views_count' => 0,
            'comments_count' => 0,
            'answers_count' => 0,
            'likes_count' => 0,
            'last_activity_at' => now(),
        ]);

        return response()->json([
            'thread' => $this->threadPayload($thread->load(['author:id,name,avatar_url', 'group:id,name,slug,visibility']), null, $user->id),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->tenantId($request);
        $isTeacher = $this->isTeacher($request);

        $thread = CommunityThread::with(['author:id,name,avatar_url', 'group:id,name,slug,visibility'])
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        if (! $isTeacher && $thread->group_id) {
            $membership = $thread->group?->userMembership($user->id);
            if ($thread->group?->visibility === 'private' && $membership?->status !== 'active') {
                return response()->json(['message' => 'This group is private.'], 403);
            }
        }

        $thread->increment('views_count');

        $likedIds = $this->likedIds([$thread->id], CommunityThread::class, $tenantId, $user->id);

        $comments = CommunityComment::with(['author:id,name,avatar_url', 'replies.author:id,name,avatar_url'])
            ->where('thread_id', $thread->id)
            ->whereNull('parent_id')
            ->orderByDesc('is_answer')
            ->orderBy('created_at')
            ->limit(min((int) $request->input('limit', 100), 200))
            ->get();

        $commentLikedIds = $this->likedIds(
            array_merge($comments->pluck('id')->all(), $comments->pluck('replies')->flatten()->pluck('id')->all()),
            CommunityComment::class,
            $tenantId,
            $user->id
        );

        return response()->json([
            'thread' => $this->threadPayload($thread, $likedIds, $user->id),
            'can_manage' => $this->canManage($request, $thread),
            'is_teacher' => $isTeacher,
            'comments' => $comments->map(function ($c) use ($commentLikedIds, $user) {
                $payload = $this->commentPayload($c, $commentLikedIds, $user->id);
                $payload['replies_count'] = $c->replies->count();
                $payload['replies'] = $c->replies->map(fn ($r) => $this->commentPayload($r, $commentLikedIds, $user->id))->values();

                return $payload;
            })->values(),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $thread = CommunityThread::where('tenant_id', $this->tenantId($request))->findOrFail($id);

        if ($thread->user_id !== $user->id && ! $this->canManage($request, $thread)) {
            return response()->json(['message' => 'You are not allowed to edit this thread.'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'body' => 'sometimes|required|string|max:20000',
            'type' => 'sometimes|string|in:discussion,question,announcement',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:40',
        ]);

        if (isset($validated['tags'])) {
            $validated['tags'] = array_values(array_unique(array_map('trim', $validated['tags'])));
        }

        $thread->update($validated);

        return response()->json([
            'thread' => $this->threadPayload($thread->fresh()->load(['author:id,name,avatar_url', 'group:id,name,slug,visibility']), null, $user->id),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $thread = CommunityThread::where('tenant_id', $this->tenantId($request))->findOrFail($id);

        if ($thread->user_id !== $user->id && ! $this->canManage($request, $thread)) {
            return response()->json(['message' => 'You are not allowed to delete this thread.'], 403);
        }

        CommunityComment::where('thread_id', $thread->id)->delete();
        $thread->delete();

        return response()->json(['message' => 'Thread deleted.']);
    }

    public function pin(Request $request, string $id): JsonResponse
    {
        $thread = CommunityThread::where('tenant_id', $this->tenantId($request))->findOrFail($id);

        if (! $this->isTeacher($request)) {
            return response()->json(['message' => 'Only teachers can pin threads.'], 403);
        }

        $thread->update(['is_pinned' => ! $thread->is_pinned]);

        return response()->json([
            'thread' => $this->threadPayload($thread->fresh()->load(['author:id,name,avatar_url', 'group:id,name,slug,visibility']), null, $request->user()->id),
            'is_pinned' => $thread->fresh()->is_pinned,
        ]);
    }

    public function toggleStatus(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $thread = CommunityThread::where('tenant_id', $this->tenantId($request))->findOrFail($id);

        if ($thread->user_id !== $user->id && ! $this->canManage($request, $thread)) {
            return response()->json(['message' => 'You are not allowed to close this thread.'], 403);
        }

        $thread->update(['status' => $thread->status === 'open' ? 'closed' : 'open']);

        return response()->json([
            'thread' => $this->threadPayload($thread->fresh()->load(['author:id,name,avatar_url', 'group:id,name,slug,visibility']), null, $user->id),
            'status' => $thread->status,
        ]);
    }
}
