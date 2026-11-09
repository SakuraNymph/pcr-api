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
        Schema::create('roles', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100)
                ->comment('角色名称');

            $table->string('code', 100)
                ->unique()
                ->comment('角色标识');

            $table->string('description', 255)
                ->default('')
                ->comment('角色描述');

            $table->integer('sort')
                ->default(0)
                ->comment('排序');

            $table->tinyInteger('status')
                ->default(1)
                ->comment('状态 0禁用 1启用');

            $table->timestamps();

            $table->index('sort');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};