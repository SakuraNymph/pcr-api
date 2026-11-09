<?php

namespace App\Http\Requests\CardStatistics;

use Illuminate\Foundation\Http\FormRequest;

class GetCardStatisticsRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_id' => [
                'required',
                'integer',
                'min:1',
            ],
            'year' => [
                'sometimes',
                'nullable',
                'integer',
                'between:2000,2100',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'account_id.required' => '游戏账号不能为空',
            'account_id.integer' => '游戏账号必须是整数',
            'account_id.min' => '游戏账号参数无效',
            'year.integer' => '年份必须是整数',
            'year.between' => '年份必须在2000到2100之间',
        ];
    }
}
