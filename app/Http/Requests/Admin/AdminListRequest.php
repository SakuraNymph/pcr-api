<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => [
                'nullable',
                'string',
                'max:50',
            ],

            'nickname' => [
                'nullable',
                'string',
                'max:50',
            ],

            'status' => [
                'nullable',
                'integer',
                Rule::in([0, 1]),
            ],

            'role_id' => [
                'nullable',
                'integer',
                'exists:roles,id',
            ],

            'created_start' => [
                'nullable',
                'date',
            ],

            'created_end' => [
                'nullable',
                'date',
            ],

            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'username.max' => '用户名不能超过50个字符',

            'nickname.max' => '昵称不能超过50个字符',

            'status.integer' => '状态参数格式错误',
            'status.in' => '状态参数错误',

            'role_id.integer' => '角色参数格式错误',
            'role_id.exists' => '选择的角色不存在',

            'created_start.date' => '开始时间格式错误',
            'created_end.date' => '结束时间格式错误',

            'page.integer' => '页码参数格式错误',
            'page.min' => '页码不能小于1',

            'per_page.integer' => '每页数量格式错误',
            'per_page.min' => '每页数量不能小于1',
            'per_page.max' => '每页最多显示100条数据',
        ];
    }
}
