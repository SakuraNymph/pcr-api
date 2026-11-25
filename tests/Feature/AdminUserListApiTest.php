<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsFeedbackSchema;
use Tests\TestCase;

class AdminUserListApiTest extends TestCase
{
    use BuildsFeedbackSchema;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-25 10:00:00'));
        $this->createFeedbackSchema();

        $role = Role::query()->firstOrCreate(
            ['code' => 'super_admin'],
            ['name' => 'super_admin', 'status' => 1]
        );

        $admin = Admin::query()->create([
            'username' => 'super_admin_test',
            'password' => 'secret',
            'nickname' => 'super_admin',
            'status' => 1,
        ]);
        $admin->roles()->attach($role->id);

        Sanctum::actingAs($admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_user_list_returns_registered_at(): void
    {
        $this->createUser('1.1.1.1', '2026-11-01 08:00:00', '2026-11-20 09:00:00');

        $registeredAt = $this->getJson('/api/admin/users')
            ->assertOk()
            ->json('data.items.0.registered_at');

        $this->assertTrue(
            Carbon::parse('2026-11-20 09:00:00')->equalTo(Carbon::parse($registeredAt))
        );
    }

    public function test_user_list_filters_by_registered_at_instead_of_created_at(): void
    {
        // 11 月创建、11 月 24 日注册。
        $this->createUser('2.2.2.2', '2026-11-01 08:00:00', '2026-11-24 09:00:00');
        // 11 月 24 日创建、11 月 25 日注册。
        $this->createUser('3.3.3.3', '2026-11-24 08:00:00', '2026-11-25 09:00:00');

        // 按注册时间筛选 11-24：只能命中第一个。
        $items = $this->getJson('/api/admin/users?start_date=2026-11-24&end_date=2026-11-24')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->json('data.items');

        $this->assertTrue(
            Carbon::parse('2026-11-24 09:00:00')
                ->equalTo(Carbon::parse($items[0]['registered_at']))
        );
    }

    private function createUser(
        string $ip,
        string $createdAt,
        string $registeredAt
    ): User {
        $id = User::query()->insertGetId([
            'ip' => $ip,
            'nickname' => '测试用户',
            'status' => 1,
            'registered_at' => $registeredAt,
            'is_subscribe' => 1,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return User::query()->findOrFail($id);
    }
}
