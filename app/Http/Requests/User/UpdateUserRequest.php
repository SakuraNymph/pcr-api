<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('id');

        return [
            'nickname' => [
                'nullable',
                'string',
                'max:255',
            ],

            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],

            'is_subscribe' => [
                'required',
                'boolean',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'nickname.string' => '昵称必须是字符串',
            'nickname.max' => '昵称长度不能超过255个字符',

            'email.email' => '邮箱格式不正确',
            'email.max' => '邮箱长度不能超过255个字符',
            'email.unique' => '该邮箱已经被其他用户使用',

            'is_subscribe.required' => '请选择是否订阅',
            'is_subscribe.boolean' => '订阅状态参数不正确',
        ];
    }
}
