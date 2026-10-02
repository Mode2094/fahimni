<?php

namespace App\Traits;

use App\Models\CommunityComment;
use App\Models\CommunityGroup;
use App\Models\CommunityLike;
use App\Models\CommunityThread;

trait CommunityPayloads
{
    private function groupPayload(CommunityGroup $group, string $userId): array
    {
        $membership = $group->relationLoaded('members')
            ? $group->members->first()
            : $group->userMembership($userId);

        return [
            'id' => $group->id,
            'name' => $group->name,
            'slug' => $group->slug,
            'description' => $group->description,
            'cover_image' => $group->cover_image,
            'visibility' => $group->visibility,
            'join_policy' => $group->join_policy,
            'status' => $group->status,
            'members_count' => $group->active_members_count ?? $group->members()->where('status', 'active')->count(),
            'threads_count' => $group->threads_count ?? $group->threads()->count(),
            'created_at' => $group->created_at?->toIso8601String(),
            'is_member' => $membership?->status === 'active',
            'membership_status' => $membership?->status,
            'my_role' => $membership?->role,
        ];
    }

    private function threadPayload(CommunityThread $thread, ?array $likedIds = null, ?string $userId = null): array
    {
        $payload = [
            'id' => $thread->id,
            'group_id' => $thread->group_id,
            'group' => $thread->group ? [
                'id' => $thread->group->id,
                'name' => $thread->group->name,
                'slug' => $thread->group->slug,
                'visibility' => $thread->group->visibility,
            ] : null,
            'title' => $thread->title,
            'body' => $thread->body,
            'type' => $thread->type,
            'tags' => $thread->tags ?? [],
            'status' => $thread->status,
            'is_pinned' => $thread->is_pinned,
            'views_count' => $thread->views_count,
            'comments_count' => $thread->comments_count,
            'answers_count' => $thread->answers_count,
            'likes_count' => $thread->likes_count,
            'last_activity_at' => $thread->last_activity_at?->toIso8601String(),
            'created_at' => $thread->created_at?->toIso8601String(),
            'updated_at' => $thread->updated_at?->toIso8601String(),
            'author' => $thread->author ? [
                'id' => $thread->author->id,
                'name' => $thread->author->name,
                'avatar_url' => $thread->author->avatar_url,
            ] : null,
        ];

        if ($userId) {
            $payload['liked_by_me'] = $likedIds === null
                ? $thread->likes()->where('user_id', $userId)->exists()
                : in_array($thread->id, $likedIds, true);
        }

        return $payload;
    }

    private function commentPayload(CommunityComment $comment, ?array $likedIds = null, ?string $userId = null): array
    {
        $payload = [
            'id' => $comment->id,
            'thread_id' => $comment->thread_id,
            'parent_id' => $comment->parent_id,
            'body' => $comment->body,
            'is_answer' => $comment->is_answer,
            'likes_count' => $comment->likes_count,
            'created_at' => $comment->created_at?->toIso8601String(),
            'updated_at' => $comment->updated_at?->toIso8601String(),
            'author' => $comment->author ? [
                'id' => $comment->author->id,
                'name' => $comment->author->name,
                'avatar_url' => $comment->author->avatar_url,
            ] : null,
        ];

        if ($userId) {
            $payload['liked_by_me'] = $likedIds === null
                ? $comment->likes()->where('user_id', $userId)->exists()
                : in_array($comment->id, $likedIds, true);
        }

        return $payload;
    }

    private function likedIds(array $likeableIds, string $likeableType, string $tenantId, string $userId): array
    {
        if (empty($likeableIds)) {
            return [];
        }

        return CommunityLike::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('likeable_type', $likeableType)
            ->whereIn('likeable_id', $likeableIds)
            ->pluck('likeable_id')
            ->all();
    }
}
