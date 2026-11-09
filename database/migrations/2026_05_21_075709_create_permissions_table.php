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
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();          // 业务唯一标识，如 system:user:list
            $table->string('display_name')->comment('中文描述');
            $table->foreignId('menu_id')->nullable()->constrained('menus')->onDelete('set null');
            $table->string('http_method')->default('GET');
            $table->string('http_path');              // 完整路径，如 /api/admin/users
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
