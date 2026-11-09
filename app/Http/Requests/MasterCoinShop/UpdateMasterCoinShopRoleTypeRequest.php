<?php

namespace App\Http\Requests\MasterCoinShop;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMasterCoinShopRoleTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
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
            'type.required' => '商店分组不能为空',
            'type.integer' => '商店分组必须是整数',
            'type.in' => '商店分组只能是 FES 或非 FES',
        ];
    }
}
