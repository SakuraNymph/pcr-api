<?php

namespace App\Http\Requests\CardStatistics;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCardStatisticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_id' => [
                'required',
                'integer',
                'min:1',
            ],
            'year' => [
                'required',
                'integer',
                'between:2000,2100',
            ],
            'data' => [
                'present',
                'array',
            ],
            'data.*' => [
                'array:id,count',
            ],
            'data.*.id' => [
                'required',
                'distinct:strict',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value) || $value < 1) {
                        $fail('角色ID必须是正整数');
                    }
                },
            ],
            'data.*.count' => [
                'required',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value) || $value < 0 || $value > 200) {
                        $fail('抽数必须是0到200之间的整数');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'account_id.required' => '游戏账号不能为空',
            'account_id.integer' => '游戏账号必须是整数',
            'account_id.min' => '游戏账号参数无效',
            'year.required' => '年份不能为空',
            'year.integer' => '年份必须是整数',
            'year.between' => '年份必须在2000到2100之间',
            'data.present' => '抽卡记录不能为空',
            'data.array' => '抽卡记录必须是数组',
            'data.*.array' => '每条抽卡记录必须包含角色ID和抽数',
            'data.*.id.required' => '角色ID不能为空',
            'data.*.id.distinct' => '同一角色不能重复提交',
            'data.*.count.required' => '抽数不能为空',
        ];
    }
}
