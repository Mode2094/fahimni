<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunityComment;
use App\Models\CommunityThread;
use App\Services\FeatureService;
use App\Traits\CommunityPayloads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class CommunityCommentController extends Controller
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

    private function findThread(Request $request, string $threadId): CommunityThread
    {
        return CommunityThread::with(['group:id,name,slug,visibility'])
            ->where('tenant_id', $this->tenantId($request))
            ->findOrFail($threadId);
    }

    public function store(Request $request, string $threadId): JsonResponse
    {
        $user = $request->user();
        $thread = $this->findThread($request, $threadId);

        $features = app(FeatureService::class);
        if (! $features->hasFeature('community_enabled')) {
            return response()->json(['message' => 'Community is not included in your current plan.'], 403);
        }

        if ($thread->status === 'closed') {
            return response()->json(['message' => 'This thread is closed.'], 409);
        }

        if (! $this->isTeacher($request) && $thread->group_id) {
            $membership = $thread->group?->userMembership($user->id);
            if ($thread->group?->visibility === 'private' && $membership?->status !== 'active') {
                return response()->json(['message' => 'This group is private.'], 403);
            }
        }

        $validated = $request->validate([
            'body' => 'required|string|max:20000',
            'parent_id' => 'nullable|uuid',
        ]);

        $parent = null;
        if (! empty($validated['parent_id'])) {
            $parent = CommunityComment::where('tenant_id', $this->tenantId($request))
                ->where('thread_id', $thread->id)
                ->findOrFail($validated['parent_id']);

            if ($parent->parent_id !== null) {
                return response()->json(['message' => 'Replies are limited to one level.'], 422);
            }
        }

        $comment = CommunityComment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $this->tenantId($request),
            'thread_id' => $thread->id,
            'parent_id' => $parent?->id,
            'user_id' => $user->id,
            'body' => $validated['body'],
            'is_answer' => false,
            'likes_count' => 0,
        ]);

        $thread->increment('comments_count');
        if ($parent === null) {
            $thread->increment('answers_count');
        }
        $thread->update(['last_activity_at' => now()]);

        return response()->json([
            'comment' => $this->commentPayload($comment->load('author:id,name,avatar_url'), null, $user->id),
        ], 201);
    }

    public function update(Request $request, string $threadId, string $commentId): JsonResponse
    {
        $user = $request->user();

        $thread = $this->findThread($request, $threadId);
        $comment = CommunityComment::where('tenant_id', $this->tenantId($request))
            ->where('thread_id', $thread->id)
            ->findOrFail($commentId);

        if ($comment->user_id !== $user->id && ! $this->canManage($request, $thread)) {
            return response()->json(['message' => 'You are not allowed to edit this comment.'], 403);
        }

        $validated = $request->validate([
            'body' => 'required|string|max:20000',
        ]);

        $comment->update($validated);

        return response()->json([
            'comment' => $this->commentPayload($comment->fresh()->load('author:id,name,avatar_url'), null, $user->id),
        ]);
    }

    public function destroy(Request $request, string $threadId, string $commentId): JsonResponse
    {
        $user = $request->user();

        $thread = $this->findThread($request, $threadId);
        $comment = CommunityComment::where('tenant_id', $this->tenantId($request))
            ->where('thread_id', $thread->id)
            ->findOrFail($commentId);

        if ($comment->user_id !== $user->id && ! $this->canManage($request, $thread)) {
            return response()->json(['message' => 'You are not allowed to delete this comment.'], 403);
        }

        $isTopLevel = $comment->parent_id === null;

        CommunityComment::where('parent_id', $comment->id)->delete();
        $comment->delete();

        $thread->decrement('comments_count');
        if ($isTopLevel) {
            $thread->decrement('answers_count');
        }

        return response()->json(['message' => 'Comment deleted.']);
    }

    public function answer(Request $request, string $threadId, string $commentId): JsonResponse
    {
        $user = $request->user();

        $thread = $this->findThread($request, $threadId);

        if ($thread->type !== 'question') {
            return response()->json(['message' => 'Only question threads accept best answers.'], 422);
        }

        if ($thread->user_id !== $user->id && ! $this->canManage($request, $thread)) {
            return response()->json(['message' => 'Only the question author or a teacher can accept an answer.'], 403);
        }

        $comment = CommunityComment::where('tenant_id', $this->tenantId($request))
            ->where('thread_id', $thread->id)
            ->whereNull('parent_id')
            ->findOrFail($commentId);

        $accepted = ! $comment->is_answer;

        CommunityComment::where('thread_id', $thread->id)
            ->where('is_answer', true)
            ->update(['is_answer' => false]);

        $comment->update(['is_answer' => $accepted]);

        return response()->json([
            'comment' => $this->commentPayload($comment->fresh()->load('author:id,name,avatar_url'), null, $user->id),
            'is_answer' => $accepted,
        ]);
    }
}
