<?php

namespace App\Http\Requests\ClanSchedule;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class UpdateClanScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 玩家名称统一去除首尾空白后再校验和保存。
     */
    protected function prepareForValidation(): void
    {
        $cells = $this->input('cells');
        if (is_array($cells)) {
            $this->merge([
                'cells' => array_map(function ($cell) {
                    if (
                        is_array($cell)
                        && array_key_exists('player', $cell)
                        && is_string($cell['player'])
                    ) {
                        $cell['player'] = Str::trim($cell['player']);
                    }

                    return $cell;
                }, $cells),
            ]);
        }

        $playerOrder = $this->input('player_order');
        if (is_array($playerOrder)) {
            $this->merge([
                'player_order' => array_map(
                    static fn ($name) => is_string($name) ? Str::trim($name) : $name,
                    $playerOrder
                ),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'cells' => ['present', 'array', 'max:180'],
            'cells.*' => ['array:phase,round,boss,player,slot'],
            'cells.*.phase' => ['required', 'string', 'in:B,C,D'],
            'cells.*.round' => ['required', 'integer', 'min:1', 'max:36'],
            'cells.*.boss' => ['required', 'integer', 'between:1,5'],
            'cells.*.player' => ['required', 'string', 'max:32'],
            'cells.*.slot' => ['required', 'integer', 'between:0,5'],
            'player_order' => ['present', 'array', 'max:30'],
            'player_order.*' => ['required', 'string', 'max:32', 'distinct:strict'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $cells = $this->input('cells');
                if (! is_array($cells)) {
                    return;
                }

                $positions = [];
                $playerCounts = [];
                $playerSlots = [];
                $playerPhases = [];

                foreach ($cells as $index => $cell) {
                    if (! is_array($cell)) {
                        continue;
                    }

                    $phase = $cell['phase'] ?? null;
                    $round = $cell['round'] ?? null;
                    $boss = $cell['boss'] ?? null;
                    $player = $cell['player'] ?? null;
                    $slot = $cell['slot'] ?? null;

                    if (! is_string($phase) || ! in_array($phase, ['B', 'C', 'D'], true)) {
                        continue;
                    }

                    if (! is_int($round) && ! (is_string($round) && ctype_digit($round))) {
                        continue;
                    }
                    $round = (int) $round;

                    if (! is_int($boss) && ! (is_string($boss) && ctype_digit($boss))) {
                        continue;
                    }
                    $boss = (int) $boss;

                    if (! is_string($player) || $player === '') {
                        continue;
                    }

                    if (! is_int($slot) && ! (is_string($slot) && ctype_digit($slot))) {
                        continue;
                    }
                    $slot = (int) $slot;

                    if ($phase === 'B' && ($round < 1 || $round > 6)) {
                        $validator->errors()->add(
                            "cells.{$index}.round",
                            'B阶段周目必须在1到6之间'
                        );
                    } elseif ($phase === 'C' && ($round < 7 || $round > 22)) {
                        $validator->errors()->add(
                            "cells.{$index}.round",
                            'C阶段周目必须在7到22之间'
                        );
                    } elseif ($phase === 'D' && ($round < 23 || $round > 36)) {
                        $validator->errors()->add(
                            "cells.{$index}.round",
                            'D阶段周目必须在23到36之间'
                        );
                    }

                    if (preg_match('/[\x00-\x1F\x7F]/u', $player) === 1) {
                        $validator->errors()->add(
                            "cells.{$index}.player",
                            '玩家名称不能包含换行或控制字符'
                        );
                    }

                    $positionKey = "{$phase}:{$round}:{$boss}";
                    if (isset($positions[$positionKey])) {
                        $validator->errors()->add(
                            "cells.{$index}.boss",
                            '同一阶段、周目和Boss只能填写一名玩家'
                        );
                    } else {
                        $positions[$positionKey] = true;
                    }

                    $playerCounts[$player] = ($playerCounts[$player] ?? 0) + 1;

                    $slotKey = $player . "\0" . $slot;
                    if (isset($playerSlots[$slotKey])) {
                        $validator->errors()->add(
                            "cells.{$index}.slot",
                            '同一玩家的出刀槽位不能重复'
                        );
                    } else {
                        $playerSlots[$slotKey] = true;
                    }

                    $playerPhases[$player][$slot] = $phase;
                }

                foreach ($playerCounts as $player => $count) {
                    if ($count > 6) {
                        $validator->errors()->add(
                            'cells',
                            "玩家“{$player}”最多只能出现6次"
                        );
                    }
                }

                foreach ($playerPhases as $player => $slotsBySlot) {
                    $dCount = count(array_filter(
                        $slotsBySlot,
                        static fn ($phase) => $phase === 'D'
                    ));

                    if ($dCount > 3) {
                        $validator->errors()->add(
                            'cells',
                            "玩家“{$player}”在D阶段最多只能出现3次"
                        );
                    }

                    $returnLabels = [
                        3 => '第一刀',
                        4 => '第二刀',
                        5 => '第三刀',
                    ];

                    foreach ($returnLabels as $returnSlot => $returnLabel) {
                        if (! array_key_exists($returnSlot, $slotsBySlot)) {
                            continue;
                        }

                        $basePhase = $slotsBySlot[$returnSlot - 3] ?? null;
                        if ($basePhase !== 'B' && $basePhase !== 'C') {
                            $validator->errors()->add(
                                'cells',
                                "玩家“{$player}”的{$returnLabel}返还只有在对应基础刀为B或C阶段时才能填写"
                            );
                        }
                    }
                }

                if (count($playerCounts) > 30) {
                    $validator->errors()->add('cells', '最多只能添加30名不同玩家');
                }

                $playerOrder = $this->input('player_order');
                if (is_array($playerOrder)) {
                    foreach ($playerOrder as $index => $name) {
                        if (
                            is_string($name)
                            && preg_match('/[\x00-\x1F\x7F]/u', $name) === 1
                        ) {
                            $validator->errors()->add(
                                "player_order.{$index}",
                                '玩家名称不能包含换行或控制字符'
                            );
                        }
                    }
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'cells.present' => '排刀数据不能为空',
            'cells.array' => '排刀数据格式错误',
            'cells.max' => '排刀数据不能超过180个格子',
            'cells.*.array' => '每个格子必须包含阶段、周目、Boss、玩家和槽位',
            'cells.*.phase.required' => '阶段不能为空',
            'cells.*.phase.in' => '阶段只能是B、C或D',
            'cells.*.round.required' => '周目不能为空',
            'cells.*.round.integer' => '周目必须是整数',
            'cells.*.round.min' => '周目必须大于0',
            'cells.*.round.max' => '周目不能超过36',
            'cells.*.boss.required' => 'Boss不能为空',
            'cells.*.boss.integer' => 'Boss必须是整数',
            'cells.*.boss.between' => 'Boss必须是1到5',
            'cells.*.player.required' => '玩家名称不能为空',
            'cells.*.player.string' => '玩家名称格式错误',
            'cells.*.player.max' => '玩家名称最多32个字符',
            'cells.*.slot.required' => '出刀槽位不能为空',
            'cells.*.slot.integer' => '出刀槽位必须是整数',
            'cells.*.slot.between' => '出刀槽位必须是0到5',
            'player_order.present' => '玩家顺序不能为空',
            'player_order.array' => '玩家顺序格式错误',
            'player_order.max' => '玩家顺序不能超过30名玩家',
            'player_order.*.required' => '玩家顺序中不能有空名称',
            'player_order.*.string' => '玩家顺序格式错误',
            'player_order.*.max' => '玩家名称最多32个字符',
            'player_order.*.distinct' => '玩家顺序中不能出现重复名称',
        ];
    }
}
