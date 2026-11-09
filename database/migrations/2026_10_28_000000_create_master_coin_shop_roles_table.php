<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_coin_shop_roles', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->unsignedBigInteger('game_role_id')
                ->comment('game_roles.id');
            $table->unsignedTinyInteger('type')
                ->comment('分组类型：1=FES，2=非FES');
            $table->unsignedInteger('sort_order')
                ->comment('分组内排序值，越大越靠前');
            $table->timestamps();

            $table->unique('game_role_id');
            $table->unique(['type', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_coin_shop_roles');
    }
};
