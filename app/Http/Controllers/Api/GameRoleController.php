<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GameRole\StoreGameRoleRequest;
use App\Http\Requests\GameRole\UpdateGameRoleRequest;
use App\Models\GameRole;
use App\Services\CardStatisticsRoleService;
use App\Services\SystemLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GameRoleController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected SystemLogService $systemLogService,
        protected CardStatisticsRoleService $cardStatisticsRoleService
    ) {
    }

    /**
     * 游戏角色列表
     */
    public function index(Request $request)
    {
        $pageSize = (int) $request->input('pageSize', 20);

        if ($pageSize < 1) {
            $pageSize = 20;
        }

        if ($pageSize > 100) {
            $pageSize = 100;
        }

        $query = GameRole::with([
            'image',
            'statistics',
        ]);

        $name = $request->input('name');

        if ($name !== null && $name !== '') {
            $query->where(function ($q) use ($name) {
                $q->where('name', 'like', "%{$name}%")
                    ->orWhere('nickname', 'like', "%{$name}%");
            });
        }

        $element = $request->input('element');

        if ($element !== null && $element !== '') {
            $query->where('element', $element);
        }

        $atkType = $request->input('atk_type');

        if ($atkType !== null && $atkType !== '') {
            $query->where('atk_type', $atkType);
        }

        $position = $request->input('position');

        if ($position !== null && $position !== '') {
            $query->where('position', $position);
        }

        $obtain = $request->input('obtain');

        if ($obtain !== null && $obtain !== '') {
            $query->where('obtain', $obtain);
        }

        $probability = $request->input('probability');

        if ($probability !== null && $probability !== '') {
            $query->where('probability', $probability);
        }

        $status = $request->input('status');

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        /**
         * 按角色 ID 倒序排列
         */
        $list = $query
            ->orderByDesc('created_at', 'DESC')
            ->paginate($pageSize);

        /**
         * 增加头像地址
         */
        $list->getCollection()->transform(function (GameRole $gameRole) {
            $imageId = $gameRole->role_id_6 ?? $gameRole->role_id_3;

            $gameRole->image_url = $imageId
                ? url("images/roles/{$imageId}.webp")
                : null;

            return $gameRole;
        });

        return $this->success(
            $list,
            '获取游戏角色列表成功'
        );
    }

    /**
     * 创建游戏角色
     */
    public function store(StoreGameRoleRequest $request)
    {
        $validated = $request->validated();

        $id = (int) $validated['id'];

        $roleId = $id * 100 + 1;
        $roleId1 = $id * 100 + 11;
        $roleId3 = $id * 100 + 31;

        $nameA = trim($validated['name_a']);

        $nameB = isset($validated['name_b'])
            ? trim($validated['name_b'])
            : '';

        $name = $nameA;

        if ($nameB !== '') {
            $name .= '（' . $nameB . '）';
        }

        $imageId = $roleId3;

        $fileName = $imageId . '.webp';

        $url = 'https://redive.estertion.win/icon/unit/' . $fileName;

        $imagePath = public_path('images/roles/' . $fileName);

        $imageAlreadyExists = file_exists($imagePath);

        $downloadedFileName = $this->downloadImage(
            $url,
            $fileName,
            'images/roles'
        );

        if ($downloadedFileName === false) {
            return $this->error(
                '角色头像下载失败',
                422
            );
        }

        try {
            $gameRole = DB::transaction(function () use (
                $validated,
                $id,
                $roleId,
                $roleId1,
                $roleId3,
                $name,
                $imageId
            ) {
                $gameRole = GameRole::create([
                    'id' => $id,
                    'role_id' => $roleId,
                    'role_id_1' => $roleId1,
                    'role_id_3' => $roleId3,
                    'role_id_6' => null,
                    'element' => $validated['element'],
                    'atk_type' => $validated['atk_type'],
                    'is_clan_battle' => $validated['is_clan_battle'] ?? false,
                    'name' => $name,
                    'nickname' => $validated['nickname'],
                    'search_area_width' => $validated['search_area_width'],
                    'position' => $validated['position'],
                    'obtain' => $validated['obtain'] ?? 1,
                    'probability' => $validated['probability'] ?? 1,
                    'up_time' => $validated['up_time'] ?? null,
                    'status' => $validated['status'] ?? true,
                ]);

                $gameRole->image()->create([
                    'image_id' => $imageId,
                    'is_6_star' => false,
                    'is_download' => true,
                ]);

                $gameRole->statistics()->create([
                    'use_times' => 0,
                ]);

                return $gameRole;
            });
        } catch (\Throwable $e) {
            if (
                !$imageAlreadyExists
                && file_exists($imagePath)
            ) {
                @unlink($imagePath);
            }

            throw $e;
        }

        $this->cardStatisticsRoleService->forgetCache();

        $gameRole->load([
            'image',
            'statistics',
        ]);

        $this->appendImageUrl($gameRole);

        $this->systemLogService->record(
            module: 'game_role',
            action: 'create',
            targetType: 'GameRole',
            targetId: $gameRole->id,
            description: '创建游戏角色',
            changes: [
                'name' => [
                    'new' => $gameRole->name,
                ],
                'nickname' => [
                    'new' => $gameRole->nickname,
                ],
            ],
        );

        return $this->success(
            $gameRole,
            '创建游戏角色成功'
        );
    }

    /**
     * 获取游戏角色详情
     */
    public function show(GameRole $gameRole)
    {
        $gameRole->load([
            'image',
            'statistics',
        ]);

        $this->appendImageUrl($gameRole);

        return $this->success(
            $gameRole,
            '获取游戏角色详情成功'
        );
    }

    /**
     * 修改游戏角色
     */
    public function update(
        UpdateGameRoleRequest $request,
        GameRole $gameRole
    ) {
        $validated = $request->validated();

        $oldData = [
            'name' => $gameRole->name,
            'nickname' => $gameRole->nickname,
            'element' => $gameRole->element,
            'atk_type' => $gameRole->atk_type,
            'position' => $gameRole->position,
            'obtain' => $gameRole->obtain,
            'probability' => $gameRole->probability,
            'up_time' => $gameRole->up_time,
        ];

        $id = $gameRole->id;

        $roleId = $id * 100 + 1;
        $roleId1 = $id * 100 + 11;
        $roleId3 = $id * 100 + 31;

        $nameA = trim($validated['name_a']);

        $nameB = isset($validated['name_b'])
            ? trim($validated['name_b'])
            : '';

        $name = $nameA;

        if ($nameB !== '') {
            $name .= '（' . $nameB . '）';
        }

        /**
         * 当前规则：
         *
         * 没有六星数据
         * 使用三星头像
         */
        $imageId = $roleId3;

        $oldImageId = $gameRole->image
            ? $gameRole->image->image_id
            : null;

        $imageChanged = $oldImageId !== $imageId;

        $imagePath = public_path(
            'images/roles/' . $imageId . '.webp'
        );

        $imageAlreadyExists = file_exists($imagePath);

        if ($imageChanged) {
            $fileName = $imageId . '.webp';

            $url = 'https://redive.estertion.win/icon/unit/' . $fileName;

            $downloadedFileName = $this->downloadImage(
                $url,
                $fileName,
                'images/roles'
            );

            if ($downloadedFileName === false) {
                return $this->error(
                    '角色头像下载失败',
                    422
                );
            }
        }

        try {
            DB::transaction(function () use (
                $gameRole,
                $validated,
                $roleId,
                $roleId1,
                $roleId3,
                $name,
                $imageId,
                $imageChanged
            ) {
                $gameRole->update([
                    'role_id' => $roleId,
                    'role_id_1' => $roleId1,
                    'role_id_3' => $roleId3,
                    'element' => $validated['element'],
                    'atk_type' => $validated['atk_type'],
                    'is_clan_battle' => $validated['is_clan_battle'] ?? false,
                    'name' => $name,
                    'nickname' => $validated['nickname'],
                    'search_area_width' => $validated['search_area_width'],
                    'position' => $validated['position'],
                    'obtain' => $validated['obtain'] ?? 1,
                    'probability' => $validated['probability'] ?? 1,
                    'up_time' => $validated['up_time'] ?? null,
                    'status' => $validated['status'] ?? true,
                ]);

                if ($gameRole->image) {
                    $data = [
                        'image_id' => $imageId,
                    ];

                    if ($imageChanged) {
                        $data['is_download'] = true;
                    }

                    $gameRole->image->update($data);
                } else {
                    $gameRole->image()->create([
                        'image_id' => $imageId,
                        'is_6_star' => false,
                        'is_download' => true,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            if (
                $imageChanged
                && !$imageAlreadyExists
                && file_exists($imagePath)
            ) {
                @unlink($imagePath);
            }

            throw $e;
        }

        $this->cardStatisticsRoleService->forgetCache();

        $gameRole = $gameRole
            ->fresh()
            ->load([
                'image',
                'statistics',
            ]);

        $this->appendImageUrl($gameRole);

        $changes = [];

        foreach ($oldData as $key => $value) {
            if ($value != $gameRole->$key) {
                $changes[$key] = [
                    'old' => $value,
                    'new' => $gameRole->$key,
                ];
            }
        }

        if ($changes) {
            $this->systemLogService->record(
                module: 'game_role',
                action: 'update',
                targetType: 'GameRole',
                targetId: $gameRole->id,
                description: '修改游戏角色',
                changes: $changes,
            );
        }

        return $this->success(
            $gameRole,
            '修改游戏角色成功'
        );
    }

    /**
     * 删除游戏角色
     *
     * 注意：
     * 不删除本地头像文件
     */
    public function destroy(GameRole $gameRole)
    {
        $deleteData = [
            'name' => $gameRole->name,
            'nickname' => $gameRole->nickname,
            'role_id' => $gameRole->role_id,
        ];

        DB::transaction(function () use ($gameRole) {
            /**
             * 删除角色对应的商店配置
             */
            $gameRole->masterCoinShopRole()->delete();

            /**
             * 删除角色图片记录
             */
            $gameRole->image()->delete();

            /**
             * 删除统计记录
             */
            $gameRole->statistics()->delete();

            /**
             * 删除角色
             */
            $gameRole->delete();
        });

        $this->cardStatisticsRoleService->forgetCache();

        $this->systemLogService->record(
            module: 'game_role',
            action: 'delete',
            targetType: 'GameRole',
            targetId: $gameRole->id,
            description: '删除游戏角色',
            changes: [
                'deleted' => $deleteData,
            ],
        );

        return $this->success(
            [],
            '删除游戏角色成功'
        );
    }

    /**
     * 增加头像地址
     */
    private function appendImageUrl(GameRole $gameRole): void
    {
        $imageId = $gameRole->role_id_6 ?? $gameRole->role_id_3;

        $gameRole->image_url = $imageId
            ? url("images/roles/{$imageId}.webp")
            : null;
    }

    /**
     * 下载角色头像
     */
    private function downloadImage(
        string $url,
        string $fileName = '',
        string $path = 'public'
    ): string|false {
        $options = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ];

        $context = stream_context_create($options);

        if ($fileName === '') {
            $fileName = rand(10000, 99999) . '.jpg';
        }

        $directory = public_path($path);

        if (!is_dir($directory)) {
            if (
                !mkdir($directory, 0755, true)
                && !is_dir($directory)
            ) {
                return false;
            }
        }

        $localFilePath = $directory
            . DIRECTORY_SEPARATOR
            . $fileName;

        $content = @file_get_contents(
            $url,
            false,
            $context
        );

        if ($content === false || $content === '') {
            return false;
        }

        $result = @file_put_contents(
            $localFilePath,
            $content,
            LOCK_EX
        );

        if ($result === false) {
            return false;
        }

        return $fileName;
    }

    /**
     * 获取全部角色选项
     */
    public function options(): JsonResponse
    {
        // 获取全部角色信息
        $roles = GameRole::query()
            ->select([
                'id',
                'nickname',
                'role_id_3',
                'role_id_6',
                'search_area_width',
            ])
            ->with('statistics:game_role_id,use_times')
            ->get();

        $roles = $roles->sort(function (GameRole $a, GameRole $b): int {
            return ((int) ($b->statistics?->use_times ?? 0) <=> (int) ($a->statistics?->use_times ?? 0))
                ?: ((int) $a->id <=> (int) $b->id);
        })->values();

        // 增加头像地址
        $roles->transform(function (GameRole $gameRole) {
            $imageId = $gameRole->role_id_6 ?? $gameRole->role_id_3;

            return [
                'id' => $gameRole->id,
                'nickname' => $gameRole->nickname,
                'search_area_width' => $gameRole->search_area_width,
                'use_times' => (int) ($gameRole->statistics?->use_times ?? 0),
                'image_url' => $imageId
                    ? url("images/roles/{$imageId}.webp")
                    : null,
            ];
        });

        return $this->success(
            $roles,
            '获取角色选项成功'
        );
    }

    /**
     * 获取用户端全部可用游戏角色
     *
     * 仅返回 status = 1 的角色
     * 按 element 分组
     * 同一属性下按使用次数降序排列
     */
    public function publicOptions(): JsonResponse
    {
        // 查询所有可用角色及其统计信息
        $roles = GameRole::query()
            ->select([
                'id',
                'element',
                'name',
                'role_id_3',
                'role_id_6',
                'search_area_width',
            ])
            ->where('status', 1)
            ->with('statistics:game_role_id,use_times')
            ->get();

        // 按使用次数降序排列，使用次数相同则按角色 ID 升序排列
        $roles = $roles->sort(function (GameRole $a, GameRole $b): int {
            return ((int) ($b->statistics?->use_times ?? 0)
                <=> (int) ($a->statistics?->use_times ?? 0))
                ?: ((int) $a->id <=> (int) $b->id);
        })->values();

        // 转换为前端需要的字段
        $roles = $roles->map(function (GameRole $gameRole): array {
            $imageId = $gameRole->role_id_6
                ?? $gameRole->role_id_3;

            return [
                'id' => $gameRole->id,
                'element' => $gameRole->element,
                'name' => $gameRole->name,
                'search_area_width' => $gameRole->search_area_width,
                'image_url' => $imageId
                    ? url("images/roles/{$imageId}.webp")
                    : null,
            ];
        });

        // 按属性分组，每组包含 roles 数组
        $groupedRoles = $roles
            ->groupBy('element')
            ->map(function ($elementRoles, $element): array {
                return [
                    'element' => (int) $element,
                    'roles' => $elementRoles->values()->all(),
                ];
            })
            ->values();

        return $this->success(
            $groupedRoles,
            '获取游戏角色成功'
        );
    }
}
