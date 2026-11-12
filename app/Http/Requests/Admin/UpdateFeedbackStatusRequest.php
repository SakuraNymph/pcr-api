<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFeedbackStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'integer', 'in:0,1,2,3'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => '请选择反馈状态',
            'status.integer' => '反馈状态不正确',
            'status.in' => '反馈状态不正确',
        ];
    }
}
