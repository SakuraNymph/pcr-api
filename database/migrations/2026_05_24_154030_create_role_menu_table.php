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
        Schema::create('role_menu', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')
                ->comment('角色ID');

            $table->unsignedBigInteger('menu_id')
                ->comment('菜单ID');

            $table->primary(['role_id', 'menu_id']);

            $table->index('role_id');
            $table->index('menu_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_menu');
    }
};