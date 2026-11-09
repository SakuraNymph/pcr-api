<?php

namespace App\Http\Requests\GameRole;

use App\Models\GameRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGameRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $gameRole = $this->route('gameRole');

        $gameRoleId = $gameRole instanceof GameRole
            ? $gameRole->getKey()
            : $gameRole;

        return [
            'name_a' => [
                'required',
                'string',
                'max:100',
            ],

            'name_b' => [
                'nullable',
                'string',
                'max:100',
            ],

            'nickname' => [
                'required',
                'string',
                'max:100',
            ],

            'search_area_width' => [
                'required',
                'integer',
                'min:0',
            ],

            'position' => [
                'required',
                'integer',
                'in:1,2,3',
            ],

            'atk_type' => [
                'required',
                'integer',
                'in:-1,0,1',
            ],

            'element' => [
                'required',
                'integer',
                'between:1,5',
            ],

            'obtain' => [
                'required',
                'integer',
                'in:1,2,3',
            ],

            'probability' => [
                'required',
                'integer',
                'in:1,2',
            ],

            'up_time' => [
                'nullable',
                'date_format:Y-m-d',
            ],

            'is_clan_battle' => [
                'sometimes',
                'boolean',
            ],

            'status' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name_a.required' => '角色名称不能为空',
            'name_a.max' => '角色名称不能超过100个字符',

            'name_b.max' => '角色名称第二部分不能超过100个字符',

            'nickname.required' => '角色昵称不能为空',
            'nickname.max' => '角色昵称不能超过100个字符',

            'search_area_width.required' => '搜索范围不能为空',
            'search_area_width.integer' => '搜索范围必须是整数',
            'search_area_width.min' => '搜索范围不能小于0',

            'position.required' => '位置不能为空',
            'position.in' => '位置必须为1、2或3',

            'atk_type.required' => '攻击类型不能为空',
            'atk_type.in' => '攻击类型必须为-1、0或1',

            'element.required' => '角色属性不能为空',
            'element.between' => '角色属性必须为1到5',

            'obtain.required' => '获取方式不能为空',
            'obtain.in' => '获取方式必须为1、2或3',

            'probability.required' => '抽卡概率不能为空',
            'probability.in' => '抽卡概率必须为1或2',

            'up_time.date_format' => '卡池时间格式必须为YYYY-MM-DD',

            'is_clan_battle.boolean' => '公会战角色参数格式错误',
            'status.boolean' => '角色状态参数格式错误',
        ];
    }
}