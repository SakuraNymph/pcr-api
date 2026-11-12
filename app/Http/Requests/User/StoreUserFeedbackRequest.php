<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreUserFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['title', 'content', 'client_type', 'client_version'] as $field) {
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
            'category' => ['required', 'integer', 'in:1,2,3,4'],
            'title' => ['required', 'string', 'min:2', 'max:30'],
            'content' => ['required', 'string', 'min:5', 'max:1000'],
            'client_type' => ['nullable', 'string', 'max:20'],
            'client_version' => ['nullable', 'string', 'max:50'],
            'images' => ['nullable', 'array', 'max:3'],
            'images.*' => [
                'file',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:5120',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'category.required' => '请选择反馈分类',
            'category.in' => '反馈分类不正确',
            'title.required' => '请输入反馈标题',
            'title.min' => '反馈标题至少需要 2 个字符',
            'title.max' => '反馈标题不能超过 30 个字符',
            'content.required' => '请输入反馈内容',
            'content.min' => '反馈内容至少需要 5 个字符',
            'content.max' => '反馈内容不能超过 1000 个字符',
            'images.max' => '每条消息最多上传 3 张图片',
            'images.*.mimes' => '仅支持 JPG、JPEG、PNG、WEBP 格式的图片',
            'images.*.mimetypes' => '仅支持 JPG、JPEG、PNG、WEBP 格式的图片',
            'images.*.max' => '单张图片不能超过 5MB',
        ];
    }
}
