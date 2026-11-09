<?php

namespace App\Services;

use InvalidArgumentException;

class CardLuckEvaluationService
{
    private const TITLES = [
        '天选之子',
        '欧皇',
        '运气不错',
        '普普通通',
        '运气欠佳',
        '非酋',
        '非酋中的非酋',
    ];

    /**
     * 每项为对应称号的包含式上界。
     */
    private const BOUNDARIES = [
        1 => [3, 12, 30, 119, 162, 191],
        2 => [2, 7, 19, 87, 133, 180],
    ];

    /**
     * @return array{title: string, level: int}|null
     */
    public function evaluate(int $count, int $probability): ?array
    {
        if ($count < 0 || $count > 200) {
            throw new InvalidArgumentException('抽数必须在 0 到 200 之间');
        }

        if ($count === 0) {
            return [
                'title' => '暂无评价',
                'level' => 0,
            ];
        }

        if ($count === 200) {
            return [
                'title' => '保底之王',
                'level' => 8,
            ];
        }

        $boundaries = self::BOUNDARIES[$probability] ?? null;

        if ($boundaries === null) {
            return null;
        }

        foreach ($boundaries as $index => $upperBound) {
            if ($count <= $upperBound) {
                return [
                    'title' => self::TITLES[$index],
                    'level' => $index + 1,
                ];
            }
        }

        return [
            'title' => self::TITLES[6],
            'level' => 7,
        ];
    }
}
