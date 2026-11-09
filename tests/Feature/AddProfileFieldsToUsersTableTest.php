<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AddProfileFieldsToUsersTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->tinyInteger('status')->nullable();
            $table->timestamps();
        });
    }

    public function test_migration_adds_fields_and_backfills_only_formal_users(): void
    {
        $guestId = DB::table('users')->insertGetId([
            'status' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $userId = DB::table('users')->insertGetId([
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path(
            'migrations/2026_11_04_020000_add_profile_fields_to_users_table.php'
        );
        $migration->up();

        $this->assertTrue(Schema::hasColumn('users', 'avatar'));
        $this->assertTrue(Schema::hasColumn('users', 'nickname_updated_at'));

        $guest = DB::table('users')->find($guestId);
        $user = DB::table('users')->find($userId);

        $this->assertNull($guest->avatar);
        $this->assertNull($guest->nickname_updated_at);
        $this->assertSame(105811, (int) $user->avatar);
        $this->assertNull($user->nickname_updated_at);

        $migration->down();

        $this->assertFalse(Schema::hasColumn('users', 'avatar'));
        $this->assertFalse(Schema::hasColumn('users', 'nickname_updated_at'));
    }
}
