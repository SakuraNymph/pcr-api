<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class AdminFeedbackIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['nickname', 'title'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $normalized[$field] = Str::trim($value);
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['nullable', 'integer', 'in:0,1,2,3'],
            'category' => ['nullable', 'integer', 'in:1,2,3,4'],
            'public_uid' => ['nullable', 'integer', 'min:1'],
            'nickname' => ['nullable', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:100'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'has_unread' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => '反馈状态不正确',
            'category.in' => '反馈分类不正确',
            'public_uid.integer' => 'UID 必须是数字',
            'start_date.date_format' => '开始日期格式应为 YYYY-MM-DD',
            'end_date.date_format' => '结束日期格式应为 YYYY-MM-DD',
        ];
    }
}
