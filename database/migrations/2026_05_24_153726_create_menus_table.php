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
        Schema::create('menus', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('parent_id')
                ->default(0)
                ->comment('父级菜单ID');

            $table->tinyInteger('type')
                ->default(1)
                ->comment('1目录 2菜单 3按钮');

            $table->string('name', 100)
                ->default('')
                ->comment('路由名称');

            $table->string('path', 255)
                ->default('')
                ->comment('路由路径');

            $table->string('component', 255)
                ->default('')
                ->comment('组件路径');

            $table->string('redirect', 255)
                ->default('')
                ->comment('重定向');

            $table->string('title', 100)
                ->default('')
                ->comment('菜单标题');

            $table->string('icon', 100)
                ->default('')
                ->comment('菜单图标');

            $table->string('permission', 255)
                ->default('')
                ->comment('权限标识');

            $table->integer('sort')
                ->default(0)
                ->comment('排序');

            $table->tinyInteger('hidden')
                ->default(0)
                ->comment('是否隐藏 0否 1是');

            $table->tinyInteger('keep_alive')
                ->default(1)
                ->comment('是否缓存 0否 1是');

            $table->tinyInteger('affix_tab')
                ->default(0)
                ->comment('是否固定标签');

            $table->string('iframe_src', 500)
                ->default('')
                ->comment('iframe地址');

            $table->string('link', 500)
                ->default('')
                ->comment('外链地址');

            $table->tinyInteger('status')
                ->default(1)
                ->comment('状态 0禁用 1启用');

            $table->timestamps();

            $table->index('parent_id');
            $table->index('type');
            $table->index('sort');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};