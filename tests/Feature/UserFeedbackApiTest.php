<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserFeedback;
use App\Models\UserFeedbackMessage;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsFeedbackSchema;
use Tests\TestCase;

class UserFeedbackApiTest extends TestCase
{
    use BuildsFeedbackSchema;

    private int $userIpCounter = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-12 10:00:00'));
        $this->createFeedbackSchema();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_guest_cannot_access_user_feedback_endpoints(): void
    {
        $this->getJson('/api/user/feedback')->assertStatus(401);
        $this->postJson('/api/user/feedback', [
            'category' => 1,
            'title' => '标题',
            'content' => '内容内容内容',
        ])->assertStatus(401);
    }

    public function test_user_can_submit_feedback_and_first_message_is_created(): void
    {
        $user = $this->createUser('提交用户');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/user/feedback', [
            'category' => 2,
            'title' => '希望增加夜间模式',
            'content' => '晚上使用有点刺眼，希望可以增加夜间模式。',
            'client_type' => 'h5',
            'client_version' => '1.0.0',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonPath('msg', '意见反馈提交成功');

        $feedback = UserFeedback::query()->findOrFail($response->json('data.id'));

        $this->assertSame((int) $user->id, (int) $feedback->user_id);
        $this->assertSame(123456, (int) $feedback->public_uid);
        $this->assertSame('提交用户', $feedback->nickname);
        $this->assertSame(2, (int) $feedback->category);
        $this->assertSame(UserFeedback::STATUS_PENDING, (int) $feedback->status);
        $this->assertSame('h5', $feedback->client_type);
        $this->assertSame(1, $feedback->messages()->count());

        $message = $feedback->messages()->first();
        $this->assertSame(UserFeedbackMessage::SENDER_USER, (int) $message->sender_type);
        $this->assertSame((int) $user->id, (int) $message->sender_id);
        $this->assertSame('晚上使用有点刺眼，希望可以增加夜间模式。', $message->content);
    }

