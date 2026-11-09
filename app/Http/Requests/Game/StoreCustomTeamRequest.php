<?php

namespace App\Http\Requests\Game;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomTeamRequest extends FormRequest
{
    /**
     * 判断当前用户是否有权发起请求。
     *
     * 游客和正式注册用户均可创建自定义作业，
     * 具体身份认证由路由中间件负责。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 获取请求验证规则。
     */
    public function rules(): array
    {
        return [
            // BOSS 位置（第 1–5 个 BOSS）
            'boss' => [
                'required',
                'integer',
                'between:1,5',
            ],

            // 伤害
            'damage' => [
                'required',
                'integer',
                'min:1',
                'max:99999',
            ],

            // 作业模式：1 自动，2 手动
            'mode' => [
                'required',
                'integer',
                'in:1,2',
            ],

            // 作业备注
            'remark' => [
                'nullable',
                'string',
                'max:255',
            ],

            // 必须恰好提交 5 个角色
            'roles' => [
                'required',
                'array',
                'size:5',
            ],

            // 每个角色 ID 必须是正整数，且不能重复
            'roles.*' => [
                'required',
                'integer',
                'distinct',
                'min:1',
            ],

            // 可选的缺失角色 ID
            'missing_role_id' => [
                'nullable',
                'integer',
                'min:1',
            ],
        ];
    }

    /**
     * 获取验证失败时显示的错误信息。
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
