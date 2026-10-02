<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunityComment;
use App\Models\CommunityGroup;
use App\Models\CommunityLike;
use App\Models\CommunityThread;
use App\Models\TenantUser;
use App\Traits\CommunityPayloads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommunityController extends Controller
{
    use CommunityPayloads;

    private function tenantId(Request $request): string
    {
        return $request->current_tenant->id;
    }

    public function isTeacher(Request $request): bool
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

    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->tenantId($request);
        $isTeacher = $this->isTeacher($request);

        $threads = CommunityThread::where('tenant_id', $tenantId)
            ->visibleTo($user->id, $isTeacher);

        $stats = [
            'threads' => (clone $threads)->count(),
            'questions' => (clone $threads)->where('type', 'question')->count(),
            'unanswered' => (clone $threads)->where('type', 'question')->where('answers_count', 0)->count(),
            'groups' => CommunityGroup::visibleTo($tenantId, $user->id)->count(),
            'members' => TenantUser::where('tenant_id', $tenantId)->where('status', 'active')->count(),
            'comments' => CommunityComment::where('tenant_id', $tenantId)->count(),
        ];

        $myGroups = CommunityGroup::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereHas('members', fn ($q) => $q->where('user_id', $user->id)->where('status', 'active'))
            ->withCount('activeMembers')
            ->withCount('threads')
            ->orderBy('name')
            ->get();

        // Tags aggregated in PHP — DB-agnostic (jsonb_array_elements_text is
        // PostgreSQL-only, and Builder has no joinRaw helper).
        $tagRows = DB::table('community_threads as t')
            ->select('t.tags')
            ->where('t.tenant_id', $tenantId)
            ->where('t.status', 'open')
            ->whereNotNull('t.tags');

        if (! $isTeacher) {
            $memberGroupIds = DB::table('community_group_members')
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->pluck('group_id');

            $publicGroupIds = DB::table('community_groups')
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->where('visibility', 'public')
                ->pluck('id');

            $visibleGroupIds = $memberGroupIds->merge($publicGroupIds)->unique()->values();

            $tagRows->where(fn ($q) => $q->whereNull('t.group_id')->orWhereIn('t.group_id', $visibleGroupIds));
        }

        $tagCounts = [];
        foreach ($tagRows->get() as $row) {
            foreach (json_decode((string) ($row->tags ?? '[]'), true) ?: [] as $tag) {
                $tag = trim((string) $tag);
                if ($tag !== '') {
                    $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
                }
            }
        }
        arsort($tagCounts);

        $tags = collect($tagCounts)
            ->take(12)
            ->map(fn ($count, $name) => ['name' => $name, 'count' => (int) $count])
            ->values()
            ->all();

        $myLikes = CommunityLike::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->count();

        return response()->json([
            'is_teacher' => $isTeacher,
            'stats' => $stats,
            'tags' => $tags,
            'my' => [
                'threads' => CommunityThread::where('tenant_id', $tenantId)->where('user_id', $user->id)->count(),
                'comments' => CommunityComment::where('tenant_id', $tenantId)->where('user_id', $user->id)->count(),
                'likes' => $myLikes,
            ],
            'my_groups' => $myGroups->map(fn (CommunityGroup $g) => $this->groupPayload($g, $user->id))->values(),
        ]);
    }
}
