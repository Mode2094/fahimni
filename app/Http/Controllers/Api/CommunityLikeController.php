<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunityComment;
use App\Models\CommunityLike;
use App\Models\CommunityThread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class CommunityLikeController extends Controller
{
    private function tenantId(Request $request): string
    {
        return $request->current_tenant->id;
    }

    /**
     * Private groups: non-staff may only interact with content they are
     * active members of — liking must follow the same rule as posting.
     */
    private function assertGroupVisible(Request $request, CommunityThread $thread): void
    {
        $user = $request->user();

        if (! $thread->group_id) {
            return;
        }

        $pivot = $user->tenantPivots()
            ->where('tenant_id', $thread->tenant_id)
            ->whereIn('role', ['teacher', 'owner', 'admin'])
            ->where('status', 'active')
            ->first();

        if ($pivot) {
            return;
        }

        $membership = $thread->group?->userMembership($user->id);

        abort_if(
            $thread->group?->visibility === 'private' && $membership?->status !== 'active',
            403,
            'This group is private.'
        );
    }

    public function toggleThread(Request $request, string $threadId): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->tenantId($request);

        $thread = CommunityThread::where('tenant_id', $tenantId)
            ->with('group')
            ->findOrFail($threadId);

        $this->assertGroupVisible($request, $thread);

        $like = CommunityLike::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('likeable_type', CommunityThread::class)
            ->where('likeable_id', $thread->id)
            ->first();

        if ($like) {
            $like->delete();
            $thread->decrement('likes_count');
            $liked = false;
        } else {
            CommunityLike::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'likeable_type' => CommunityThread::class,
                'likeable_id' => $thread->id,
            ]);
            $thread->increment('likes_count');
            $liked = true;
        }

        return response()->json([
            'liked' => $liked,
            'likes_count' => $thread->fresh()->likes_count,
        ]);
    }

    public function toggleComment(Request $request, string $threadId, string $commentId): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->tenantId($request);

        $comment = CommunityComment::where('tenant_id', $tenantId)
            ->where('thread_id', $threadId)
            ->with('thread.group')
            ->findOrFail($commentId);

        if ($comment->thread) {
            $this->assertGroupVisible($request, $comment->thread);
        }

        $like = CommunityLike::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('likeable_type', CommunityComment::class)
            ->where('likeable_id', $comment->id)
            ->first();

        if ($like) {
            $like->delete();
            $comment->decrement('likes_count');
            $liked = false;
        } else {
            CommunityLike::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'likeable_type' => CommunityComment::class,
                'likeable_id' => $comment->id,
            ]);
            $comment->increment('likes_count');
            $liked = true;
        }

        return response()->json([
            'liked' => $liked,
            'likes_count' => $comment->fresh()->likes_count,
        ]);
    }
}
