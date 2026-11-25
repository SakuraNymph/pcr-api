<?php

namespace Tests\Feature;

use App\Models\DailyUserVisit;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DashboardAnalyticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-25 10:00:00'));

        Schema::dropIfExists('daily_user_visits');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('public_uid')->nullable()->unique();
            $table->string('ip', 45);
            $table->string('nickname')->nullable();
            $table->tinyInteger('status')->default(0);
            $table->timestamp('registered_at')->nullable();
            $table->tinyInteger('is_subscribe')->default(1);
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('daily_user_visits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->date('visit_date');
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'visit_date']);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_new_users_are_deduped_by_ip(): void
    {
        $this->createUser('1.1.1.1', '2026-11-25 08:00:00');
        $this->createUser('1.1.1.1', '2026-11-25 09:00:00');
        $this->createUser('2.2.2.2', '2026-11-25 09:30:00');

        $today = $this->analytics()['今日新增用户量'];

        $this->assertSame(2, $today['value']);
    }

    public function test_new_registers_use_registered_at_instead_of_created_at(): void
    {
        // 昨天首次访问，今天才注册。
        $this->createUser(
            '3.3.3.3',
            '2026-11-24 08:00:00',
            '2026-11-25 09:00:00'
        );

        // 今天首次访问但尚未注册。
        $this->createUser('4.4.4.4', '2026-11-25 08:00:00');

        $analytics = $this->analytics();

        $this->assertSame(1, $analytics['今日新增注册量']['value']);
        $this->assertSame(0, $analytics['今日新增注册量']['subValue']);
        // 今天只有 4.4.4.4 是新建用户；3.3.3.3 是昨天创建、今天注册。
        $this->assertSame(1, $analytics['今日新增用户量']['value']);
        // 昨天创建了 1 个用户（3.3.3.3）。
        $this->assertSame(1, $analytics['今日新增用户量']['subValue']);
    }

    public function test_visits_count_guests_and_registered_users_once_per_day(): void
    {
        $guest = $this->createUser('5.5.5.5', '2026-11-25 07:00:00');
        $member = $this->createUser(
            '6.6.6.6',
            '2026-11-25 07:10:00',
            '2026-11-25 07:20:00'
        );

        foreach ([$guest, $member, $member] as $user) {
            DailyUserVisit::query()->insertOrIgnore([
                'user_id' => $user->id,
                'visit_date' => '2026-11-25',
                'ip' => $user->ip,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(2, $this->analytics()['今日访问量']['value']);
    }

    public function test_monthly_and_trend_statistics_follow_the_same_rules(): void
    {
        $this->createUser('7.7.7.7', '2026-11-25 08:00:00');
        $this->createUser('7.7.7.7', '2026-11-25 09:00:00');
        $this->createUser(
            '8.8.8.8',
            '2026-11-10 08:00:00',
            '2026-11-25 09:00:00'
        );
        $this->createUser('9.9.9.9', '2026-11-11 08:00:00');

        $analytics = $this->analytics();

        $this->assertSame(3, $analytics['本月新增用户量']['value']);
        $this->assertSame(1, $analytics['本月新增注册量']['value']);

        $currentMonth = collect(app(DashboardService::class)->trend())
            ->firstWhere('month', '2026-11');

        $this->assertSame(3, $currentMonth['new_users']);
        $this->assertSame(1, $currentMonth['new_registers']);
    }

    private function analytics(): array
    {
        return collect(app(DashboardService::class)->analytics())
            ->keyBy('title')
            ->all();
    }

    private function createUser(
        string $ip,
        string $createdAt,
        ?string $registeredAt = null
    ): User {
        $id = DB::table('users')->insertGetId([
            'ip' => $ip,
            'status' => $registeredAt === null ? 0 : 1,
            'registered_at' => $registeredAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return User::query()->findOrFail($id);
    }
}
