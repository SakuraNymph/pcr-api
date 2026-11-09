<?php

namespace App\Http\Requests\Menu;

use App\Models\Menu;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMenuRequest extends FormRequest
{
    /**
     * 判断当前用户是否有权限执行请求。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 验证规则。
     */
    public function rules(): array
    {
        $menu = $this->route('menu');

        $menuId = $menu instanceof Menu
            ? $menu->id
            : (int) $menu;

        $rules = [
            // 父级菜单
            // 0 表示顶级菜单
            'parent_id' => [
                'required',
                'integer',
                'min:0',
            ],

            // 菜单类型
            // 1 = 目录
            // 2 = 菜单
            // 3 = 按钮
            'type' => [
                'required',
                'integer',
                Rule::in([1, 2, 3]),
            ],

            // 菜单名称
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('menus', 'name')->ignore($menuId),
            ],

            // 路由路径
            'path' => [
                'nullable',
                'string',
                'max:255',
            ],

            // 前端组件
            'component' => [
                'nullable',
                'string',
                'max:255',
            ],

            // 重定向
            'redirect' => [
                'nullable',
                'string',
                'max:255',
            ],

            // 菜单标题
            'title' => [
                'required',
                'string',
                'max:100',
            ],

            // 图标
            'icon' => [
                'nullable',
                'string',
                'max:255',
            ],

            // 权限标识
            'permission' => [
                'nullable',
                'string',
                'max:255',
            ],

            // 排序
            'sort' => [
                'nullable',
                'integer',
                'min:0',
            ],

            // 隐藏
            'hidden' => [
                'nullable',
                'boolean',
            ],

            // 缓存
            'keep_alive' => [
                'nullable',
                'boolean',
            ],

            // 固定标签页
            'affix_tab' => [
                'nullable',
                'boolean',
            ],

            // iframe 地址
            'iframe_src' => [
                'nullable',
                'string',
                'max:500',
            ],

            // 外链
            'link' => [
                'nullable',
                'string',
                'max:500',
            ],

            // 状态
            'status' => [
                'nullable',
                'boolean',
            ],
        ];

        /**
         * parent_id = 0 表示顶级菜单，不需要检查数据库存在。
         *
         * parent_id > 0 时：
         * 1. 父级菜单必须存在
         * 2. 不能是自己
         * 3. 不能是自己的子孙节点
         */
        if ((int) $this->input('parent_id', 0) > 0) {
            $rules['parent_id'][] = Rule::exists('menus', 'id');

            $rules['parent_id'][] = function ($attribute, $value, $fail) use ($menuId) {
                $parentId = (int) $value;

                // 不能把自己设置成自己的父级
                if ($parentId === $menuId) {
                    $fail('父级菜单不能是自身');

                    return;
                }

                // 不能把自己的子孙菜单设置成父级
                if ($menuId > 0) {
                    $descendantIds = Menu::descendantIds($menuId);

                    if (in_array($parentId, $descendantIds, true)) {
                        $fail('父级菜单不能是当前菜单的子孙菜单');
                    }
                }
            };
        }

        return $rules;
    }

    /**
     * 验证失败时的错误信息。
     */
    public function messages(): array
    {
        return [
            'parent_id.required' => '父级菜单 ID 必填',
            'parent_id.integer' => '父级菜单 ID 必须是整数',
            'parent_id.min' => '父级菜单 ID 不能小于 0',
            'parent_id.exists' => '父级菜单不存在或已被删除',

            'type.required' => '菜单类型不能为空',
            'type.integer' => '菜单类型必须是整数',
            'type.in' => '菜单类型无效',

            'name.required' => '菜单名称不能为空',
            'name.max' => '菜单名称不能超过100个字符',
            'name.unique' => '该菜单名称已被使用',

            'path.string' => '路由路径必须是字符串',
            'path.max' => '路由路径不能超过255个字符',

            'component.string' => '组件路径必须是字符串',
            'component.max' => '组件路径不能超过255个字符',

            'redirect.string' => '重定向地址必须是字符串',
            'redirect.max' => '重定向地址不能超过255个字符',

            'title.required' => '菜单标题不能为空',
            'title.max' => '菜单标题不能超过100个字符',

            'icon.string' => '图标必须是字符串',
            'icon.max' => '图标不能超过255个字符',

            'permission.string' => '权限标识必须是字符串',
            'permission.max' => '权限标识不能超过255个字符',

            'sort.integer' => '排序必须是整数',
            'sort.min' => '排序不能小于0',

            'hidden.boolean' => '隐藏设置必须是布尔值',
            'keep_alive.boolean' => '缓存设置必须是布尔值',
            'affix_tab.boolean' => '固定标签页必须是布尔值',

            'iframe_src.string' => 'iframe 地址必须是字符串',
            'iframe_src.max' => 'iframe 地址不能超过500个字符',

            'link.string' => '外链地址必须是字符串',
            'link.max' => '外链地址不能超过500个字符',

            'status.boolean' => '状态必须是布尔值',
        ];
    }

    /**
     * 验证前处理请求数据。
     *
     * menus 表中的 path 等字段不允许 NULL，
     * 因此空值统一转换成空字符串。
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            // 0 = 顶级菜单
            'parent_id' => (int) $this->input('parent_id', 0),

            // 1 = 目录
            // 2 = 菜单
            // 3 = 按钮
            'type' => (int) $this->input('type', 1),

            // 数据库不允许 NULL
            'path' => $this->input('path') ?? '',

            'component' => $this->input('component') ?? '',

            'redirect' => $this->input('redirect') ?? '',

            'title' => $this->input('title', ''),

            'icon' => $this->input('icon') ?? '',

            'permission' => $this->input('permission') ?? '',

            'sort' => (int) $this->input('sort', 0),

            'iframe_src' => $this->input('iframe_src') ?? '',

            'link' => $this->input('link') ?? '',
        ]);

        /**
         * 布尔字段只有在请求中存在时才进行转换，
         * 避免编辑时没有传某个字段却被强制覆盖。
         */
        if ($this->has('hidden')) {
            $this->merge([
                'hidden' => $this->boolean('hidden'),
            ]);
        }

        if ($this->has('keep_alive')) {
            $this->merge([
                'keep_alive' => $this->boolean('keep_alive'),
            ]);
        }

        if ($this->has('affix_tab')) {
            $this->merge([
                'affix_tab' => $this->boolean('affix_tab'),
            ]);
        }

        if ($this->has('status')) {
            $this->merge([
                'status' => $this->boolean('status'),
            ]);
        }
    }
}
