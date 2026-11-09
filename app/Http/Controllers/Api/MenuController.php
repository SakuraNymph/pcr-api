<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Menu\DeleteMenuRequest;
use App\Http\Requests\Menu\StoreMenuRequest;
use App\Http\Requests\Menu\UpdateMenuRequest;
use App\Http\Resources\MenuResource;
use App\Models\Menu;
use App\Services\SystemLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MenuController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected SystemLogService $systemLogService
    ) {
    }

    /**
     * 测试菜单权限
     */
    public function test(Request $request)
    {
        $menuIds = [3];

        $menuIds = Menu::addParentIds($menuIds);

        return $this->success($menuIds);
    }

    /**
     * 测试权限参数
     */
    public function testPermission($id)
    {
        return $this->success([
            'id' => $id,
        ]);
    }

    /**
     * 测试获取所有菜单
     */
    public function tree1111()
    {
        $menus = Menu::all();

        return $this->success($menus);
    }

    /**
     * 菜单列表
     */
    public function list(Request $request): JsonResponse
    {
        $menus = Menu::all()->toArray();

        $menus = Menu::buildTree($menus);

        return $this->success($menus);
    }

    /**
     * 当前管理员可见菜单树
     */
    public function tree(Request $request): JsonResponse
    {
        $admin = $request->user();

        $roles = $admin->roles;

        if ($roles->contains('code', 'super_admin')) {
            $menuIds = Menu::where('status', 1)
                ->pluck('id')
                ->all();
        } else {
            $menuIds = $roles->flatMap(function ($role) {
                return $role->menus
                    ->where('status', 1)
                    ->pluck('id');
            })
                ->unique()
                ->values()
                ->all();

            // 自动补充父级菜单
            $menuIds = Menu::addParentIds($menuIds);
        }

        $menus = Menu::getNestedForVue($menuIds);

        return $this->success($menus);
    }

    /**
     * 创建菜单
     */
    public function store(StoreMenuRequest $request): JsonResponse
    {
        $validated = $request->validated();

        DB::beginTransaction();

        try {
            $menu = Menu::create($validated);

            DB::commit();

            $this->systemLogService->record(
                module: 'menu',
                action: 'create',
                targetType: 'Menu',
                targetId: $menu->id,
                description: '创建菜单',
                changes: [
                    'name' => [
                        'old' => null,
                        'new' => $menu->name,
                    ],
                    'title' => [
                        'old' => null,
                        'new' => $menu->title,
                    ],
                    'parent_id' => [
                        'old' => null,
                        'new' => $menu->parent_id,
                    ],
                    'type' => [
                        'old' => null,
                        'new' => $menu->type,
                    ],
                    'path' => [
                        'old' => null,
                        'new' => $menu->path,
                    ],
                    'component' => [
                        'old' => null,
                        'new' => $menu->component,
                    ],
                    'permission' => [
                        'old' => null,
                        'new' => $menu->permission,
                    ],
                    'sort' => [
                        'old' => null,
                        'new' => $menu->sort,
                    ],
                    'status' => [
                        'old' => null,
                        'new' => $menu->status,
                    ],
                ],
            );

            return $this->success(
                new MenuResource($menu),
                '菜单创建成功'
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Menu create failed', [
                'data' => $validated,
                'exception' => $e,
            ]);

            $this->systemLogService->recordFailure(
                module: 'menu',
                action: 'create',
                targetType: 'Menu',
                description: '创建菜单',
                errorMessage: $e->getMessage(),
            );

            return response()->json([
                'message' => '创建菜单时出现错误',
                'error' => config('app.debug')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /**
     * 更新菜单
     */
    public function update(
        UpdateMenuRequest $request,
        Menu $menu
    ): JsonResponse {
        $validated = $request->validated();

        // 保存修改前的数据
        $oldData = [
            'name' => $menu->name,
            'title' => $menu->title,
            'parent_id' => $menu->parent_id,
            'type' => $menu->type,
            'path' => $menu->path,
            'component' => $menu->component,
            'redirect' => $menu->redirect,
            'icon' => $menu->icon,
            'permission' => $menu->permission,
            'sort' => $menu->sort,
            'hidden' => $menu->hidden,
            'keep_alive' => $menu->keep_alive,
            'affix_tab' => $menu->affix_tab,
            'iframe_src' => $menu->iframe_src,
            'link' => $menu->link,
            'status' => $menu->status,
        ];

        DB::beginTransaction();

        try {
            $menu->update($validated);

            $menu->refresh();

            DB::commit();

            // 只记录真正发生变化的字段
            $changes = [];

            foreach ($oldData as $field => $oldValue) {
                $newValue = $menu->{$field};

                if ((string) $oldValue !== (string) $newValue) {
                    $changes[$field] = [
                        'old' => $oldValue,
                        'new' => $newValue,
                    ];
                }
            }

            // 只有实际发生变化时才记录日志
            if (! empty($changes)) {
                $this->systemLogService->record(
                    module: 'menu',
                    action: 'update',
                    targetType: 'Menu',
                    targetId: $menu->id,
                    description: '修改菜单',
                    changes: $changes,
                );
            }

            return $this->success(
                new MenuResource($menu),
                '菜单更新成功'
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Menu update failed', [
                'menu_id' => $menu->id,
                'data' => $validated,
                'exception' => $e,
            ]);

            $this->systemLogService->recordFailure(
                module: 'menu',
                action: 'update',
                targetType: 'Menu',
                targetId: $menu->id,
                description: '修改菜单',
                errorMessage: $e->getMessage(),
            );

            return response()->json([
                'message' => '更新菜单时出现错误',
                'error' => config('app.debug')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /**
     * 删除菜单
     *
     * 删除父级菜单时，会同时删除：
     * - 当前菜单
     * - 所有子菜单
     * - 所有更深层级的菜单
     * - 所属按钮
     */
    public function destroy(DeleteMenuRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $ids = $validated['ids'];

        // 获取所有需要删除的菜单，包括后代菜单
        $allIds = Menu::descendantIds($ids);

        // 保存删除前的菜单信息
        $deletedMenus = Menu::whereIn('id', $allIds)
            ->get([
                'id',
                'name',
                'title',
                'parent_id',
                'type',
            ])
            ->map(function ($menu) {
                return [
                    'id' => $menu->id,
                    'name' => $menu->name,
                    'title' => $menu->title,
                    'parent_id' => $menu->parent_id,
                    'type' => $menu->type,
                ];
            })
            ->values()
            ->toArray();

        DB::beginTransaction();

        try {
            $deletedCount = Menu::whereIn('id', $allIds)->delete();

            DB::commit();

            $targetId = ! empty($ids)
                ? (int) $ids[0]
                : null;

            $this->systemLogService->record(
                module: 'menu',
                action: 'delete',
                targetType: 'Menu',
                targetId: $targetId,
                description: '删除菜单',
                changes: [
                    'menus' => [
                        'old' => $deletedMenus,
                        'new' => [],
                    ],
                ],
            );

            return $this->success(
                [
                    'ids' => $allIds,
                    'deleted_count' => $deletedCount,
                ],
                "成功删除 {$deletedCount} 条菜单记录"
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Menu delete failed', [
                'ids' => $ids,
                'all_ids' => $allIds,
                'exception' => $e,
            ]);

            $targetId = ! empty($ids)
                ? (int) $ids[0]
                : null;

            $this->systemLogService->recordFailure(
                module: 'menu',
                action: 'delete',
                targetType: 'Menu',
                targetId: $targetId,
                description: '删除菜单',
                errorMessage: $e->getMessage(),
                changes: [
                    'ids' => $allIds,
                ],
            );

            return response()->json([
                'message' => '删除菜单时出现异常',
                'error' => config('app.debug')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }
}