<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DailyUserVisitRecordTest extends TestCase
{
    private User $user;

    private User $guest;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00'));
        Cache::flush();

        Schema::dropIfExists('daily_user_visits');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('ip', 45);
            $table->string('nickname')->nullable();
            $table->tinyInteger('status')->default(0);
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

        $this->user = User::create([
            'ip' => '127.0.0.1',
            'nickname' => '正式用户',
            'email' => 'member@example.com',
            'password' => 'secret',
            'status' => 1,
        ]);

        $this->guest = User::create([
            'ip' => '127.0.0.2',
            'nickname' => '游客',
            'status' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_registered_user_is_recorded_once_per_day(): void
    {
        Route::middleware(['user.auth', 'user.identity:registered', 'user.visit'])
            ->get('/api/test/daily-visit/registered', fn () => response()->json(['status' => 0]));

        Sanctum::actingAs($this->user);

        $this->getJson('/api/test/daily-visit/registered')->assertOk();
        $this->getJson('/api/test/daily-visit/registered')->assertOk();

        $this->assertSame(
            1,
            DB::table('daily_user_visits')->where('user_id', $this->user->id)->count()
        );
        $this->assertSame(
            '2026-11-15',
            DB::table('daily_user_visits')->where('user_id', $this->user->id)->value('visit_date')
        );
    }

    public function test_guest_visit_is_not_recorded(): void
    {
        Route::middleware(['user.auth', 'user.identity:any', 'user.visit'])
            ->get('/api/test/daily-visit/any', fn () => response()->json(['status' => 0]));

        Sanctum::actingAs($this->guest);

        $this->getJson('/api/test/daily-visit/any')->assertOk();

        $this->assertSame(0, DB::table('daily_user_visits')->count());
    }

    public function test_a_new_day_creates_a_second_record(): void
    {
        Route::middleware(['user.auth', 'user.identity:registered', 'user.visit'])
            ->get('/api/test/daily-visit/next-day', fn () => response()->json(['status' => 0]));

        Sanctum::actingAs($this->user);

        $this->getJson('/api/test/daily-visit/next-day')->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-11-16 09:00:00'));

        $this->getJson('/api/test/daily-visit/next-day')->assertOk();

        $this->assertSame(
            2,
            DB::table('daily_user_visits')->where('user_id', $this->user->id)->count()
        );
    }
}
