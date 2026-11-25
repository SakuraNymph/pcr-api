<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AddRegisteredAtToUsersTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->tinyInteger('status')->default(0);
            $table->timestamps();
        });
    }

    public function test_migration_adds_registered_at_and_backfills_only_formal_users(): void
    {
        $guestCreatedAt = '2026-10-01 08:30:00';
        $userCreatedAt = '2026-11-02 09:15:00';

        $guestId = DB::table('users')->insertGetId([
            'status' => 0,
            'created_at' => $guestCreatedAt,
            'updated_at' => $guestCreatedAt,
        ]);
        $userId = DB::table('users')->insertGetId([
            'status' => 1,
            'created_at' => $userCreatedAt,
            'updated_at' => $userCreatedAt,
        ]);

        $migration = require database_path(
            'migrations/2026_11_25_000000_add_registered_at_to_users_table.php'
        );
        $migration->up();

        $this->assertTrue(Schema::hasColumn('users', 'registered_at'));

        $guest = DB::table('users')->find($guestId);
        $user = DB::table('users')->find($userId);

        // 游客不参与注册统计，保持为空。
        $this->assertNull($guest->registered_at);

        // 历史正式用户用首次创建时间近似回填。
        $this->assertSame(
            $userCreatedAt,
            (string) $user->registered_at
        );

        $migration->down();

        $this->assertFalse(Schema::hasColumn('users', 'registered_at'));
    }
}
