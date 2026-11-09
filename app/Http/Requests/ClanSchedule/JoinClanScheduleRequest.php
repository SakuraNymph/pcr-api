<?php

namespace App\Http\Requests\ClanSchedule;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class JoinClanScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('share_code');

        if (is_string($code)) {
            $normalized = preg_replace('/[\s\-]+/u', '', Str::trim($code));
            $this->merge([
                'share_code' => Str::upper($normalized ?? ''),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'share_code' => [
                'required',
                'string',
                'size:8',
                'regex:/^[A-HJ-NP-Z2-9]{8}$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'share_code.required' => '请输入排刀表分享码',
            'share_code.string' => '分享码格式错误',
            'share_code.size' => '分享码必须是8位字符',
            'share_code.regex' => '分享码包含不支持的字符',
        ];
    }
}
