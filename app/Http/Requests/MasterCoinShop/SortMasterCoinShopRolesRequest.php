<?php

namespace App\Http\Requests\MasterCoinShop;

use Illuminate\Foundation\Http\FormRequest;

class SortMasterCoinShopRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'game_role_ids' => [
                'required',
                'array',
                'min:1',
            ],
            'game_role_ids.*' => [
                'required',
                'integer',
                'min:1',
                'distinct',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'game_role_ids.required' => '排序角色列表不能为空',
            'game_role_ids.array' => '排序角色列表格式不正确',
            'game_role_ids.min' => '排序角色列表不能为空',
            'game_role_ids.*.required' => '排序角色不能为空',
            'game_role_ids.*.integer' => '排序角色ID必须是整数',
            'game_role_ids.*.min' => '排序角色ID必须大于0',
            'game_role_ids.*.distinct' => '排序角色不能重复',
        ];
    }
}
