<?php

namespace App\Http\Requests\Game;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateTeamLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'author' => ['required', 'string', 'max:16'],
            'link' => ['required', 'url:http,https', 'max:1000'],
            'difficulty' => ['required', 'integer', 'between:1,5'],
            'damage' => ['nullable', 'integer', 'between:1,99999'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $author = $this->input('author');
            if (!is_string($author) || $author === '') {
                return;
            }

            if (!preg_match('/^[\x{4E00}-\x{9FFF}A-Za-z0-9]+$/u', $author)) {
                $validator->errors()->add('author', '作者只能输入中文、字母、数字');
                return;
            }

            $length = 0;
            foreach (preg_split('//u', $author, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
                $length += preg_match('/^[\x{4E00}-\x{9FFF}]$/u', $char) ? 2 : 1;
            }
            if ($length > 16) {
                $validator->errors()->add('author', '作者长度不能超过16个长度单位，最多8个汉字');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'author.required' => '作者不能为空',
            'author.max' => '作者长度不能超过16个长度单位',
            'link.required' => '来源链接不能为空',
            'link.url' => '来源链接格式错误',
            'difficulty.required' => '难度不能为空',
            'difficulty.between' => '难度必须是1-5',
            'damage.integer' => '伤害必须是整数',
            'damage.between' => '伤害范围必须是1-99999',
        ];
    }
}
