<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class AppendAdminFeedbackMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $content = $this->input('content');

        if (is_string($content)) {
            $this->merge(['content' => Str::trim($content)]);
        }
    }

    public function rules(): array
    {
        return [
            'content' => ['nullable', 'string', 'max:1000'],
            'images' => ['nullable', 'array', 'max:3'],
            'images.*' => [
                'file',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:5120',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $content = $this->input('content');
                $images = $this->file('images', []);

                $hasContent = is_string($content) && $content !== '';
                $hasImage = is_array($images) && count($images) > 0;

                if (! $hasContent && ! $hasImage) {
                    $validator->errors()->add(
                        'content',
                        '请输入回复内容或至少上传一张图片'
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'content.max' => '回复内容不能超过 1000 个字符',
            'images.max' => '每条消息最多上传 3 张图片',
            'images.*.mimes' => '仅支持 JPG、JPEG、PNG、WEBP 格式的图片',
            'images.*.mimetypes' => '仅支持 JPG、JPEG、PNG、WEBP 格式的图片',
            'images.*.max' => '单张图片不能超过 5MB',
        ];
    }
}
