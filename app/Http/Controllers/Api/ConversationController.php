<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Course;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

class ConversationController extends Controller
{
    /**
     * Current user's conversations with unread counts and last message.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $request->current_tenant?->id ?? $request->header('X-Tenant-Id');

        $participantIds = ConversationParticipant::where('user_id', $user->id)
            ->pluck('conversation_id');

        $conversations = Conversation::whereIn('id', $participantIds);

        if ($tenantId) {
            $conversations->where('tenant_id', $tenantId);
        }

        $conversations = $conversations
            ->with([
                'participants.user:id,name,avatar_url',
                'lastMessage.sender:id,name,avatar_url',
            ])
            ->orderByDesc('last_message_at')
            ->paginate(min(max((int) $request->input('per_page', 20), 1), 100));

        $conversationIds = $conversations->getCollection()->pluck('id');

        $unreadCounts = DB::table('conversation_messages')
            ->whereIn('conversation_id', $conversationIds)
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->groupBy('conversation_id')
            ->selectRaw('conversation_id, count(*) as unread')
            ->get()
            ->pluck('unread', 'conversation_id');

        $result = $conversations->getCollection()->map(function (Conversation $conversation) use ($user, $unreadCounts) {
            $other = $conversation->participants->firstWhere('user_id', '!=', $user->id);

            return [
                'id' => $conversation->id,
                'type' => $conversation->type,
                'other_user' => $other?->user,
                'last_message' => $conversation->lastMessage,
                'last_message_at' => $conversation->last_message_at,
                'unread_count' => (int) ($unreadCounts[$conversation->id] ?? 0),
            ];
        });

        return response()->json([
            'conversations' => $result->values(),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    /**
     * Start (or reuse) a direct conversation with another tenant user.
     * Students may contact teachers/admins; teachers and admins may contact anyone.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        // Tenant context is required (super admins without X-Tenant-Id have
        // none) — fail with 400 instead of crashing on a null tenant.
        $tenant = $request->attributes->get('current_tenant') ?? $request->input('current_tenant');
        if (! $tenant) {
            return response()->json(['message' => 'Tenant context is required.'], 400);
        }
        $tenantId = $tenant->getKey();

        $request->validate([
            'user_id' => 'required|uuid',
            'course_id' => 'nullable|uuid|exists:courses,id',
        ]);

        if ($request->user_id === $user->id) {
            return response()->json(['message' => 'You cannot start a conversation with yourself.'], 422);
        }

        $other = User::findOrFail($request->user_id);
        $this->authorizePair($request, $tenantId, $other);

        // A conversation tagged with a course must reference a course of
        // this tenant (exists: alone ignores the tenant scope).
        if ($request->filled('course_id')) {
            Course::where('tenant_id', $tenantId)->findOrFail($request->course_id);
        }

        $existing = $this->findDirectConversation($tenantId, $user->id, $other->id);

        if ($existing) {
            return response()->json(['conversation' => $existing]);
        }

        $conversation = DB::transaction(function () use ($tenantId, $user, $other, $request) {
            $conversation = Conversation::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenantId,
                'type' => 'direct',
                'course_id' => $request->course_id,
            ]);

            ConversationParticipant::insert([
                [
                    'id' => UuidV7::generate(),
                    'conversation_id' => $conversation->id,
                    'user_id' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => UuidV7::generate(),
                    'conversation_id' => $conversation->id,
                    'user_id' => $other->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            return $conversation;
        });

        return response()->json(['conversation' => $conversation], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $conversation = Conversation::with('participants.user:id,name,avatar_url')
            ->findOrFail($id);

        abort_unless(
            $conversation->participants->contains('user_id', $user->id),
            403,
            'Not a participant of this conversation.'
        );

        return response()->json(['conversation' => $conversation]);
    }

    public function contacts(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $request->current_tenant->id;

        $role = $request->user()->tenantPivots()
            ->where('tenant_id', $tenantId)
            ->first()?->role;

        $query = TenantUser::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('user_id', '!=', $user->id)
            ->join('users', 'users.id', '=', 'tenant_user.user_id')
            ->whereNull('users.deleted_at')
            ->select('tenant_user.role', 'users.id', 'users.name', 'users.avatar_url', 'users.email');

        if (in_array($role, ['teacher', 'owner', 'admin'], true)) {
            $query->whereIn('tenant_user.role', ['student', 'teacher', 'owner', 'admin']);
        } else {
            $query->whereIn('tenant_user.role', ['teacher', 'owner', 'admin']);
        }

        return response()->json([
            'contacts' => $query->orderBy('users.name')->get(),
        ]);
    }

    private function authorizePair(Request $request, string $tenantId, User $other): void
    {
        $myRole = $request->user()->tenantPivots()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->first()?->role;

        $otherPivot = $other->tenantPivots()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->first();

        abort_unless($myRole && $otherPivot, 422, 'Both users must belong to the same tenant.');

        $isStaff = in_array($myRole, ['teacher', 'owner', 'admin'], true);

        abort_unless(
            $isStaff || in_array($otherPivot->role, ['teacher', 'owner', 'admin'], true),
            403,
            'Students can only message teachers or admins.'
        );
    }

    private function findDirectConversation(string $tenantId, string $userIdA, string $userIdB): ?Conversation
    {
        $ids = ConversationParticipant::where('user_id', $userIdA)
            ->pluck('conversation_id');

        $shared = ConversationParticipant::where('user_id', $userIdB)
            ->whereIn('conversation_id', $ids)
            ->pluck('conversation_id');

        if ($shared->isEmpty()) {
            return null;
        }

        $conversation = Conversation::whereIn('id', $shared)
            ->where('tenant_id', $tenantId)
            ->where('type', 'direct')
            ->first();

        if (! $conversation) {
            return null;
        }

        $count = $conversation->participants()->count();

        return $count === 2 ? $conversation : null;
    }
}
