<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BossApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00'));
        $this->createTestTables();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_current_period_returns_five_placeholder_seats_when_table_is_empty(): void
    {
        $response = $this->getJson('/api/game/bosses?period=current');

        $response->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonCount(5, 'data');

        $data = $response->json('data');

        $this->assertSame([1, 2, 3, 4, 5], array_column($data, 'sort'));
        $this->assertSame('fallback-boss-1', $data[0]['id']);
        $this->assertSame('BOSS 1', $data[0]['name']);
        $this->assertSame('', $data[0]['file_path']);
        $this->assertSame('202611', $data[0]['year_month']);
    }

    public function test_current_period_uses_selected_current_bosses_and_images(): void
    {
        foreach (range(1, 5) as $sort) {
            DB::table('bosses')->insert([
                'id' => 100 + $sort,
                'name' => "真实 BOSS {$sort}",
                'file_path' => "boss-{$sort}.webp",
                'year_month' => '202611',
                'status' => 1,
                'sort' => $sort,
            ]);
        }

        $response = $this->getJson('/api/game/bosses?period=current');

        $response->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.id', 101)
            ->assertJsonPath(
                'data.0.file_path',
                rtrim((string) config('app.url'), '/').'/images/bosses/boss-1.webp'
            );

        $this->assertSame([1, 2, 3, 4, 5], array_column($response->json('data'), 'sort'));
    }

    public function test_current_period_fills_missing_seats_from_previous_month(): void
    {
        DB::table('bosses')->insert([
            [
                'id' => 101,
                'name' => '本月一王',
                'file_path' => 'current-one.webp',
                'year_month' => '202611',
                'status' => 1,
                'sort' => 1,
            ],
            [
                'id' => 201,
                'name' => '上月一王',
                'file_path' => 'previous-one.webp',
                'year_month' => '202610',
                'status' => 1,
                'sort' => 1,
            ],
            [
                'id' => 202,
                'name' => '上月二王',
                'file_path' => 'previous-two.webp',
                'year_month' => '202610',
                'status' => 1,
                'sort' => 2,
            ],
        ]);

        $response = $this->getJson('/api/game/bosses?period=current');
        $data = $response->json('data');

        $response->assertOk()->assertJsonCount(5, 'data');
        $this->assertSame(101, $data[0]['id']);
        $this->assertSame(202, $data[1]['id']);
        $this->assertSame('202611', $data[1]['year_month']);
        $this->assertSame('fallback-boss-3', $data[2]['id']);
    }

    public function test_invalid_duplicate_unselected_and_missing_seats_are_normalized(): void
    {
        DB::table('bosses')->insert([
            [
                'id' => 201,
                'name' => '  ',
                'file_path' => '',
                'year_month' => '202610',
                'status' => 1,
                'sort' => 1,
            ],
            [
                'id' => 202,
                'name' => '重复一王',
                'file_path' => 'duplicate.webp',
                'year_month' => '202610',
                'status' => 1,
                'sort' => 1,
            ],
            [
                'id' => 203,
                'name' => '本月未选中',
                'file_path' => 'unselected.webp',
                'year_month' => '202611',
                'status' => 0,
                'sort' => 2,
            ],
            [
                'id' => 204,
                'name' => '真实三王',
                'file_path' => 'boss-three.webp',
                'year_month' => '202610',
                'status' => 1,
                'sort' => 3,
            ],
            [
                'id' => 205,
                'name' => '越界 BOSS',
                'file_path' => 'out-of-range.webp',
                'year_month' => '202610',
                'status' => 1,
                'sort' => 6,
            ],
            [
                'id' => 206,
                'name' => '空排序 BOSS',
                'file_path' => 'missing-sort.webp',
                'year_month' => '202610',
                'status' => 1,
                'sort' => null,
            ],
        ]);

        $response = $this->getJson('/api/game/bosses?period=current');
        $data = $response->json('data');

        $response->assertOk()->assertJsonCount(5, 'data');
        $this->assertSame([1, 2, 3, 4, 5], array_column($data, 'sort'));
        $this->assertSame(201, $data[0]['id']);
        $this->assertSame('BOSS 1', $data[0]['name']);
        $this->assertSame('', $data[0]['file_path']);
        $this->assertSame('fallback-boss-2', $data[1]['id']);
        $this->assertSame(204, $data[2]['id']);
        $this->assertSame('fallback-boss-4', $data[3]['id']);
        $this->assertSame('fallback-boss-5', $data[4]['id']);
    }

    public function test_previous_period_only_uses_actual_previous_month_bosses(): void
    {
        DB::table('bosses')->insert([
            [
                'id' => 301,
                'name' => '上月一王',
                'file_path' => 'previous-one.webp',
                'year_month' => '202610',
                'status' => 1,
                'sort' => 1,
            ],
            [
                'id' => 302,
                'name' => '本月二王',
                'file_path' => 'current-two.webp',
                'year_month' => '202611',
                'status' => 1,
                'sort' => 2,
            ],
        ]);

        $response = $this->getJson('/api/game/bosses?period=previous');
        $data = $response->json('data');

        $response->assertOk()->assertJsonCount(5, 'data');
        $this->assertSame(301, $data[0]['id']);
        $this->assertSame('202610', $data[0]['year_month']);
        $this->assertSame('fallback-boss-2', $data[1]['id']);
    }

    public function test_invalid_period_still_fails_validation(): void
    {
        $this->getJson('/api/game/bosses?period=unknown')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['period']);
    }

    private function createTestTables(): void
    {
        Schema::create('bosses', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('file_path');
            $table->char('year_month', 6)->nullable();
            $table->tinyInteger('status')->default(0);
            $table->unsignedTinyInteger('sort')->nullable();
        });
    }
}
