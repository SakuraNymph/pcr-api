<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Older databases created this column as varchar(255), too wide for a
        // unique index on older MySQL configurations.
        Schema::table('users', function (Blueprint $table) {
            $table->string('ip', 45)->change();
        });

        // The create_users_table migration already adds this index on fresh installs.
        if (! Schema::hasIndex('users', ['ip'], 'unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unique('ip', 'users_ip_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('users', 'users_ip_unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique('users_ip_unique');
            });
        }
    }
};
