<?php

namespace App\Http\Requests\MasterCoinShop;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserMasterCoinShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'roles' => [
                'present',
                'array',
            ],
            'roles.*' => [
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
            'roles.present' => '大师币角色列表不能为空',
            'roles.array' => '大师币角色列表必须是数组',
            'roles.*.required' => '大师币角色ID不能为空',
            'roles.*.integer' => '大师币角色ID必须是整数',
            'roles.*.min' => '大师币角色ID必须大于0',
            'roles.*.distinct' => '大师币角色不能重复选择',
        ];
    }
}
