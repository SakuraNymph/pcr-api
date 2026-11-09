<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            // 1️⃣ username - 必须唯一可选，长度 255
            $table->string('username')->after('id');

            // 2️⃣ email - 必须唯一，长度 255
            $table->string('email')->unique()->after('username');

            // 3️⃣ password - 存储 bcrypt 哈希值
            $table->string('password')->after('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn(['username', 'email', 'password']);
        });
    }
};