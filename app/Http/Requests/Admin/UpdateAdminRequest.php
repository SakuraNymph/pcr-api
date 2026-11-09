<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $adminId = $this->route('admin');

        return [
            'username' => [
                'required',
                'string',
                'max:50',
                Rule::unique('admins', 'username')->ignore($adminId),
            ],

            'nickname' => [
                'nullable',
                'string',
                'max:50',
            ],

            'password' => [
                'nullable',
                'string',
                'min:6',
                'max:50',
            ],

            'email' => [
                'nullable',
                'email',
                'max:100',
            ],

            'mobile' => [
                'nullable',
                'string',
                'max:20',
            ],

            'status' => [
                'required',
                'integer',
                Rule::in([0, 1]),
            ],

            'role_ids' => [
                'required',
                'array',
            ],

            'role_ids.*' => [
                'integer',
                'exists:roles,id',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'username.required' => '用户名不能为空',
            'username.unique' => '用户名已经存在',
            'username.max' => '用户名不能超过50个字符',

            'nickname.max' => '昵称不能超过50个字符',

            'password.min' => '密码不能少于6个字符',
            'password.max' => '密码不能超过50个字符',

            'email.email' => '邮箱格式不正确',
            'email.max' => '邮箱不能超过100个字符',

            'mobile.max' => '手机号不能超过20个字符',

            'status.required' => '状态不能为空',
            'status.in' => '状态参数错误',

            'role_ids.required' => '角色不能为空',
            'role_ids.array' => '角色参数格式错误',
            'role_ids.*.exists' => '选择的角色不存在',
        ];
    }
}
