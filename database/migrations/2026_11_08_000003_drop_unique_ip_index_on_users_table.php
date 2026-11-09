<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * IP 不再参与身份识别，移除唯一索引，ip 仅作审计字段保留。
     */
    public function up(): void
    {
        if (! Schema::hasIndex('users', 'users_ip_unique')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_ip_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('users', ['ip'], 'unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unique('ip', 'users_ip_unique');
            });
        }
    }
};
