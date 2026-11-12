<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\FeedbackManagementSeeder;
use Database\Seeders\RolePermissionSeeder;
use RuntimeException;
use Tests\Concerns\BuildsFeedbackSchema;
use Tests\TestCase;

class FeedbackSeederTest extends TestCase
{
    use BuildsFeedbackSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createFeedbackSchema();
    }

    public function test_seeder_mounts_menu_on_user_management_and_grants_only_super_admin(): void
    {
        $parent = Menu::query()->create([
            'parent_id' => 0,
            'type' => 1,
            'name' => 'UserManagement',
            'path' => '/user',
            'title' => '用户管理',
            'status' => 1,
        ]);

        $superAdmin = Role::query()->create(['name' => '超级管理员', 'code' => 'super_admin', 'status' => 1]);
        $dataAdmin = Role::query()->create(['name' => '数据管理员', 'code' => 'data_admin', 'status' => 1]);
        $testAdmin = Role::query()->create(['name' => '测试角色', 'code' => 'test_admin', 'status' => 1]);

        $this->seed(FeedbackManagementSeeder::class);

        $menu = Menu::query()->where('name', 'FeedbackManagement')->firstOrFail();

        $this->assertSame((int) $parent->id, (int) $menu->parent_id);
        $this->assertSame('/user/feedback', $menu->path);
        $this->assertSame('/user/feedback/index', $menu->component);
        $this->assertSame('意见反馈', $menu->title);
        $this->assertSame(2, (int) $menu->type);

        $permissions = Permission::query()
            ->where('name', 'like', 'system:feedback:%')
            ->get();

        $this->assertCount(6, $permissions);
        $this->assertTrue($permissions->every(fn (Permission $permission) => (int) $permission->menu_id === (int) $menu->id));

        $this->assertTrue($superAdmin->fresh()->menus()->where('menus.id', $menu->id)->exists());
        $this->assertSame(6, $superAdmin->fresh()->permissions()->where('name', 'like', 'system:feedback:%')->count());
        $this->assertFalse($dataAdmin->fresh()->menus()->where('menus.id', $menu->id)->exists());
        $this->assertSame(0, $dataAdmin->fresh()->permissions()->where('name', 'like', 'system:feedback:%')->count());
        $this->assertSame(0, $testAdmin->fresh()->permissions()->where('name', 'like', 'system:feedback:%')->count());
    }

    public function test_seeder_fails_when_user_management_menu_is_missing(): void
    {
        Role::query()->create(['name' => '超级管理员', 'code' => 'super_admin', 'status' => 1]);

        $this->expectException(RuntimeException::class);

        $this->seed(FeedbackManagementSeeder::class);
    }

    public function test_role_permission_seeder_excludes_feedback_permissions_for_other_roles(): void
    {
        $superAdmin = Role::query()->create(['name' => '超级管理员', 'code' => 'super_admin', 'status' => 1]);
        $dataAdmin = Role::query()->create(['name' => '数据管理员', 'code' => 'data_admin', 'status' => 1]);
        $testAdmin = Role::query()->create(['name' => '测试角色', 'code' => 'test_admin', 'status' => 1]);

        $userList = Permission::query()->create([
            'name' => 'system:user:list',
            'display_name' => '用户列表查询',
            'http_method' => 'GET',
            'http_path' => 'api/admin/users',
        ]);
        $feedbackList = Permission::query()->create([
            'name' => 'system:feedback:list',
            'display_name' => '意见反馈列表查询',
            'http_method' => 'GET',
            'http_path' => 'api/admin/feedback',
        ]);

        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(2, $superAdmin->fresh()->permissions()->count());

        foreach ([$dataAdmin, $testAdmin] as $role) {
            $ids = $role->fresh()->permissions()->pluck('permissions.id')->all();
            $this->assertContains((int) $userList->id, $ids);
            $this->assertNotContains((int) $feedbackList->id, $ids);
        }
    }

    public function test_cors_config_contains_local_h5_origin_without_wildcard(): void
    {
        $origins = config('cors.allowed_origins');

        $this->assertContains('http://127.0.0.1:8081', $origins);
        $this->assertContains('http://localhost:8081', $origins);
        $this->assertNotContains('*', $origins);
    }
}