    public function test_submit_feedback_validates_fields(): void
    {
        Sanctum::actingAs($this->createUser());

        $this->postJson('/api/user/feedback', [
            'category' => 9,
            'title' => '短',
            'content' => '四个字',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['category', 'title', 'content']);
    }

    public function test_user_list_only_returns_own_feedback_and_paginates_by_20(): void
    {
        $user = $this->createUser();
        $other = $this->createUser('其他用户', 654321);
        Sanctum::actingAs($user);

        for ($index = 1; $index <= 21; $index++) {
            $this->createFeedback($user, ['title' => '反馈 '.$index]);
        }

        $this->createFeedback($other, ['title' => '别人的反馈']);

        $response = $this->getJson('/api/user/feedback')
            ->assertOk()
            ->assertJsonPath('data.total', 21)
            ->assertJsonPath('data.per_page', 20)
            ->assertJsonPath('data.last_page', 2);

        $this->assertCount(20, $response->json('data.items'));

        foreach ($response->json('data.items') as $item) {
            $this->assertStringStartsWith('反馈 ', $item['title']);
        }
    }

    public function test_unread_filter_only_returns_feedback_with_unread_admin_messages(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        $unread = $this->createFeedback($user, ['title' => '有未读']);
        $unread->messages()->create([
            'sender_type' => UserFeedbackMessage::SENDER_ADMIN,
            'sender_id' => 1,
            'content' => '管理员回复',
            'read_at' => null,
        ]);

        $read = $this->createFeedback($user, ['title' => '已读']);
        $read->messages()->create([
            'sender_type' => UserFeedbackMessage::SENDER_ADMIN,
            'sender_id' => 1,
            'content' => '管理员回复',
            'read_at' => now(),
        ]);

        $this->getJson('/api/user/feedback?filter=unread')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.title', '有未读')
            ->assertJsonPath('data.items.0.unread_count', 1);
    }

    public function test_detail_does_not_mark_messages_as_read(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        $feedback = $this->createFeedback($user);
        $message = $feedback->messages()->create([
            'sender_type' => UserFeedbackMessage::SENDER_ADMIN,
            'sender_id' => 1,
            'content' => '未读回复',
            'read_at' => null,
        ]);

        $this->getJson('/api/user/feedback/'.$feedback->id)
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.messages.1.read_at', null);

        $this->assertNull($message->fresh()->read_at);
    }

    public function test_mark_read_only_marks_admin_messages_through_given_id(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        $feedback = $this->createFeedback($user);

        $first = $feedback->messages()->create([
            'sender_type' => UserFeedbackMessage::SENDER_ADMIN,
            'sender_id' => 1,
            'content' => '第一条回复',
            'read_at' => null,
        ]);
        $second = $feedback->messages()->create([
            'sender_type' => UserFeedbackMessage::SENDER_ADMIN,
            'sender_id' => 1,
            'content' => '第二条回复',
            'read_at' => null,
        ]);

        $this->postJson('/api/user/feedback/'.$feedback->id.'/read', [
            'through_message_id' => $first->id,
        ])->assertOk()
            ->assertJsonPath('data.read_count', 1)
            ->assertJsonPath('data.unread_count', 1);

        $this->assertNotNull($first->fresh()->read_at);
        $this->assertNull($second->fresh()->read_at);
    }

    public function test_mark_read_rejects_message_from_other_feedback(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        $feedback = $this->createFeedback($user);
        $other = $this->createFeedback($user, ['title' => '另一条反馈']);

        $this->postJson('/api/user/feedback/'.$feedback->id.'/read', [
            'through_message_id' => $other->messages()->first()->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['through_message_id']);
    }

    public function test_user_reply_transitions_status(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        $cases = [
            UserFeedback::STATUS_PENDING => UserFeedback::STATUS_PENDING,
            UserFeedback::STATUS_PROCESSING => UserFeedback::STATUS_PROCESSING,
            UserFeedback::STATUS_REPLIED => UserFeedback::STATUS_PROCESSING,
            UserFeedback::STATUS_CLOSED => UserFeedback::STATUS_PROCESSING,
        ];

        foreach ($cases as $from => $expected) {
            $feedback = $this->createFeedback($user, [
                'title' => '状态测试 '.$from,
                'status' => $from,
            ]);

            $this->postJson('/api/user/feedback/'.$feedback->id.'/messages', [
                'content' => '补充说明',
            ])->assertOk()
                ->assertJsonPath('data.status', $expected);

            $this->assertSame($expected, (int) $feedback->fresh()->status);
        }
    }

    public function test_user_reply_requires_content_or_image(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        $feedback = $this->createFeedback($user);

        $this->postJson('/api/user/feedback/'.$feedback->id.'/messages', [
            'content' => '   ',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['content']);
    }

    public function test_user_cannot_access_feedback_of_another_user(): void
    {
        $user = $this->createUser();
        $other = $this->createUser('其他用户', 654321);
        Sanctum::actingAs($user);

        $feedback = $this->createFeedback($other);

        $this->getJson('/api/user/feedback/'.$feedback->id)->assertStatus(404);
        $this->postJson('/api/user/feedback/'.$feedback->id.'/messages', [
            'content' => '越权回复',
        ])->assertStatus(404);
        $this->postJson('/api/user/feedback/'.$feedback->id.'/read', [
            'through_message_id' => $feedback->messages()->first()->id,
        ])->assertStatus(404);
    }

    public function test_feedback_delete_route_does_not_exist(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        $feedback = $this->createFeedback($user);

        $this->deleteJson('/api/user/feedback/'.$feedback->id)->assertStatus(405);
    }

    private function createUser(string $nickname = '测试用户', int $publicUid = 123456): User
    {
        return User::query()->create([
            'public_uid' => $publicUid,
            'ip' => '10.0.0.'.$this->userIpCounter++,
            'nickname' => $nickname,
            'avatar' => 105811,
            'status' => 1,
            'is_subscribe' => 1,
            'email' => $publicUid.'@example.com',
        ]);
    }

    private function createFeedback(User $user, array $overrides = []): UserFeedback
    {
        $feedback = UserFeedback::query()->create(array_merge([
            'user_id' => $user->id,
            'public_uid' => $user->public_uid,
            'nickname' => $user->nickname,
            'category' => UserFeedback::CATEGORY_USAGE,
            'title' => '测试反馈',
            'status' => UserFeedback::STATUS_PENDING,
            'client_type' => 'h5',
            'client_version' => '1.0.0',
            'last_message_at' => now(),
        ], $overrides));

        $feedback->messages()->create([
            'sender_type' => UserFeedbackMessage::SENDER_USER,
            'sender_id' => $user->id,
            'content' => '初始反馈内容',
            'read_at' => null,
        ]);

        return $feedback;
    }
}
