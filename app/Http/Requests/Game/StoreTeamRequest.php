<?php

namespace App\Http\Requests\Game;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTeamRequest extends FormRequest
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
            'records' => ['required', 'array', 'min:1'],
            'records.*.stage' => ['required', 'integer', 'between:2,4'],
            'records.*.boss' => ['required', 'array', 'min:1', 'max:5'],
            'records.*.boss.*' => ['required', 'integer', 'between:1,5'],
            'records.*.difficulty' => ['required', 'integer', 'between:1,5'],
            'records.*.damage' => ['nullable', 'integer', 'between:1,99999'],
            'records.*.roles' => ['required', 'array', 'min:1', 'max:5'],
            'records.*.roles.*' => ['required', 'integer', 'exists:game_roles,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
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
            },
            function (Validator $validator): void {
                $records = $this->input('records', []);
                if (!is_array($records)) {
                    return;
                }

                foreach ($records as $index => $record) {
                    if (!is_array($record)) {
                        continue;
                    }
                    if ((int) ($record['stage'] ?? 0) === 4 && ($record['damage'] ?? null) === null) {
                        $validator->errors()->add("records.{$index}.damage", 'D阶段必须填写伤害');
                    }
                }
            },
            function (Validator $validator): void {
                $records = $this->input('records', []);
                if (!is_array($records)) {
                    return;
                }

                foreach ($records as $index => $record) {
                    if (!is_array($record) || !is_array($record['boss'] ?? null)) {
                        continue;
                    }

                    $bossIds = array_map(static fn ($boss): int => (int) $boss, $record['boss']);
                    if (count($bossIds) !== count(array_unique($bossIds))) {
                        $validator->errors()->add("records.{$index}.boss", '同一个作业中 Boss 不能重复');
                    }
                }
            },
            function (Validator $validator): void {
                $records = $this->input('records', []);
                if (!is_array($records)) {
                    return;
                }

                foreach ($records as $index => $record) {
                    if (!is_array($record) || !is_array($record['roles'] ?? null)) {
                        continue;
                    }

                    $roleIds = array_map(static fn ($role): int => (int) $role, $record['roles']);
                    if (count($roleIds) !== count(array_unique($roleIds))) {
                        $validator->errors()->add("records.{$index}.roles", '同一个队伍中不能存在重复角色');
                    }
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'author.required' => '作者不能为空',
            'author.max' => '作者长度不能超过16个长度单位',
            'link.required' => '来源链接不能为空',
            'link.url' => '来源链接格式错误',
            'records.required' => '作业数据不能为空',
            'records.min' => '请至少添加一条作业',
            'records.*.stage.required' => '阶段不能为空',
            'records.*.stage.between' => '阶段只能选择B、C或D阶段',
            'records.*.boss.required' => 'Boss不能为空',
            'records.*.boss.min' => '至少选择一个Boss',
            'records.*.boss.max' => '最多选择5个Boss',
            'records.*.difficulty.required' => '难度不能为空',
            'records.*.difficulty.between' => '难度必须是1-5',
            'records.*.damage.required' => 'D阶段必须填写伤害',
            'records.*.damage.between' => '伤害范围必须是1-99999',
            'records.*.roles.required' => '角色不能为空',
            'records.*.roles.min' => '至少需要一个角色',
            'records.*.roles.max' => '最多只能5个角色',
            'records.*.roles.*.exists' => '存在不存在的游戏角色ID',
        ];
    }
}
