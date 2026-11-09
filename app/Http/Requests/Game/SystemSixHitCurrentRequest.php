<?php

namespace App\Http\Requests\Game;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SystemSixHitCurrentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $uid = (int) ($this->user()?->id ?? 0);

        return [
            'accountId' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('user_role_pools', 'id')->where(
                    static fn ($query) => $query
                        ->where('uid', $uid)
                        ->where('status', 1)
                ),
            ],
            'lowBosses' => ['nullable', 'array', 'max:3'],
            'lowBosses.*' => ['integer', 'between:1,5'],
            'highBosses' => ['nullable', 'array', 'max:3'],
            'highBosses.*' => ['integer', 'between:1,5'],
            'mode' => ['nullable', 'integer', 'in:1,2'],
            'stage' => ['nullable', 'integer', 'in:0,2,3'],
            'atkType' => ['nullable', 'integer', 'between:0,4'],
            'lockedIds' => ['nullable', 'array', 'max:4'],
            'lockedIds.*' => ['integer', 'min:1'],
            'hiddenIds' => ['nullable', 'array'],
            'hiddenIds.*' => ['integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'accountId.required' => '游戏账号不能为空',
            'accountId.integer' => '游戏账号必须是整数',
            'accountId.min' => '游戏账号参数无效',
            'accountId.exists' => '游戏账号不存在或不属于当前用户',
            'lowBosses.array' => '低阶段 BOSS 筛选格式错误',
            'lowBosses.max' => '低阶段 BOSS 最多选择 3 个',
            'lowBosses.*.integer' => '低阶段 BOSS 必须是整数',
            'lowBosses.*.between' => '低阶段 BOSS 必须在 1 到 5 之间',
            'highBosses.array' => '高阶段 BOSS 筛选格式错误',
            'highBosses.max' => '高阶段 BOSS 最多选择 3 个',
            'highBosses.*.integer' => '高阶段 BOSS 必须是整数',
            'highBosses.*.between' => '高阶段 BOSS 必须在 1 到 5 之间',
            'mode.integer' => '作业模式无效',
            'mode.in' => '作业模式只能是自动或手动',
            'stage.integer' => '阶段筛选无效',
            'stage.in' => '阶段只能是不限、B 或 C',
            'atkType.integer' => '攻击类型筛选无效',
            'atkType.between' => '攻击类型参数无效',
            'lockedIds.array' => '锁定作业格式错误',
            'lockedIds.max' => '锁定作业最多 4 个',
            'lockedIds.*.integer' => '锁定作业 ID 必须是整数',
            'lockedIds.*.min' => '锁定作业 ID 必须大于 0',
            'hiddenIds.array' => '隐藏作业格式错误',
            'hiddenIds.*.integer' => '隐藏作业 ID 必须是整数',
            'hiddenIds.*.min' => '隐藏作业 ID 必须大于 0',
        ];
    }
}
