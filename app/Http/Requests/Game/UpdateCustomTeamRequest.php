<?php

namespace App\Http\Requests\Game;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomTeamRequest extends FormRequest
{
    /**
     * 身份认证由路由中间件负责。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 完整修改自定义作业，所有可编辑字段均需提交。
     */
    public function rules(): array
    {
        return [
            'boss' => [
                'required',
                'integer',
                'between:1,5',
            ],

            'damage' => [
                'required',
                'integer',
                'min:1',
                'max:99999',
            ],

            'mode' => [
                'required',
                'integer',
                'in:1,2',
            ],

            'remark' => [
                'nullable',
                'string',
                'max:255',
            ],

            'roles' => [
                'required',
                'array',
                'size:5',
            ],

            'roles.*' => [
                'required',
                'integer',
                'distinct',
                'min:1',
            ],

            'missing_role_id' => [
                'nullable',
                'integer',
                'min:1',
            ],
        ];
    }

    /**
     * 验证失败时的提示信息。
     */
    public function messages(): array
    {
        return [
            'boss.required' => '请选择 BOSS',
            'boss.integer' => 'BOSS 位置必须是整数',
            'boss.between' => 'BOSS 位置只能是 1–5',

            'damage.required' => '请输入伤害',
            'damage.integer' => '伤害必须是整数',
            'damage.min' => '伤害必须大于 0',
            'damage.max' => '伤害不能超过 99999',

            'mode.required' => '请选择作业模式',
            'mode.integer' => '作业模式无效',
            'mode.in' => '作业模式只能是自动或手动',

            'remark.string' => '备注必须是字符串',
            'remark.max' => '备注不能超过 255 个字符',

            'roles.required' => '请选择角色阵容',
            'roles.array' => '角色阵容格式错误',
            'roles.size' => '角色阵容必须恰好包含 5 个角色',

            'roles.*.required' => '角色 ID 不能为空',
            'roles.*.integer' => '角色 ID 必须是整数',
            'roles.*.distinct' => '角色不能重复',
            'roles.*.min' => '角色 ID 无效',

            'missing_role_id.integer' => '缺失角色 ID 必须是整数',
            'missing_role_id.min' => '缺失角色 ID 无效',
        ];
    }
}
