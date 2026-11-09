<?php

namespace App\Http\Requests\Menu;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * DeleteMenuRequest
 *
 * 负责校验：
 *  - ids 必须是一个非空数组
 *  - 每个元素必须是整数且对应的菜单确实存在
 *  - （可选）若使用软删，可在 exists 里加入 whereNull('deleted_at')
 */
class DeleteMenuRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 根据业务自行写权限检查，例如:
        // return auth()->user()->can('menu:delete');
        return true;
    }

    public function rules(): array
    {
        return [
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => [
                'required',
                'integer',
                'distinct', // 防止同一个 id 重复出现
                Rule::exists('menus', 'id'), // 如有软删请追加 ->whereNull('deleted_at')
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required'   => '请至少提交一个要删除的 ID',
            'ids.array'      => 'ids 必须是数组形式',
            'ids.min'        => 'ids 至少要包含一个元素',
            'ids.*.integer'  => '每个 id 必须是整数',
            'ids.*.distinct' => 'ids 中不允许出现重复的值',
            'ids.*.exists'   => '选中的菜单不存在或已被删除',
        ];
    }

    /** 把所有的 id 转成整数（防止前端传字符串） */
    protected function prepareForValidation(): void
    {
        $ids = $this->input('ids', []);
        $this->merge([
            'ids' => array_map('intval', $ids),
        ]);
    }
}