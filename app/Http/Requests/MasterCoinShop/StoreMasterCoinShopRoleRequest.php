<?php

namespace App\Http\Requests\MasterCoinShop;

use Illuminate\Foundation\Http\FormRequest;

class StoreMasterCoinShopRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'game_role_id' => [
                'required',
                'integer',
                'min:1',
                'exists:game_roles,id',
            ],
            'type' => [
                'required',
                'integer',
                'in:1,2',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'game_role_id.required' => '游戏角色不能为空',
            'game_role_id.integer' => '游戏角色ID必须是整数',
            'game_role_id.exists' => '所选游戏角色不存在',
            'type.required' => '商店分组不能为空',
            'type.integer' => '商店分组必须是整数',
            'type.in' => '商店分组只能是 FES 或非 FES',
        ];
    }
}
