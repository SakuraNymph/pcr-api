<?php

namespace Tests\Unit;

use App\Services\CardLuckEvaluationService;
use PHPUnit\Framework\TestCase;

class CardLuckEvaluationServiceTest extends TestCase
{
    private CardLuckEvaluationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CardLuckEvaluationService();
    }

    public function test_probability_one_uses_the_expected_integer_intervals(): void
    {
        $cases = [
            1 => ['天选之子', 1],
            3 => ['天选之子', 1],
            4 => ['欧皇', 2],
            12 => ['欧皇', 2],
            13 => ['运气不错', 3],
            30 => ['运气不错', 3],
            31 => ['普普通通', 4],
            119 => ['普普通通', 4],
            120 => ['运气欠佳', 5],
            162 => ['运气欠佳', 5],
            163 => ['非酋', 6],
            191 => ['非酋', 6],
            192 => ['非酋中的非酋', 7],
            199 => ['非酋中的非酋', 7],
        ];

        foreach ($cases as $count => [$title, $level]) {
            $this->assertSame(
                ['title' => $title, 'level' => $level],
                $this->service->evaluate($count, 1)
            );
        }
    }

    public function test_probability_two_uses_the_expected_integer_intervals(): void
    {
        $cases = [
            1 => ['天选之子', 1],
            2 => ['天选之子', 1],
            3 => ['欧皇', 2],
            7 => ['欧皇', 2],
            8 => ['运气不错', 3],
            19 => ['运气不错', 3],
            20 => ['普普通通', 4],
            87 => ['普普通通', 4],
            88 => ['运气欠佳', 5],
            133 => ['运气欠佳', 5],
            134 => ['非酋', 6],
            180 => ['非酋', 6],
            181 => ['非酋中的非酋', 7],
            199 => ['非酋中的非酋', 7],
        ];

        foreach ($cases as $count => [$title, $level]) {
            $this->assertSame(
                ['title' => $title, 'level' => $level],
                $this->service->evaluate($count, 2)
            );
        }
    }

    public function test_zero_and_two_hundred_are_special_evaluations(): void
    {
        $this->assertSame(
            ['title' => '暂无评价', 'level' => 0],
            $this->service->evaluate(0, 1)
        );
        $this->assertSame(
            ['title' => '保底之王', 'level' => 8],
            $this->service->evaluate(200, 2)
        );
    }
}
