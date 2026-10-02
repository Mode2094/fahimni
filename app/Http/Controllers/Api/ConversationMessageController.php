<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationParticipant;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

class ConversationMessageController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function index(Request $request, string $conversationId): JsonResponse
    {
        $user = $request->user();

        $conversation = Conversation::findOrFail($conversationId);

        abort_unless(
            $conversation->participants()->where('user_id', $user->id)->exists(),
            403,
            'Not a participant of this conversation.'
        );

        $messages = ConversationMessage::where('conversation_id', $conversationId)
            ->with('sender:id,name,avatar_url')
            ->orderByDesc('created_at')
            ->paginate(min(max((int) $request->input('per_page', 50), 1), 100));

        return response()->json([
            'messages' => $messages->items(),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    public function store(Request $request, string $conversationId): JsonResponse
    {
        $user = $request->user();

        $conversation = Conversation::findOrFail($conversationId);

        abort_unless(
            $conversation->participants()->where('user_id', $user->id)->exists(),
            403,
            'Not a participant of this conversation.'
        );

        $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $message = DB::transaction(function () use ($conversation, $user, $request) {
            $message = ConversationMessage::create([
                'id' => UuidV7::generate(),
                'conversation_id' => $conversation->id,
                'sender_id' => $user->id,
                'body' => $request->body,
                'type' => 'text',
            ]);

            $conversation->update(['last_message_at' => now()]);

            return $message->load('sender:id,name,avatar_url');
        });

        $this->notifyRecipients($conversation, $user, $message);

        return response()->json(['message' => $message], 201);
    }

    /**
     * Mark all incoming messages as read.
     */
    public function read(Request $request, string $conversationId): JsonResponse
    {
        $user = $request->user();

        $conversation = Conversation::findOrFail($conversationId);

        abort_unless(
            $conversation->participants()->where('user_id', $user->id)->exists(),
            403,
            'Not a participant of this conversation.'
        );

        DB::transaction(function () use ($conversation, $user) {
            ConversationMessage::where('conversation_id', $conversation->id)
                ->where('sender_id', '!=', $user->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            ConversationParticipant::where('conversation_id', $conversation->id)
                ->where('user_id', $user->id)
                ->update([
                    'last_read_at' => now(),
                    'last_read_message_id' => DB::table('conversation_messages')
                        ->where('conversation_id', $conversation->id)
                        ->orderByDesc('created_at')
                        ->value('id'),
                ]);
        });

        return response()->json(['message' => 'Conversation marked as read.']);
    }

    public function unreadTotals(Request $request): JsonResponse
    {
        $user = $request->user();

        $conversationIds = ConversationParticipant::where('user_id', $user->id)
            ->pluck('conversation_id');

        $total = ConversationMessage::whereIn('conversation_id', $conversationIds)
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['unread_count' => $total]);
    }

    private function notifyRecipients(Conversation $conversation, $sender, ConversationMessage $message): void
    {
        $recipients = $conversation->participants()
            ->with('user')
            ->get()
            ->pluck('user')
            ->where('id', '!=', $sender->id);

        $recipients->each(function ($recipient) use ($conversation, $sender, $message) {
            $this->notificationService->dispatch(
                notifiable: $recipient,
                type: 'chat.message',
                title: $sender->name,
                body: $message->body,
                sender: $sender,
                tenantId: $conversation->tenant_id,
                data: [
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                    'action_url' => url('/messages'),
                ],
                channels: ['database', 'push', 'mail'],
            );
        });
    }
}
