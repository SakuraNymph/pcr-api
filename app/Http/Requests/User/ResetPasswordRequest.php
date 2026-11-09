<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reset_token' => [
                'required',
                'string',
            ],
            'password' => [
                'required',
                'string',
                'min:'.(int) config('profile.password_min_length', 8),
                'max:'.(int) config('profile.password_max_length', 16),
                'confirmed',
            ],
            'password_confirmation' => [
                'required',
                'string',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'reset_token.required' => '重置凭证无效，请重新验证邮箱',
            'reset_token.string' => '重置凭证格式不正确',
            'password.required' => '请输入新密码',
            'password.string' => '新密码格式不正确',
            'password.min' => '新密码长度不能少于6个字符',
            'password.max' => '新密码长度不能超过16个字符',
            'password.confirmed' => '两次输入的新密码不一致',
            'password_confirmation.required' => '请再次输入新密码',
            'password_confirmation.string' => '确认密码格式不正确',
        ];
    }
}
