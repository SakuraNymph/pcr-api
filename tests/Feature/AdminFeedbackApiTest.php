<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Role;
use App\Models\User;
use App\Models\UserFeedback;
use App\Models\UserFeedbackMessage;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsFeedbackSchema;
use Tests\TestCase;

class AdminFeedbackApiTest extends TestCase
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

    public function test_only_super_admin_can_access_feedback_management(): void
    {
        $this->actingAsAdmin('super_admin');
        $this->getJson('/api/admin/feedback')->assertOk();

        $this->actingAsAdmin('data_admin');
        $this->getJson('/api/admin/feedback')->assertStatus(403);

        $this->actingAsAdmin('test_admin');
        $this->getJson('/api/admin/feedback')->assertStatus(403);

        $this->actingAsAdmin('super_admin', 0);
        $this->getJson('/api/admin/feedback')->assertStatus(403);
    }

    public function test_admin_list_orders_by_status_then_last_message_time(): void
    {
        $this->actingAsAdmin('super_admin');
        $user = $this->createUser();

        $closed = $this->createFeedback($user, [
            'title' => '已关闭',
            'status' => UserFeedback::STATUS_CLOSED,
            'last_message_at' => Carbon::parse('2026-11-12 09:00:00'),
        ]);
        $replied = $this->createFeedback($user, [
            'title' => '已回复',
            'status' => UserFeedback::STATUS_REPLIED,
            'last_message_at' => Carbon::parse('2026-11-12 09:10:00'),
        ]);
        $pending = $this->createFeedback($user, [
            'title' => '待处理',
            'status' => UserFeedback::STATUS_PENDING,
            'last_message_at' => Carbon::parse('2026-11-12 09:20:00'),
        ]);
        $processing = $this->createFeedback($user, [
            'title' => '处理中',
            'status' => UserFeedback::STATUS_PROCESSING,
            'last_message_at' => Carbon::parse('2026-11-12 09:30:00'),
        ]);

        $response = $this->getJson('/api/admin/feedback')->assertOk();

        $titles = array_column($response->json('data.items'), 'title');

        $this->assertSame(['待处理', '处理中', '已回复', '已关闭'], $titles);

        $this->assertNotNull($pending->id);
        $this->assertNotNull($processing->id);
        $this->assertNotNull($replied->id);
        $this->assertNotNull($closed->id);
    }

    public function test_admin_list_filters_and_statistics(): void
    {
        $this->actingAsAdmin('super_admin');
        $user = $this->createUser(nickname: '筛选用户', publicUid: 654321);

        $this->createFeedback($user, [
            'title' => '筛选目标反馈',
            'category' => UserFeedback::CATEGORY_FEATURE,
            'status' => UserFeedback::STATUS_PENDING,
        ]);
        $this->createFeedback($user, [
            'title' => '其他反馈',
            'category' => UserFeedback::CATEGORY_USAGE,
            'status' => UserFeedback::STATUS_CLOSED,
        ]);

        $this->getJson('/api/admin/feedback?status=0')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.title', '筛选目标反馈');

        $this->getJson('/api/admin/feedback?category=2')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->getJson('/api/admin/feedback?public_uid=654321')
            ->assertOk()
            ->assertJsonPath('data.total', 2);

        $this->getJson('/api/admin/feedback?nickname=筛选')
            ->assertOk()
            ->assertJsonPath('data.total', 2);

        $this->getJson('/api/admin/feedback?title=目标')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->getJson('/api/admin/feedback?has_unread=1')
            ->assertOk()
            ->assertJsonPath('data.total', 2);

        $this->getJson('/api/admin/feedback?start_date=2026-11-12&end_date=2026-11-12')
            ->assertOk()
            ->assertJsonPath('data.total', 2);

        $this->getJson('/api/admin/feedback/statistics')
            ->assertOk()
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.processing', 0)
            ->assertJsonPath('data.replied', 0)
            ->assertJsonPath('data.closed', 1)
            ->assertJsonPath('data.total', 2);
    }

    public function test_admin_reply_does_not_change_status(): void
    {
        $admin = $this->actingAsAdmin('super_admin');
        $user = $this->createUser();
        $feedback = $this->createFeedback($user, [
            'status' => UserFeedback::STATUS_REPLIED,
        ]);

        $this->postJson('/api/admin/feedback/'.$feedback->id.'/messages', [
            'content' => '管理员补充说明',
        ])->assertOk()
            ->assertJsonPath('data.status', UserFeedback::STATUS_REPLIED)
            ->assertJsonPath('data.message.sender_type', UserFeedbackMessage::SENDER_ADMIN);

        $this->assertSame(UserFeedback::STATUS_REPLIED, (int) $feedback->fresh()->status);

        $message = $feedback->messages()->where('sender_type', UserFeedbackMessage::SENDER_ADMIN)->first();
        $this->assertSame((int) $admin->id, (int) $message->sender_id);
    }

    public function test_admin_can_update_status_and_invalid_status_is_rejected(): void
    {
        $this->actingAsAdmin('super_admin');
        $user = $this->createUser();
        $feedback = $this->createFeedback($user);

        $this->putJson('/api/admin/feedback/'.$feedback->id.'/status', ['status' => 2])
            ->assertOk()
            ->assertJsonPath('data.status', 2)
            ->assertJsonPath('data.status_name', '已回复');

        $this->assertSame(2, (int) $feedback->fresh()->status);

        $this->putJson('/api/admin/feedback/'.$feedback->id.'/status', ['status' => 9])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_admin_detail_keeps_snapshot_when_user_is_deleted(): void
    {
        $this->actingAsAdmin('super_admin');
        $user = $this->createUser(nickname: '历史昵称');
        $feedback = $this->createFeedback($user);

        $user->delete();

        $response = $this->getJson('/api/admin/feedback/'.$feedback->id)
            ->assertOk()
            ->assertJsonPath('data.user', null)
            ->assertJsonPath('data.nickname', '历史昵称')
            ->assertJsonPath('data.public_uid', 123456);

        $payload = json_encode($response->json('data'));

        $this->assertStringNotContainsString('sender_id', $payload);
        $this->assertStringNotContainsString('"path"', $payload);
    }

    public function test_admin_mark_read_marks_only_user_messages(): void
    {
        $this->actingAsAdmin('super_admin');
        $user = $this->createUser();
        $feedback = $this->createFeedback($user);

        $userMessage = $feedback->messages()->where('sender_type', UserFeedbackMessage::SENDER_USER)->first();
        $adminMessage = $feedback->messages()->create([
            'sender_type' => UserFeedbackMessage::SENDER_ADMIN,
            'sender_id' => 1,
            'content' => '管理员消息',
            'read_at' => null,
        ]);

        $this->postJson('/api/admin/feedback/'.$feedback->id.'/read', [
            'through_message_id' => $adminMessage->id,
        ])->assertOk()
            ->assertJsonPath('data.read_count', 1);

        $this->assertNotNull($userMessage->fresh()->read_at);
        $this->assertNull($adminMessage->fresh()->read_at);
    }

    public function test_admin_feedback_delete_route_does_not_exist(): void
    {
        $this->actingAsAdmin('super_admin');
        $feedback = $this->createFeedback($this->createUser());

        $this->deleteJson('/api/admin/feedback/'.$feedback->id)->assertStatus(405);
    }

    private function actingAsAdmin(string $roleCode, int $status = 1): Admin
    {
        $role = Role::query()->firstOrCreate(
            ['code' => $roleCode],
            ['name' => $roleCode, 'status' => 1]
        );

        $admin = Admin::query()->create([
            'username' => $roleCode.'_'.uniqid(),
            'password' => 'secret',
            'nickname' => $roleCode,
            'status' => $status,
        ]);

        $admin->roles()->attach($role->id);

        Sanctum::actingAs($admin);

        return $admin;
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
