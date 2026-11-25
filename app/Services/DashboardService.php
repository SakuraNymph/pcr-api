<?php

namespace App\Services;

use App\Models\DailyUserVisit;
use App\Models\User;
use Carbon\Carbon;

class DashboardService
{
    /*
     * 已知统计偏差（产品已确认接受）：
     * - users.ip 会在注册与登录时被覆盖，因此“新增用户”按 IP 去重的历史数字
     *   可能随用户更换 IP 而轻微漂移。
     * - 新增用户按 IP 去重、新增注册按账号统计，同一 IP 多账号注册时
     *   可能出现“新增注册 > 新增用户”。
     */

    /**
     * 获取 Dashboard 全部数据
     */
    public function analytics(): array
    {
        $today     = $this->getDayStats(today());
        $yesterday = $this->getDayStats(today()->subDay());
        $thisMonth = $this->getRangeStats(now()->startOfMonth(), now());
        $lastMonth = $this->getRangeStats(now()->subMonth()->startOfMonth(), now()->subMonth());

        return [
            $this->makeSmallArray('今日访问量', $today['visits'], $yesterday['visits']),
            $this->makeSmallArray('今日新增用户量', $today['new_users'], $yesterday['new_users']),
            $this->makeSmallArray('今日新增注册量', $today['new_registers'], $yesterday['new_registers']),
            $this->makeSmallArray('本月访问量', $thisMonth['visits'], $lastMonth['visits']),
            $this->makeSmallArray('本月新增用户量', $thisMonth['new_users'], $lastMonth['new_users']),
            $this->makeSmallArray('本月新增注册量', $thisMonth['new_registers'], $lastMonth['new_registers'])
        ];
    }

    public function trend(): array
    {
        return $this->getTrend(12);
    }

    private function makeSmallArray($title, $value, $subValue)
    {
        return [
            'title'    => $title,
            'value'    => $value,
            'subValue' => $subValue,
            'subRatio' => $this->percentChange($value, $subValue)
        ];
    }


    /**
     * [percentChange 获取增长率]
     * @param  [type]  $a         [description]
     * @param  [type]  $b         [description]
     * @param  integer $precision [description]
     * @return [type]             [description]
     */
    private function percentChange($a, $b, $precision = 0)
    {
        if ($b == 0) {

            if ($a == 0) {
                return '0%';
            }

            return '+∞';

        }

        $percent = (($a - $b) / $b) * 100;

        return ($percent >= 0 ? '+' : '')
            . round($percent, $precision)
            . '%';
    }



    /**
     * 统计某一天的数据
     *
     * 口径说明：
     * - 访问用户：daily_user_visits 每用户每天唯一，天然按人去重。
     * - 新增用户：按 users.ip 去重，同一 IP 当天只计 1 个新增。
     * - 新增注册：按 registered_at 统计真正的注册时间，不按首次访问时间。
     *
     * @param Carbon $date
     */
    private function getDayStats(Carbon $date): array
    {
        return [

            // 当日访问用户数
            'visits' => DailyUserVisit::query()
                ->whereDate(
                    'visit_date',
                    $date
                )
                ->count(),


            // 当日新增用户（按 IP 去重）
            'new_users' => User::query()
                ->whereDate(
                    'created_at',
                    $date
                )
                ->distinct()
                ->count('ip'),


            // 当日新增注册用户
            'new_registers' => User::query()
                ->whereNotNull('registered_at')
                ->whereDate(
                    'registered_at',
                    $date
                )
                ->count(),
        ];
    }



    /**
     * 统计时间范围数据
     *
     * 例如：
     * 本月
     * 上月同期
     */
    private function getRangeStats(
        Carbon $start,
        Carbon $end
    ): array {

        return [

            // 访问量
            'visits' => DailyUserVisit::query()
                ->whereBetween(
                    'visit_date',
                    [
                        $start->format('Y-m-d'),
                        $end->format('Y-m-d')
                    ]
                )
                ->count(),


            // 新增用户（按 IP 去重）
            'new_users' => User::query()
                ->whereBetween(
                    'created_at',
                    [
                        $start,
                        $end
                    ]
                )
                ->distinct()
                ->count('ip'),


            // 新增注册用户
            'new_registers' => User::query()
                ->whereNotNull('registered_at')
                ->whereBetween(
                    'registered_at',
                    [
                        $start,
                        $end
                    ]
                )
                ->count(),
        ];
    }



    /**
     * 最近N个月趋势
     *
     * 返回给 ECharts 折线图
     */
    private function getTrend(
        int $months = 12
    ): array {

        $result = [];


        for ($i = $months - 1; $i >= 0; $i--) {


            $month = now()->subMonths($i);


            $start = $month
                ->copy()
                ->startOfMonth();


            $end = $month
                ->copy()
                ->endOfMonth();



            $result[] = [

                'month' => $month->format('Y-m'),


                // 月访问用户
                'visits' => DailyUserVisit::query()
                    ->whereBetween(
                        'visit_date',
                        [
                            $start->format('Y-m-d'),
                            $end->format('Y-m-d')
                        ]
                    )
                    ->count(),


                // 月新增用户（按 IP 去重）
                'new_users' => User::query()
                    ->whereBetween(
                        'created_at',
                        [
                            $start,
                            $end
                        ]
                    )
                    ->distinct()
                    ->count('ip'),


                // 月新增注册用户
                'new_registers' => User::query()
                    ->whereNotNull('registered_at')
                    ->whereBetween(
                        'registered_at',
                        [
                            $start,
                            $end
                        ]
                    )
                    ->count(),

            ];
        }


        return $result;
    }
}
