<?php

namespace App\Http\Requests\User;

use App\Rules\NicknameByteLength;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $nickname = $this->input('nickname');

        if (is_string($nickname)) {
            $this->merge([
                'nickname' => Str::trim($nickname),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'nickname' => [
                'sometimes',
                'required',
                'string',
                new NicknameByteLength(
                    (int) config('profile.nickname_min_bytes', 1),
                    (int) config('profile.nickname_max_bytes', 24),
                ),
            ],
            'avatar' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->has('nickname') && ! $this->has('avatar')) {
                    $validator->errors()->add(
                        'profile',
                        '请至少提交一个需要修改的字段'
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'nickname.required' => '请输入昵称',
            'nickname.string' => '昵称必须是字符串',
            'avatar.required' => '请选择头像',
            'avatar.integer' => '头像参数不正确',
            'avatar.min' => '头像参数不正确',
        ];
    }
}
