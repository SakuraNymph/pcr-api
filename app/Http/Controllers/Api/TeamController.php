<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Game\StoreTeamRequest;
use App\Http\Requests\Game\UpdateTeamLinkRequest;
use App\Http\Requests\Game\UpdateTeamRequest;
use App\Models\Team;
use App\Models\TeamLink;
use App\Services\TeamService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TeamService $teamService)
    {
    }

    /** 作业列表与筛选 */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'stage' => ['nullable', 'integer', 'between:1,5'],
            'boss' => ['nullable', 'integer', 'between:1,5'],
            'mode' => ['nullable', 'integer', Rule::in([1, 2])],
            'difficulty' => ['nullable', 'integer', 'between:1,5'],
            'author' => ['nullable', 'string', 'max:32'],
            'atk_type' => ['nullable', 'integer', Rule::in([-1, 0, 1])],
            'status' => ['nullable', 'integer', Rule::in([0, 1])],
        ]);
        return $this->success($this->teamService->list($filters));
    }

    /** 本月 D 阶段阵容首角色候选。 */
    public function dStageLineupOptions(Request $request)
    {
        $data = $request->validate(['prefix' => ['required', 'string', 'max:64']]);
        return $this->success($this->teamService->dStageLineupOptions($data['prefix']));
    }

    /** 获取单条作业详情 */
    public function show(Team $team)
    {
        return $this->success($this->teamService->present($team));
    }

    /** 管理员批量添加作业 */
    public function store(StoreTeamRequest $request)
    {
        $count = $this->teamService->createBatch($request->validated());

        return $this->success(['count' => $count], '作业添加成功');
    }

    /** 修改单条作业 */
    public function update(UpdateTeamRequest $request, Team $team)
    {
        $this->teamService->update($team, $request->validated());

        return $this->success(
            $this->teamService->present($team->fresh()),
            '作业修改成功'
        );
    }

    /** 修改单条来源链接，所属团队字段保持共享且只读。 */
    public function updateLink(UpdateTeamLinkRequest $request, Team $team, TeamLink $teamLink)
    {
        abort_unless((int) $teamLink->team_id === (int) $team->id, 404);

        return $this->success(
            $this->teamService->updateLink($team, $teamLink, $request->validated()),
            '来源作业修改成功'
        );
    }

    /** 只隐藏当前来源链接。 */
    public function destroyLink(Team $team, TeamLink $teamLink)
    {
        abort_unless((int) $teamLink->team_id === (int) $team->id, 404);

        $this->teamService->setLinkStatus($teamLink, 0);

        return $this->success(null, '来源作业已隐藏');
    }

    /** 恢复或隐藏当前来源链接。 */
    public function updateLinkStatus(Request $request, Team $team, TeamLink $teamLink)
    {
        abort_unless((int) $teamLink->team_id === (int) $team->id, 404);

        $data = $request->validate([
            'status' => ['required', 'integer', Rule::in([0, 1])],
        ]);

        $status = (int) $data['status'];
        $this->teamService->setLinkStatus($teamLink, $status);

        return $this->success(null, $status === 1 ? '来源作业已恢复' : '来源作业已隐藏');
    }

    /** 将作业软隐藏，保留角色和来源链接 */
    public function destroy(Team $team)
    {
        $this->teamService->setStatus($team, 0);

        return $this->success(null, '作业已隐藏');
    }

    /** 恢复或隐藏作业状态 */
    public function updateStatus(Request $request, Team $team)
    {
        $data = $request->validate([
            'status' => ['required', 'integer', Rule::in([0, 1])],
        ]);

        $status = (int) $data['status'];
        $this->teamService->setStatus($team, $status);

        return $this->success(null, $status === 1 ? '作业已恢复' : '作业已隐藏');
    }
}
