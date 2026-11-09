<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => [
                'required',
                'string',
            ],
            'new_password' => [
                'required',
                'string',
                'min:'.(int) config('profile.password_min_length', 8),
                'max:'.(int) config('profile.password_max_length', 16),
                'confirmed',
            ],
            'new_password_confirmation' => [
                'required',
                'string',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => '请输入原密码',
            'current_password.string' => '原密码格式不正确',
            'new_password.required' => '请输入新密码',
            'new_password.string' => '新密码格式不正确',
            'new_password.min' => '新密码长度不能少于6个字符',
            'new_password.max' => '新密码长度不能超过16个字符',
            'new_password.confirmed' => '两次输入的新密码不一致',
            'new_password_confirmation.required' => '请再次输入新密码',
            'new_password_confirmation.string' => '确认密码格式不正确',
        ];
    }
}
