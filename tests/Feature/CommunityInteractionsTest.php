<?php

namespace Tests\Feature;

use App\Models\CommunityGroup;
use App\Models\CommunityThread;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Community works between real users: post → another member sees it → reply →
 * author is notified → like → accept answer, with group permissions and tenant
 * isolation enforced.
 */
class CommunityInteractionsTest extends TenantTestCase
{
    use RefreshDatabase;

    private function teacher(Tenant $tenant): User
    {
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'teacher');

        return $user;
    }

    private function student(Tenant $tenant): User
    {
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'student');

        return $user;
    }

    public function test_post_reply_notify_like_and_accept_answer(): void
    {
        config(['queue.default' => 'sync']);

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);

        $author = $this->student($tenant);
        $peer = $this->student($tenant);

        // Author posts a question.
        $threadId = $this->withHeaders($this->authHeaders($author, $tenant))
            ->postJson('/api/v1/community/threads', [
                'title' => 'كيف أحل هذه المسألة؟',
                'body' => 'محتوى السؤال',
                'type' => 'question',
                'tags' => ['رياضيات'],
            ])->assertCreated()->json('thread.id');

        // Another member sees it immediately.
        $list = $this->withHeaders($this->authHeaders($peer, $tenant))->getJson('/api/v1/community/threads');
        $list->assertOk();
        $this->assertContains($threadId, collect($list->json('threads'))->pluck('id')->all());

        // Peer replies.
        $commentId = $this->withHeaders($this->authHeaders($peer, $tenant))
            ->postJson("/api/v1/community/threads/{$threadId}/comments", ['body' => 'هذه هي الطريقة'])
            ->assertCreated()->json('comment.id');

        // Author is notified once, with a deep link to the thread.
        $notif = DB::table('notifications')
            ->where('notifiable_id', $author->id)
            ->where('type', 'community.reply')
            ->first();
        $this->assertNotNull($notif, 'thread author should be notified of a reply');
        $this->assertSame('/community/threads/'.$threadId, json_decode($notif->data, true)['action_url']);

        // The replier is not notified about their own comment.
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $peer->id)->where('type', 'community.reply')->count());

        // Likes toggle.
        $this->withHeaders($this->authHeaders($peer, $tenant))
            ->postJson("/api/v1/community/threads/{$threadId}/like")
            ->assertOk()->assertJsonPath('liked', true)->assertJsonPath('likes_count', 1);
        $this->withHeaders($this->authHeaders($peer, $tenant))
            ->postJson("/api/v1/community/threads/{$threadId}/like")
            ->assertOk()->assertJsonPath('liked', false)->assertJsonPath('likes_count', 0);

        // Thread author accepts the answer.
        $this->withHeaders($this->authHeaders($author, $tenant))
            ->postJson("/api/v1/community/threads/{$threadId}/comments/{$commentId}/answer")
            ->assertOk()->assertJsonPath('is_answer', true);

        $this->assertSame(1, CommunityThread::find($threadId)->where('answers_count', 1)->count());
    }

    public function test_replies_to_a_comment_notify_the_comment_author(): void
    {
        config(['queue.default' => 'sync']);

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);

        $author = $this->student($tenant);
        $peer = $this->student($tenant);
        $third = $this->student($tenant);

        $threadId = $this->withHeaders($this->authHeaders($author, $tenant))
            ->postJson('/api/v1/community/threads', ['title' => 'T', 'body' => 'B', 'type' => 'discussion'])
            ->json('thread.id');

        $commentId = $this->withHeaders($this->authHeaders($peer, $tenant))
            ->postJson("/api/v1/community/threads/{$threadId}/comments", ['body' => 'first'])
            ->json('comment.id');

        DB::table('notifications')->delete();

        // Third user replies to the peer's comment → peer (and thread author) notified.
        $this->withHeaders($this->authHeaders($third, $tenant))
            ->postJson("/api/v1/community/threads/{$threadId}/comments", ['body' => 'reply', 'parent_id' => $commentId])
            ->assertCreated();

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $peer->id)->where('type', 'community.reply')->count());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $author->id)->where('type', 'community.reply')->count());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $third->id)->count());
    }

    public function test_threads_are_isolated_per_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->attachSubscription($tenantA);
        $this->attachSubscription($tenantB);

        $authorA = $this->student($tenantA);
        $outsiderB = $this->student($tenantB);

        $threadId = $this->withHeaders($this->authHeaders($authorA, $tenantA))
            ->postJson('/api/v1/community/threads', ['title' => 'A', 'body' => 'B', 'type' => 'discussion'])
            ->json('thread.id');

        $this->withHeaders($this->authHeaders($outsiderB, $tenantB))
            ->getJson("/api/v1/community/threads/{$threadId}")
            ->assertNotFound();

        $this->withHeaders($this->authHeaders($outsiderB, $tenantB))
            ->postJson("/api/v1/community/threads/{$threadId}/comments", ['body' => 'intrude'])
            ->assertNotFound();
    }

    public function test_private_group_requires_membership_to_post_and_view(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);

        $teacher = $this->teacher($tenant);
        $member = $this->student($tenant);
        $stranger = $this->student($tenant);

        $groupId = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson('/api/v1/community/groups', [
                'name' => 'Private group', 'visibility' => 'private', 'join_policy' => 'open',
            ])->assertCreated()->json('group.id');

        // A stranger cannot post into the private group before joining.
        $this->withHeaders($this->authHeaders($stranger, $tenant))
            ->postJson('/api/v1/community/threads', [
                'title' => 'x', 'body' => 'y', 'type' => 'discussion', 'group_id' => $groupId,
            ])->assertStatus(403);

        // Joining (open policy → auto-approved) grants access.
        $this->withHeaders($this->authHeaders($stranger, $tenant))
            ->postJson("/api/v1/community/groups/{$groupId}/join")
            ->assertOk();

        $this->withHeaders($this->authHeaders($stranger, $tenant))
            ->postJson('/api/v1/community/threads', [
                'title' => 'now allowed', 'body' => 'body', 'type' => 'discussion', 'group_id' => $groupId,
            ])->assertCreated();

        // A non-member still cannot read the private group's threads.
        $outsider = $this->student($tenant);
        $privateThread = CommunityThread::where('group_id', $groupId)->first();
        $this->withHeaders($this->authHeaders($outsider, $tenant))
            ->getJson("/api/v1/community/threads/{$privateThread->id}")
            ->assertStatus(403);

        $this->assertSame($groupId, CommunityGroup::find($groupId)->id);
        $this->assertTrue(CommunityGroup::find($groupId)->isMember($stranger->id));
        $this->assertFalse(CommunityGroup::find($groupId)->isMember($member->id));
    }
}
