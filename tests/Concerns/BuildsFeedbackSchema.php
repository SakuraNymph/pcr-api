<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait BuildsFeedbackSchema
{
    protected function createFeedbackSchema(): void
    {
        foreach ([
            'user_feedback_images',
            'user_feedback_messages',
            'user_feedback',
            'personal_access_tokens',
            'role_menu',
            'menus',
            'role_permission',
            'permissions',
            'admin_role',
            'admins',
            'roles',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('public_uid')->nullable()->unique();
            $table->string('ip', 45)->default('');
            $table->string('country')->nullable();
            $table->string('province')->nullable();
            $table->string('city')->nullable();
            $table->string('nickname')->nullable();
            $table->unsignedBigInteger('avatar')->nullable();
            $table->timestamp('nickname_updated_at')->nullable();
            $table->tinyInteger('status')->default(0);
            $table->tinyInteger('is_subscribe')->default(1);
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('description')->default('');
            $table->integer('sort')->default(0);
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('admins', function (Blueprint $table): void {
            $table->id();
            $table->string('username')->unique();
            $table->string('password');
            $table->string('nickname')->default('');
            $table->string('avatar')->default('');
            $table->string('email')->default('');
            $table->string('mobile')->default('');
            $table->tinyInteger('status')->default(1);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip')->default('');
            $table->timestamps();
        });

        Schema::create('admin_role', function (Blueprint $table): void {
            $table->unsignedBigInteger('admin_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['admin_id', 'role_id']);
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('display_name');
            $table->unsignedBigInteger('menu_id')->nullable();
            $table->string('http_method')->default('GET');
            $table->string('http_path');
            $table->timestamps();
        });

        Schema::create('role_permission', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('menus', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->default(0);
            $table->tinyInteger('type')->default(1);
            $table->string('name', 100)->default('');
            $table->string('path', 255)->default('');
            $table->string('component', 255)->default('');
            $table->string('redirect', 255)->default('');
            $table->string('title', 100)->default('');
            $table->string('icon', 100)->default('');
            $table->string('permission', 255)->default('');
            $table->integer('sort')->default(0);
            $table->tinyInteger('hidden')->default(0);
            $table->tinyInteger('keep_alive')->default(1);
            $table->tinyInteger('affix_tab')->default(0);
            $table->string('iframe_src', 500)->default('');
            $table->string('link', 500)->default('');
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('role_menu', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('menu_id');
            $table->primary(['role_id', 'menu_id']);
        });

        Schema::create('user_feedback', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->unsignedInteger('public_uid');
            $table->string('nickname', 255)->nullable();
            $table->unsignedTinyInteger('category');
            $table->string('title', 30);
            $table->unsignedTinyInteger('status')->default(0);
            $table->string('client_type', 20)->nullable();
            $table->string('client_version', 50)->nullable();
            $table->timestamp('last_message_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('user_feedback_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('feedback_id');
            $table->unsignedTinyInteger('sender_type');
            $table->unsignedBigInteger('sender_id');
            $table->text('content')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('user_feedback_images', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('message_id');
            $table->string('path', 191)->unique();
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('file_size');
            $table->timestamp('created_at')->useCurrent();
        });
    }
}
