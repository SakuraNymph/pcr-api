<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username'   => 'required|string|max:255|unique:admins,username',
            'password'   => 'required|string|min:6|max:255',
            'nickname'   => 'nullable|string|max:255',
            'avatar'     => 'nullable|string|max:255',
            'email'      => 'nullable|email|max:255',
            'mobile'     => 'nullable|string|max:50',
            'status'     => 'required|integer|in:0,1',
            'role_ids'   => 'required|array',
            'role_ids.*' => 'integer|exists:roles,id',
        ];
    }

    public function messages(): array
    {
        return [
            'username.required' => '请输入用户名',
            'username.unique'   => '用户名已经存在',
            'password.required' => '请输入密码',
            'password.min'      => '密码至少6位',
            'email.email'       => '邮箱格式不正确',
            'status.in'         => '状态参数错误',
            'role_ids.required' => '请选择角色',
            'role_ids.array'    => '角色参数格式错误',
            'role_ids.*.exists' => '选择的角色不存在',
        ];
    }
}