<?php

namespace App\Http\Requests\Menu;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMenuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'parent_id' => [
                'required',
                'integer',
                'min:0',
            ],

            'type' => [
                'required',
                'integer',
                'in:1,2,3',
            ],

            'name' => [
                'required',
                'string',
                'max:100',
                'unique:menus,name',
            ],

            'path' => [
                'nullable',
                'string',
                'max:255',
            ],

            'component' => [
                'nullable',
                'string',
                'max:255',
            ],

            'redirect' => [
                'nullable',
                'string',
                'max:255',
            ],

            'title' => [
                'required',
                'string',
                'max:100',
            ],

            'icon' => [
                'nullable',
                'string',
                'max:100',
            ],

            'permission' => [
                'nullable',
                'string',
                'max:100',
            ],

            'sort' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'hidden' => [
                'nullable',
                'boolean',
            ],

            'keep_alive' => [
                'nullable',
                'boolean',
            ],

            'affix_tab' => [
                'nullable',
                'boolean',
            ],

            'iframe_src' => [
                'nullable',
                'string',
                'max:500',
            ],

            'link' => [
                'nullable',
                'string',
                'max:500',
            ],

            'status' => [
                'nullable',
                'integer',
                'in:0,1',
            ],
        ];

        // parent_id = 0 表示一级菜单，不需要验证父级菜单是否存在
        // parent_id > 0 时，才要求对应的父菜单存在
        if ((int) $this->input('parent_id', 0) > 0) {
            $rules['parent_id'][] = Rule::exists('menus', 'id');
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('parent_id') === null) {
            $this->merge([
                'parent_id' => 0,
            ]);
        }
    }
}
