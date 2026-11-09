<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('avatar')->nullable();
            $table->timestamp('nickname_updated_at')->nullable();
        });

        DB::table('users')
            ->where('status', 1)
            ->update(['avatar' => 105811]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['avatar', 'nickname_updated_at']);
        });
    }
};
