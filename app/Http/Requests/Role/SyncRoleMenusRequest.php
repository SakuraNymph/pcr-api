<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;

class SyncRoleMenusRequest extends FormRequest
{
    /**
     * 是否允许请求
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 验证规则
     */
    public function rules(): array
    {
        return [
            'menu_ids' => [
                'required',
                'array',
            ],

            'menu_ids.*' => [
                'required',
                'integer',
                'distinct',
                'exists:menus,id',
            ],
        ];
    }

    /**
     * 验证错误信息
     */
    public function messages(): array
    {
        return [
            'menu_ids.required' => '请选择菜单权限',
            'menu_ids.array' => '菜单权限必须是数组',
            'menu_ids.*.required' => '菜单 ID 不能为空',
            'menu_ids.*.integer' => '菜单 ID 必须是整数',
            'menu_ids.*.distinct' => '菜单权限中不能存在重复的 ID',
            'menu_ids.*.exists' => '选择的菜单不存在',
        ];
    }

    /**
     * 请求参数预处理
     *
     * 前端有时候会传：
     *
     * ["1", "2", "3"]
     *
     * 这里统一转换成：
     *
     * [1, 2, 3]
     */
    protected function prepareForValidation(): void
    {
        $menuIds = $this->input('menu_ids', []);

        $this->merge([
            'menu_ids' => array_map('intval', $menuIds),
        ]);
    }
}
