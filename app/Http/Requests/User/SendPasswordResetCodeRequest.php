<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class SendPasswordResetCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge([
                'email' => trim($this->input('email')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => '请输入注册邮箱',
            'email.string' => '邮箱格式不正确',
            'email.email' => '请输入有效的邮箱地址',
            'email.max' => '邮箱长度不能超过255个字符',
        ];
    }
}
