<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Game\StoreCustomTeamRequest;
use App\Http\Requests\Game\UpdateCustomTeamRequest;
use App\Models\GameRole;
use App\Models\Team;
use App\Models\TeamRole;
use App\Services\TeamQueryService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UserTeamController extends Controller
{
    use ApiResponse;

    /**
     * 获取当前用户当月 D 阶段个人作业列表。
     */
    public function index(TeamQueryService $teamQueryService)
    {
        $uid = $this->uid();

        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $data = $teamQueryService->getUserTeams($uid);

        return $this->success($data, '获取个人作业列表成功');
    }

    /**
     * 将系统作业添加到个人作业列表。
     *
     * 可选参数：
     * missing_role_id：缺失角色 ID，最多指定一个。
     */
    public function addFromSystem(Request $request, int $systemTeamId)
    {
        $uid = $this->uid();

        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $validator = Validator::make($request->all(), [
            'missing_role_id' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->error('请求参数错误', 422, $validator->errors());
        }

        return DB::transaction(function () use ($uid, $systemTeamId, $request) {
            // 查询系统作业，并锁定记录，避免并发操作造成重复添加。
            $systemTeam = Team::where('id', $systemTeamId)
                ->where('uid', 0)
                ->where('status', 1)
                ->lockForUpdate()
                ->first();

            if (! $systemTeam) {
                return $this->error('系统作业不存在或已不可用', 404);
            }

            // 获取系统作业的角色 ID。
            $roleIds = $systemTeam->teamRoles()
                ->orderBy('id')
                ->pluck('role_id')
                ->map(fn ($roleId) => (int) $roleId)
                ->all();

            if (empty($roleIds)) {
                return $this->error('该系统作业没有角色信息，无法添加', 422);
            }

            // 缺失角色必须属于当前系统作业。
            $missingRoleId = $request->input('missing_role_id');

            if (
                $missingRoleId !== null
                && ! in_array((int) $missingRoleId, $roleIds, true)
            ) {
                return $this->error('指定的缺失角色不属于该系统作业', 422);
            }

            // 查询当前用户是否已经添加过该系统作业。
            $userTeam = Team::where('uid', $uid)
                ->where('source_team_id', $systemTeam->id)
                ->lockForUpdate()
                ->first();

            if ($userTeam) {
                // 已存在则恢复原记录，不重复创建。
                $userTeam->status = 1;
                $userTeam->save();
            } else {
                // 首次添加，只复制作业信息，不复制攻略链接。
                $userTeam = Team::create([
                    'stage' => $systemTeam->stage,
                    'source_team_id' => $systemTeam->id,
                    'uid' => $uid,
                    'boss' => $systemTeam->boss,
                    'damage' => $systemTeam->damage,
                    'team_key' => $systemTeam->team_key,
                    'status' => 1,
                    'mode' => $systemTeam->mode,
                    'atk_value' => $systemTeam->atk_value,
                    'remark' => $systemTeam->remark,
                    'import_timestamp' => $systemTeam->import_timestamp,
                ]);

                // 复制系统作业中的角色，初始状态全部可用。
                $teamRoles = [];

                foreach ($roleIds as $roleId) {
                    $teamRoles[] = [
                        'team_id' => $userTeam->id,
                        'role_id' => $roleId,
                        'status' => 1,
                    ];
                }

                TeamRole::insert($teamRoles);
            }

            // 每次添加或恢复时，先将全部角色设为可用。
            $userTeam->teamRoles()->update([
                'status' => 1,
            ]);

            // 如果指定缺失角色，仅将该角色设为不可用。
            if ($missingRoleId !== null) {
                $userTeam->teamRoles()
                    ->where('role_id', (int) $missingRoleId)
                    ->update([
                        'status' => 0,
                    ]);
            }

            return $this->success([
                'id' => $userTeam->id,
                'source_team_id' => $systemTeam->id,
                'status' => $userTeam->status,
                'missing_role_id' => $missingRoleId === null
                    ? null
                    : (int) $missingRoleId,
            ], '系统作业已添加到个人列表');
        });
    }

    /**
     * 将系统作业从个人作业列表中取消。
     *
     * 仅修改个人作业的 status，不物理删除记录。
     */
    public function removeFromSystem(int $systemTeamId)
    {
        $uid = $this->uid();

        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        return DB::transaction(function () use ($uid, $systemTeamId) {
            // 只允许操作当前用户自己的个人作业记录。
            $userTeam = Team::where('uid', $uid)
                ->where('source_team_id', $systemTeamId)
                ->lockForUpdate()
                ->first();

            if (! $userTeam) {
                return $this->error('个人作业记录不存在', 404);
            }

            // 取消收藏后，该副本不再保留角色不可用状态。
            $userTeam->teamRoles()->update([
                'status' => 1,
            ]);

            // 软删除：只隐藏个人列表中的这条作业。
            $userTeam->status = 0;
            $userTeam->save();

            return $this->success([
                'id' => $userTeam->id,
                'source_team_id' => $systemTeamId,
                'status' => $userTeam->status,
            ], '已从个人作业列表中取消');
        });
    }

    /**
     * 创建自定义作业。
     *
     * 游客和正式注册用户均可创建。
     */
    public function storeCustom(StoreCustomTeamRequest $request)
    {
        $uid = $this->uid();

        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        // 获取已经通过 Form Request 参数验证的角色 ID。
        $roleIds = array_map(
            'intval',
            $request->input('roles')
        );

        // 缺失角色必须属于本次提交的 5 个角色。
        $missingRoleId = $request->input('missing_role_id');

        if (
            $missingRoleId !== null
            && ! in_array((int) $missingRoleId, $roleIds, true)
        ) {
            return $this->error('指定的缺失角色不属于本次作业', 422);
        }

        // 查询游戏角色，并确保提交的 5 个角色都存在。
        $roles = GameRole::whereIn('id', $roleIds)
            ->get();

        if ($roles->count() !== 5) {
            return $this->error('部分游戏角色不存在，请重新选择', 422);
        }

        // 按角色站位从大到小排序；站位相同时按角色 ID 升序。
        $roles = $roles
            ->sort(function ($a, $b) {
                $positionCompare = $b->search_area_width
                    <=> $a->search_area_width;

                if ($positionCompare !== 0) {
                    return $positionCompare;
                }

                return $a->id <=> $b->id;
            })
            ->values();

        // 按排序后的角色 ID 生成 team_key。
        $teamKey = $roles
            ->pluck('id')
            ->implode(',');

        // 根据 5 个角色的攻击类型计算 atk_value。
        $atkValue = (int) $roles->sum('atk_type');

        return DB::transaction(function () use (
            $uid,
            $request,
            $roles,
            $teamKey,
            $atkValue,
            $missingRoleId
        ) {
            // 创建个人自定义作业。
            $team = Team::create([
                'stage' => 4,
                'source_team_id' => null,
                'uid' => $uid,
                'boss' => (int) $request->input('boss'),
                'damage' => (int) $request->input('damage'),
                'team_key' => $teamKey,
                'status' => 1,
                'mode' => (int) $request->input('mode'),
                'atk_value' => $atkValue,
                'remark' => $request->input('remark'),
                'import_timestamp' => null,
            ]);

            // 创建 5 条角色记录。
            $teamRoles = [];

            foreach ($roles as $role) {
                $teamRoles[] = [
                    'team_id' => $team->id,
                    'role_id' => $role->id,
                    'status' => (
                        $missingRoleId !== null
                        && (int) $missingRoleId === (int) $role->id
                    ) ? 0 : 1,
                ];
            }

            TeamRole::insert($teamRoles);

            return $this->success([
                'id' => $team->id,
                'boss' => $team->boss,
                'damage' => $team->damage,
                'stage' => $team->stage,
                'mode' => $team->mode,
                'team_key' => $team->team_key,
                'atk_value' => $team->atk_value,
                'status' => $team->status,
                'missing_role_id' => $missingRoleId === null
                    ? null
                    : (int) $missingRoleId,
                'roles' => $teamRoles,
            ], '自定义作业创建成功');
        });
    }


    
    /**
     * 完整修改当前用户自己的自定义作业。
     *
     * 保留 teams.id 和原有 5 条 team_roles.id。
     *
     * 必填参数：
     * boss、damage、mode、roles
     *
     * 可选参数：
     * remark、missing_role_id
     */
    public function updateCustom(
        UpdateCustomTeamRequest $request,
        int $teamId
    ) {
        $uid = $this->uid();

        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        // 获取通过 Form Request 验证的角色 ID。
        $roleIds = array_map(
            'intval',
            $request->input('roles')
        );

        // 缺失角色必须属于本次提交的 5 个角色。
        $missingRoleId = $request->input('missing_role_id');

        if (
            $missingRoleId !== null
            && ! in_array((int) $missingRoleId, $roleIds, true)
        ) {
            return $this->error('指定的缺失角色不属于本次作业', 422);
        }

        // 查询角色，确保提交的 5 个角色全部存在。
        $roles = GameRole::whereIn('id', $roleIds)->get();

        if ($roles->count() !== 5) {
            return $this->error('部分游戏角色不存在，请重新选择', 422);
        }

        // 按站位降序排列；站位相同时按角色 ID 升序排列。
        $roles = $roles
            ->sort(function ($a, $b) {
                $positionCompare = $b->search_area_width
                    <=> $a->search_area_width;

                if ($positionCompare !== 0) {
                    return $positionCompare;
                }

                return $a->id <=> $b->id;
            })
            ->values();

        // 生成新的 team_key 和 atk_value。
        $teamKey = $roles
            ->pluck('id')
            ->implode(',');

        $atkValue = (int) $roles->sum('atk_type');

        return DB::transaction(function () use (
            $uid,
            $teamId,
            $request,
            $roles,
            $teamKey,
            $atkValue,
            $missingRoleId
        ) {
            // 只能修改当前用户自己的、有效的自定义作业。
            // 系统作业和其他用户的作业均不可修改。
            $team = Team::where('id', $teamId)
                ->where('uid', $uid)
                ->whereNull('source_team_id')
                ->where('status', 1)
                ->lockForUpdate()
                ->first();

            if (! $team) {
                return $this->error(
                    '自定义作业不存在或无权修改',
                    404
                );
            }

            // 锁定原有角色记录，必须恰好有 5 条。
            $teamRoleRecords = $team->teamRoles()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($teamRoleRecords->count() !== 5) {
                return $this->error(
                    '原作业角色记录数量异常，无法安全修改',
                    422
                );
            }

            // 原有角色 ID。
            $existingRoleIds = $teamRoleRecords
                ->pluck('role_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            // 新阵容角色 ID，已经按站位排序。
            $newRoleIds = $roles
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            // 找出新阵容中新增的角色。
            // 这些角色一定不在原阵容中，因此不会与原有记录冲突。
            $roleIdsToAdd = array_values(
                array_diff($newRoleIds, $existingRoleIds)
            );

            // 找出原阵容中被移除的角色记录。
            $recordsToReplace = $teamRoleRecords
                ->filter(function ($teamRole) use ($newRoleIds) {
                    return ! in_array(
                        (int) $teamRole->role_id,
                        $newRoleIds,
                        true
                    );
                })
                ->values();

            // 两边数量必须一致，否则不进行修改。
            if ($recordsToReplace->count() !== count($roleIdsToAdd)) {
                return $this->error(
                    '新旧角色阵容不匹配，无法安全修改',
                    422
                );
            }

            // 更新 teams 主表。
            // 不修改 id、uid、stage、source_team_id。
            $team->boss = (int) $request->input('boss');
            $team->damage = (int) $request->input('damage');
            $team->mode = (int) $request->input('mode');
            $team->remark = $request->input('remark');
            $team->team_key = $teamKey;
            $team->atk_value = $atkValue;
            $team->save();

            // 只替换真正被移除的角色。
            // 新角色不在旧阵容中，不会触发唯一索引冲突。
            foreach ($recordsToReplace as $index => $teamRole) {
                $teamRole->role_id = $roleIdsToAdd[$index];
                $teamRole->save();
            }

            // 更新全部角色记录的状态。
            // 保留角色不更换记录 ID，只更新 status。
            foreach ($teamRoleRecords as $teamRole) {
                $teamRole->status = (
                    $missingRoleId !== null
                    && (int) $missingRoleId === (int) $teamRole->role_id
                ) ? 0 : 1;

                $teamRole->save();
            }

            // 返回更新后的作业和原有角色记录 ID。
            return $this->success([
                'id' => $team->id,
                'boss' => $team->boss,
                'damage' => $team->damage,
                'stage' => $team->stage,
                'mode' => $team->mode,
                'team_key' => $team->team_key,
                'atk_value' => $team->atk_value,
                'status' => $team->status,
                'missing_role_id' => $missingRoleId === null
                    ? null
                    : (int) $missingRoleId,
                'roles' => $team->teamRoles()
                    ->orderBy('id')
                    ->get([
                        'id',
                        'team_id',
                        'role_id',
                        'status',
                    ]),
            ], '自定义作业修改成功');
        });
    }

    /**
     * 软删除当前用户自己的自定义作业。
     *
     * 只将 teams.status 修改为 0，不删除作业、角色或链接记录。
     */
    public function destroyCustom(int $teamId)
    {
        $uid = $this->uid();

        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        return DB::transaction(function () use ($uid, $teamId) {
            $team = Team::where('id', $teamId)
                ->where('uid', $uid)
                ->whereNull('source_team_id')
                ->where('status', 1)
                ->lockForUpdate()
                ->first();

            if (! $team) {
                return $this->error('自定义作业不存在或无权删除', 404);
            }

            $team->status = 0;
            $team->save();

            return $this->success([
                'id' => $team->id,
                'status' => $team->status,
            ], '自定义作业已删除');
        });
    }


}
