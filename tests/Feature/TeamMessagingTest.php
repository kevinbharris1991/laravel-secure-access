<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use SecureAccess\Models\TeamConversation;
use SecureAccess\Models\TeamMember;
use SecureAccess\Models\TeamMessage;
use SecureAccess\Models\TeamMessageRead;
use SecureAccess\SecureAccess;
use SecureAccess\Services\TeamMessagingService;
use SecureAccess\Tests\Fixtures\User;
use SecureAccess\Tests\TestCase;

class TeamMessagingTest extends TestCase
{
    private function user(string $name, string $role = 'family_caregiver'): User
    {
        return $this->createUser([
            'name' => $name,
            'username' => $name,
            'email' => $name.'@example.com',
            'registration_role' => $role,
        ]);
    }

    private function login(User $user): void
    {
        $this->actingAs($user)->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->getKey(),
        ]);
    }

    private function link(User $adult, User $member): TeamMember
    {
        return TeamMember::create([
            'self_advocate_id' => (string) $adult->id,
            'team_member_id' => (string) $member->id,
            'team_member_role' => $member->registration_role,
        ]);
    }

    private function conversation(User $adult, array $members): TeamConversation
    {
        foreach ($members as $member) {
            if (! $member->is($adult)) {
                $this->link($adult, $member);
            }
        }

        return app(TeamMessagingService::class)->create(
            (string) $members[0]->id,
            (string) $adult->id,
            array_map(fn ($member) => (string) $member->id, $members),
        );
    }

    public function test_explicit_subset_can_create_one_to_one_and_group_conversations_without_adding_the_adult(): void
    {
        $adult = $this->user('adult', 'self_advocate');
        $caregiver = $this->user('caregiver');
        $professional = $this->user('professional', 'professional');
        $other = $this->user('other');
        foreach ([$caregiver, $professional, $other] as $member) {
            $this->link($adult, $member);
        }
        $this->login($caregiver);
        $this->get(route('secure-access.messaging.create'))->assertOk()->assertSee('adult');
        $this->post(route('secure-access.messaging.store'), [
            'self_advocate_id' => (string) $adult->id,
            'participants' => [(string) $caregiver->id, (string) $professional->id],
        ])->assertRedirect();
        $conversation = TeamConversation::sole();
        $this->assertEqualsCanonicalizing([$caregiver->id, $professional->id], $conversation->participants->modelKeys());
        $this->post(route('secure-access.messaging.store'), [
            'self_advocate_id' => (string) $adult->id,
            'participants' => [(string) $caregiver->id, (string) $adult->id, (string) $other->id],
        ])->assertRedirect();
        $this->assertSame(3, TeamConversation::latest('id')->first()->participants()->count());
    }

    public function test_unrelated_and_ended_members_cannot_be_added_or_select_a_different_team(): void
    {
        $adult = $this->user('adult', 'self_advocate');
        $otherAdult = $this->user('otheradult', 'self_advocate');
        $caregiver = $this->user('caregiver');
        $unrelated = $this->user('unrelated');
        $ended = $this->user('ended');
        $this->link($adult, $caregiver);
        $this->link($otherAdult, $unrelated);
        $relationship = $this->link($adult, $ended);
        DB::table('secure_access_team_members')->where('id', $relationship->id)->update(['ended_at' => now()]);
        $this->login($caregiver);
        foreach ([$unrelated, $ended] as $invalid) {
            $this->post(route('secure-access.messaging.store'), [
                'self_advocate_id' => (string) $adult->id,
                'participants' => [(string) $caregiver->id, (string) $invalid->id],
            ])->assertSessionHasErrors('participants');
        }
        $this->post(route('secure-access.messaging.store'), [
            'self_advocate_id' => (string) $otherAdult->id,
            'participants' => [(string) $caregiver->id, (string) $unrelated->id],
        ])->assertNotFound();
        $this->post(route('secure-access.messaging.store'), [
            'self_advocate_id' => (string) $adult->id,
            'participants' => [(string) $adult->id, (string) $unrelated->id],
        ])->assertSessionHasErrors('participants');
        $this->assertSame(0, TeamConversation::count());
    }

    public function test_nonparticipant_adult_and_same_team_members_cannot_list_show_send_or_read(): void
    {
        $adult = $this->user('adult', 'self_advocate');
        $first = $this->user('first');
        $second = $this->user('second');
        $outsider = $this->user('outsider');
        $sameTeam = $this->user('sameteam');
        $conversation = $this->conversation($adult, [$first, $second]);
        $this->link($adult, $sameTeam);
        $message = $conversation->messages()->create(['sender_id' => (string) $first->id, 'body' => 'private-text']);
        foreach ([$adult, $outsider, $sameTeam] as $user) {
            $this->login($user);
            $this->get(route('secure-access.messaging.index'))->assertOk()
                ->assertDontSee('Conversation #'.$conversation->id);
            $this->get(route('secure-access.messaging.show', $conversation))->assertNotFound();
            $this->post(route('secure-access.messaging.send', $conversation), ['body' => 'intrusion'])->assertNotFound();
            $this->post(route('secure-access.messaging.read', $conversation), ['message_ids' => [$message->id]])->assertNotFound();
        }
        $this->assertSame(1, TeamMessage::count());
        $this->assertSame(0, TeamMessageRead::count());
    }

    public function test_initiator_must_explicitly_select_themselves_even_when_other_participants_are_valid(): void
    {
        $adult = $this->user('adult', 'self_advocate');
        $first = $this->user('first');
        $second = $this->user('second');
        $this->link($adult, $first);
        $this->link($adult, $second);
        $this->login($adult);
        $this->post(route('secure-access.messaging.store'), [
            'self_advocate_id' => (string) $adult->id,
            'participants' => [(string) $first->id, (string) $second->id],
        ])->assertSessionHasErrors('participants');
        $this->assertSame(0, TeamConversation::count());
    }

    public function test_removed_participant_loses_all_access_even_with_previously_retrieved_message_ids(): void
    {
        $adult = $this->user('adult', 'self_advocate');
        $member = $this->user('member');
        $conversation = $this->conversation($adult, [$adult, $member]);
        $message = $conversation->messages()->create(['sender_id' => (string) $adult->id, 'body' => 'earlier']);
        $this->login($member);
        $this->get(route('secure-access.messaging.show', $conversation))->assertOk();
        DB::table('secure_access_team_members')->where('team_member_id', (string) $member->id)->update(['ended_at' => now()]);
        $this->get(route('secure-access.messaging.index'))->assertOk()->assertDontSee('Conversation #'.$conversation->id);
        $this->get(route('secure-access.messaging.show', $conversation))->assertNotFound();
        $this->post(route('secure-access.messaging.send', $conversation), ['body' => 'blocked'])->assertNotFound();
        $this->post(route('secure-access.messaging.read', $conversation), ['message_ids' => [$message->id]])->assertNotFound();
        $this->assertSame(0, app(TeamMessagingService::class)->unreadCount((string) $member->id));
    }

    public function test_participant_in_another_team_cannot_use_membership_in_that_team_to_retain_access(): void
    {
        $adult = $this->user('adult', 'self_advocate');
        $otherAdult = $this->user('otheradult', 'self_advocate');
        $member = $this->user('member');
        $conversation = $this->conversation($adult, [$adult, $member]);
        $this->link($otherAdult, $member);
        DB::table('secure_access_team_members')->where('self_advocate_id', (string) $adult->id)
            ->where('team_member_id', (string) $member->id)->update(['ended_at' => now()]);
        $this->login($member);
        $this->get(route('secure-access.messaging.show', $conversation))->assertNotFound();
    }

    public function test_encrypted_messages_are_escaped_and_do_not_enter_urls_notifications_or_flashed_input(): void
    {
        Notification::fake();
        $adult = $this->user('adult', 'self_advocate');
        $member = $this->user('member');
        $conversation = $this->conversation($adult, [$adult, $member]);
        $this->login($adult);
        $body = '<script>alert("private")</script>';
        $response = $this->post(route('secure-access.messaging.send', $conversation), ['body' => $body]);
        $response->assertRedirect(route('secure-access.messaging.show', [$conversation, 'embedded' => 0]));
        $this->assertStringNotContainsString('private', $response->headers->get('Location'));
        $message = TeamMessage::sole();
        $this->assertSame($body, $message->body);
        $this->assertStringNotContainsString($body, DB::table('secure_access_team_messages')->value('body'));
        $this->assertArrayNotHasKey('body', $message->toArray());
        $this->get(route('secure-access.messaging.show', $conversation))->assertOk()
            ->assertSee($body)->assertDontSee($body, false)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->post(route('secure-access.messaging.send', $conversation), ['body' => str_repeat('private', 1500)])
            ->assertSessionHasErrors('body')->assertSessionMissing('_old_input.body');
        Notification::assertNothingSent();
        $this->assertSame(1, TeamMessage::count());
    }

    public function test_unread_counts_exclude_sender_and_receipts_only_cover_retrieved_messages(): void
    {
        $adult = $this->user('adult', 'self_advocate');
        $member = $this->user('member');
        $conversation = $this->conversation($adult, [$adult, $member]);
        $message = $conversation->messages()->create(['sender_id' => (string) $adult->id, 'body' => 'first']);
        $service = app(TeamMessagingService::class);
        $this->assertSame(0, $service->unreadCount((string) $adult->id));
        $this->assertSame(1, $service->unreadCount((string) $member->id));
        $this->login($member);
        $this->post(route('secure-access.messaging.read', $conversation), ['message_ids' => [$message->id]])->assertUnprocessable();
        $this->get(route('secure-access.messaging.show', $conversation))->assertOk();
        $later = $conversation->messages()->create(['sender_id' => (string) $adult->id, 'body' => 'arrived later']);
        $this->post(route('secure-access.messaging.read', $conversation), ['message_ids' => [$later->id]])->assertUnprocessable();
        $this->assertSame(0, TeamMessageRead::count());
        $this->post(route('secure-access.messaging.read', $conversation), ['message_ids' => [$message->id]])->assertRedirect();
        $this->post(route('secure-access.messaging.read', $conversation), ['message_ids' => [$message->id]])->assertRedirect();
        $this->assertSame(1, TeamMessageRead::count());
        $this->assertSame(1, $service->unreadCount((string) $member->id));
        $this->assertSame((string) $member->id, TeamMessageRead::sole()->user_id);
    }

    public function test_paginated_read_receipts_do_not_mark_unretrieved_older_messages_or_own_messages(): void
    {
        $adult = $this->user('adult', 'self_advocate');
        $member = $this->user('member');
        $conversation = $this->conversation($adult, [$adult, $member]);
        for ($i = 0; $i < 51; $i++) {
            $conversation->messages()->create(['sender_id' => (string) $adult->id, 'body' => 'message '.$i]);
        }
        $own = $conversation->messages()->create(['sender_id' => (string) $member->id, 'body' => 'own']);
        $this->login($member);
        $this->get(route('secure-access.messaging.show', $conversation))->assertOk();
        $oldest = $conversation->messages()->oldest('id')->first();
        $this->post(route('secure-access.messaging.read', $conversation), ['message_ids' => [$oldest->id]])->assertUnprocessable();
        $ids = $conversation->messages()->latest('id')->limit(50)->pluck('id')->all();
        $this->post(route('secure-access.messaging.read', $conversation), ['message_ids' => $ids])->assertRedirect();
        $this->assertSame(49, TeamMessageRead::count());
        $this->assertDatabaseMissing('secure_access_team_message_reads', ['message_id' => $own->id]);
        $this->assertSame(2, app(TeamMessagingService::class)->unreadCount((string) $member->id));
    }

    public function test_read_receipts_cannot_be_replayed_across_conversations(): void
    {
        $adult = $this->user('adult', 'self_advocate');
        $member = $this->user('member');
        $first = $this->conversation($adult, [$adult, $member]);
        $second = app(TeamMessagingService::class)->create(
            (string) $adult->id, (string) $adult->id, [(string) $adult->id, (string) $member->id],
        );
        $message = $first->messages()->create(['sender_id' => (string) $adult->id, 'body' => 'first conversation']);
        $this->login($member);
        $this->get(route('secure-access.messaging.show', $first))->assertOk();
        $this->get(route('secure-access.messaging.show', $second))->assertOk();
        $this->post(route('secure-access.messaging.read', $second), ['message_ids' => [$message->id]])->assertUnprocessable();
        $this->assertSame(0, TeamMessageRead::count());
    }

    public function test_dashboard_has_accessible_optional_overlay_and_embedded_view_has_no_recursive_overlay(): void
    {
        $adult = $this->user('adult', 'self_advocate');
        $member = $this->user('member');
        $conversation = $this->conversation($adult, [$adult, $member]);
        $conversation->messages()->create(['sender_id' => (string) $adult->id, 'body' => 'secret preview']);
        $this->login($member);
        $this->get(route('secure-access.dashboard'))->assertOk()
            ->assertSee('id="sa-messages-open"', false)
            ->assertSee('1 unread messages')
            ->assertSee('aria-labelledby="sa-messages-title"', false)
            ->assertSee('title="Private team messenger"', false)
            ->assertSee('Open full-page messenger')
            ->assertSee("event.key === 'Escape'", false)
            ->assertSee('open.focus()', false)
            ->assertDontSee('secret preview');
        $this->get(route('secure-access.messaging.index', ['embedded' => 1]))->assertOk()
            ->assertDontSee('id="sa-messages-dialog"', false);
    }

    public function test_messaging_requires_authentication_mfa_and_parent_route_throttling(): void
    {
        $this->get(route('secure-access.messaging.index'))->assertRedirect(route('secure-access.login'));
        $member = $this->user('member');
        $this->actingAs($member);
        $this->getJson(route('secure-access.messaging.index'))->assertForbidden();
        foreach (['index', 'create', 'store', 'show', 'send', 'read'] as $name) {
            $route = app('router')->getRoutes()->getByName('secure-access.messaging.'.$name);
            $this->assertContains('secure-access.mfa', $route->gatherMiddleware());
            $this->assertContains('throttle:secure-access', $route->gatherMiddleware());
        }
    }

    public function test_messaging_uses_the_configured_secure_access_guard(): void
    {
        config()->set('auth.guards.team', ['driver' => 'session', 'provider' => 'users']);
        config()->set('secure-access.guard', 'team');
        $adult = $this->user('adult', 'self_advocate');
        $member = $this->user('member');
        $conversation = $this->conversation($adult, [$adult, $member]);
        $this->actingAs($member, 'team')->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $member->id,
        ]);
        $this->post(route('secure-access.messaging.send', $conversation), ['body' => 'configured guard'])
            ->assertRedirect();
        $this->assertSame((string) $member->id, TeamMessage::sole()->sender_id);
    }
}
